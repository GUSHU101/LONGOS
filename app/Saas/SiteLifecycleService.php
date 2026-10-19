<?php

namespace App\Saas;

use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Saas\Support\DomainName;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SiteLifecycleService
{
    public function __construct(
        private readonly DomainConfigWriter $domainConfig,
        private readonly SiteHealthChecker $health,
        private readonly SiteAuditLogger $audit,
    ) {}

    public function setStatus(Site $site, string $status, bool $force = false): Site
    {
        return $this->domainConfig->synchronized(
            fn (): Site => $this->setStatusSynchronized($site, $status, $force)
        );
    }

    private function setStatusSynchronized(Site $site, string $status, bool $force): Site
    {
        if (! in_array($status, [Site::STATUS_ACTIVE, Site::STATUS_SUSPENDED], true)) {
            throw new RuntimeException("不支持的站点状态：{$status}");
        }
        if ($site->status === $status) {
            return $site;
        }
        if ($status === Site::STATUS_SUSPENDED && ! $force && (bool) data_get($site->meta, 'control_site', false)) {
            throw new RuntimeException('控制站暂停后可能无法再访问管理 API；如确需暂停，请使用 --force 并预留 CLI 恢复通道。');
        }
        if ($status === Site::STATUS_ACTIVE) {
            $activeDomains  = $site->domains()->where('status', Domain::STATUS_ACTIVE)->count();
            $primaryDomains = $site->domains()
                ->where('status', Domain::STATUS_ACTIVE)
                ->where('is_primary', true)
                ->count();
            if ($activeDomains < 1) {
                throw new RuntimeException('站点至少需要一个已启用域名才能上线。');
            }
            if ($primaryDomains !== 1) {
                throw new RuntimeException('站点必须且只能有一个已启用的主域名。');
            }
        }

        if ($status === Site::STATUS_ACTIVE && ! $force) {
            $health = $this->health->check($site->load('domains'), false);
            if (! $health['database']['ok']) {
                throw new RuntimeException('站点数据库健康检查失败，请修复后上线，或显式强制上线。');
            }
        }

        $previous         = $site->status;
        $transitionStatus = $status === Site::STATUS_ACTIVE ? Site::STATUS_ACTIVATING : $status;
        $site->forceFill(['status' => $transitionStatus])->save();
        $published = false;

        try {
            $this->domainConfig->sync();
            $published = true;

            if ($status === Site::STATUS_ACTIVE) {
                $updated = Site::query()
                    ->whereKey($site->getKey())
                    ->where('status', Site::STATUS_ACTIVATING)
                    ->update([
                        'status'     => Site::STATUS_ACTIVE,
                        'updated_at' => now(),
                    ]);
                if ($updated !== 1) {
                    throw new RuntimeException('域名配置已发布，但站点激活状态已变更。');
                }
                $site->forceFill(['status' => Site::STATUS_ACTIVE]);
            }
        } catch (\Throwable $exception) {
            $site->forceFill(['status' => $previous])->save();
            if ($published) {
                try {
                    // ACTIVATING domains were already published. Rebuild from
                    // the restored fail-closed state before returning failure.
                    $this->domainConfig->sync();
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            $this->audit->record($site, 'site.status', 'failed', $exception->getMessage(), ['requested_status' => $status]);

            throw $exception;
        }

        $this->audit->record($site, 'site.status', 'success', null, ['from' => $previous, 'to' => $status]);

        return $site->refresh()->load('domains');
    }

    public function addDomain(Site $site, string $value, bool $primary = false): Domain
    {
        return $this->domainConfig->synchronized(
            fn (): Domain => $this->addDomainSynchronized($site, $value, $primary)
        );
    }

    private function addDomainSynchronized(Site $site, string $value, bool $primary): Domain
    {
        $parsed            = DomainName::parse($value, (string) config('saas.default_scheme', 'https'));
        $this->assertPlatformManagedHost($parsed['host']);
        $previousPrimaryId = null;
        $created           = false;
        $staged            = false;
        $makePrimary       = $primary;
        $existingSnapshot  = null;

        $domain = DB::connection('saas')->transaction(function () use ($site, $parsed, $primary, &$created, &$staged, &$makePrimary, &$existingSnapshot, &$previousPrimaryId): Domain {
            $lockedSite        = Site::query()->lockForUpdate()->findOrFail($site->getKey());
            $previousPrimaryId = $lockedSite->domains()->where('is_primary', true)->value('id');
            $existing          = Domain::query()->where('host', $parsed['host'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->site_id !== $lockedSite->id) {
                    throw new RuntimeException("域名 {$parsed['host']} 已绑定到其他站点。");
                }

                $existingSnapshot = $existing->only([
                    'scheme', 'is_primary', 'routing_behavior', 'status', 'origin', 'verification_method',
                    'verification_token', 'verification_expires_at', 'verification_attempts',
                    'verified_at', 'last_checked_at', 'verification_error',
                ]);
                $makePrimary = $primary || $existing->is_primary;

                if ($existing->status !== Domain::STATUS_ACTIVE) {
                    $staged = true;
                    $existing->forceFill([
                        'scheme'                  => $parsed['scheme'],
                        'status'                  => Domain::STATUS_ACTIVATING,
                        'origin'                  => Domain::ORIGIN_PLATFORM,
                        'is_primary'              => false,
                        'routing_behavior'        => Domain::ROUTING_REDIRECT,
                        'verification_method'     => null,
                        'verification_token'      => null,
                        'verification_expires_at' => null,
                        'verification_attempts'   => 0,
                        'verification_error'      => null,
                    ])->save();

                    return $existing;
                }

                if ($primary && ! $existing->is_primary) {
                    $lockedSite->domains()->where('is_primary', true)->update([
                        'is_primary'       => false,
                        'routing_behavior' => Domain::ROUTING_REDIRECT,
                    ]);
                }
                $existing->forceFill([
                    'scheme'                  => $parsed['scheme'],
                    'status'                  => Domain::STATUS_ACTIVE,
                    'origin'                  => Domain::ORIGIN_PLATFORM,
                    'is_primary'              => $primary || $existing->is_primary,
                    'routing_behavior'        => Domain::ROUTING_REDIRECT,
                    'verification_method'     => null,
                    'verification_token'      => null,
                    'verification_expires_at' => null,
                    'verification_attempts'   => 0,
                    'verification_error'      => null,
                ])->save();

                return $existing;
            }

            $created     = true;
            $staged      = true;
            $makePrimary = $primary || ! $lockedSite->domains()->where('status', Domain::STATUS_ACTIVE)->exists();

            return $lockedSite->domains()->create([
                'host'             => $parsed['host'],
                'scheme'           => $parsed['scheme'],
                'is_primary'       => false,
                'routing_behavior' => Domain::ROUTING_REDIRECT,
                'status'           => Domain::STATUS_ACTIVATING,
                'origin'           => Domain::ORIGIN_PLATFORM,
                'verified_at'      => null,
            ]);
        });

        $published = false;

        try {
            $this->domainConfig->sync();
            $published = true;

            if ($staged) {
                DB::connection('saas')->transaction(function () use ($site, $domain, $makePrimary): void {
                    $lockedSite   = Site::query()->lockForUpdate()->findOrFail($site->getKey());
                    $lockedDomain = Domain::query()->lockForUpdate()->findOrFail($domain->getKey());
                    if ($lockedDomain->site_id !== $lockedSite->id || $lockedDomain->status !== Domain::STATUS_ACTIVATING) {
                        throw new RuntimeException('域名发布状态已变更。');
                    }
                    if ($makePrimary) {
                        $lockedSite->domains()->where('is_primary', true)->update([
                            'is_primary'       => false,
                            'routing_behavior' => Domain::ROUTING_REDIRECT,
                        ]);
                    }
                    $lockedDomain->forceFill([
                        'status'            => Domain::STATUS_ACTIVE,
                        'published_at'      => now(),
                        'publication_error' => null,
                        'is_primary'        => $makePrimary,
                        'routing_behavior'  => Domain::ROUTING_REDIRECT,
                    ])->save();
                });

                // Refresh the manifest after the staged domain becomes active
                // and, when requested, assumes the primary role.
                $this->domainConfig->sync();
            }
        } catch (\Throwable $exception) {
            DB::connection('saas')->transaction(function () use ($site, $domain, $created, $previousPrimaryId, $existingSnapshot): void {
                $lockedSite = Site::query()->lockForUpdate()->findOrFail($site->getKey());
                if ($created) {
                    $domain->delete();
                } elseif ($existingSnapshot !== null) {
                    $domain->forceFill($existingSnapshot)->save();
                }
                $lockedSite->domains()->update(['is_primary' => false]);
                if ($previousPrimaryId) {
                    $lockedSite->domains()->whereKey($previousPrimaryId)->update(['is_primary' => true]);
                }
            });
            if ($published) {
                try {
                    $this->domainConfig->sync();
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            $this->audit->record($site, 'domain.add', 'failed', $exception->getMessage(), ['host' => $parsed['host']]);

            throw $exception;
        }
        $domain->refresh();
        $this->audit->record($site, 'domain.add', 'success', null, ['host' => $domain->host, 'primary' => $domain->is_primary]);

        return $domain;
    }

    public function changeSubdomain(Site $site, string $subdomain): Domain
    {
        return $this->domainConfig->synchronized(
            fn (): Domain => $this->changeSubdomainSynchronized($site, $subdomain)
        );
    }

    private function changeSubdomainSynchronized(Site $site, string $subdomain): Domain
    {
        $subdomain = strtolower(trim($subdomain));
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $subdomain) !== 1) {
            throw new RuntimeException('子域名必须是 1-63 位小写字母、数字或连字符，且不能以连字符开头或结尾。');
        }
        if (in_array($subdomain, (array) config('saas.domains.reserved_subdomains', []), true)) {
            throw new RuntimeException("子域名 {$subdomain} 已被平台保留。");
        }

        try {
            $baseDomain = DomainName::normalize((string) config('saas.base_domain'));
            $host       = DomainName::normalize("{$subdomain}.{$baseDomain}");
        } catch (\Throwable $exception) {
            throw new RuntimeException('SAAS_BASE_DOMAIN 未配置或新子域名无效。', 0, $exception);
        }

        $fields = [
            'scheme', 'is_primary', 'routing_behavior', 'status', 'origin',
            'verification_method', 'verification_token', 'verification_expires_at',
            'verification_attempts', 'verified_at', 'last_checked_at', 'verification_error',
            'published_at', 'last_probe_at', 'publication_error',
        ];
        $snapshots = $site->domains()->get()->mapWithKeys(
            static fn (Domain $domain): array => [$domain->id => $domain->only($fields)]
        )->all();
        $primary     = $site->domains()->where('status', Domain::STATUS_ACTIVE)->where('is_primary', true)->first();
        $makePrimary = ! $primary || $primary->origin === Domain::ORIGIN_PLATFORM;
        $target      = $this->addDomainSynchronized($site, 'https://' . $host, $makePrimary);

        try {
            DB::connection('saas')->transaction(function () use ($site, $target): void {
                Site::query()->lockForUpdate()->findOrFail($site->getKey());
                $lockedTarget = Domain::query()->lockForUpdate()->findOrFail($target->getKey());
                if ($lockedTarget->site_id   !== $site->id
                    || $lockedTarget->origin !== Domain::ORIGIN_PLATFORM
                    || $lockedTarget->status !== Domain::STATUS_ACTIVE) {
                    throw new RuntimeException('新平台子域名未能进入可用状态。');
                }

                Domain::query()
                    ->where('site_id', $site->id)
                    ->where('origin', Domain::ORIGIN_PLATFORM)
                    ->whereKeyNot($lockedTarget->getKey())
                    ->update([
                        'status'           => Domain::STATUS_RETIRED,
                        'is_primary'       => false,
                        'routing_behavior' => Domain::ROUTING_REDIRECT,
                        'updated_at'       => now(),
                    ]);
            });
            $this->domainConfig->sync();
        } catch (\Throwable $exception) {
            try {
                DB::connection('saas')->transaction(function () use ($site, $target, $snapshots): void {
                    Site::query()->lockForUpdate()->findOrFail($site->getKey());
                    // Only remove the replacement created by this operation.
                    // A custom-domain verification request can legitimately be
                    // created in parallel and must never be swept up by this
                    // rollback merely because it was absent from the snapshot.
                    if (! array_key_exists($target->getKey(), $snapshots)) {
                        Domain::query()
                            ->where('site_id', $site->id)
                            ->whereKey($target->getKey())
                            ->delete();
                    }
                    foreach ($snapshots as $id => $attributes) {
                        Domain::query()->where('site_id', $site->id)->whereKey($id)->update($attributes);
                    }
                });
                $this->domainConfig->sync();
            } catch (\Throwable $rollbackException) {
                report($rollbackException);
            }
            $this->audit->record($site, 'domain.subdomain', 'failed', $exception->getMessage(), ['host' => $host]);

            throw $exception;
        }

        $this->audit->record($site, 'domain.subdomain', 'success', null, ['host' => $host]);

        return $target->refresh();
    }

    public function removeDomain(Site $site, Domain $domain, bool $force = false): void
    {
        $this->domainConfig->synchronized(
            fn () => $this->removeDomainSynchronized($site, $domain, $force)
        );
    }

    private function removeDomainSynchronized(Site $site, Domain $domain, bool $force): void
    {
        $controlDomains = array_map('strtolower', (array) config('saas.control_domains', []));
        if (in_array(strtolower($domain->host), $controlDomains, true)) {
            throw new RuntimeException('该域名仍在 SAAS_CONTROL_DOMAINS 中，请先更新环境配置。');
        }

        $host     = $domain->host;
        $snapshot = null;
        DB::connection('saas')->transaction(function () use ($site, $domain, $force, &$snapshot): void {
            $lockedSite   = Site::query()->lockForUpdate()->findOrFail($site->getKey());
            $lockedDomain = Domain::query()->lockForUpdate()->findOrFail($domain->getKey());
            if ($lockedDomain->site_id !== $lockedSite->id) {
                throw new RuntimeException('域名不属于该站点。');
            }
            if ($lockedSite->domains()->count() <= 1) {
                throw new RuntimeException('不能删除站点的最后一个域名。');
            }
            if (! $force
                && config('saas.domains.retain_platform_domain', true)
                && $lockedDomain->origin === Domain::ORIGIN_PLATFORM
                && $lockedSite->domains()->where('origin', Domain::ORIGIN_PLATFORM)->count() <= 1) {
                throw new RuntimeException('为保留可恢复的管理入口，不能删除站点最后一个平台域名。控制面可在明确承担风险时强制删除。');
            }
            if ($lockedSite->status      === Site::STATUS_ACTIVE
                && $lockedDomain->status === Domain::STATUS_ACTIVE
                && $lockedSite->domains()->where('status', Domain::STATUS_ACTIVE)->count() <= 1) {
                throw new RuntimeException('不能删除已上线站点的最后一个启用域名，请先暂停站点。');
            }

            $snapshot = $lockedDomain->only([
                'site_id', 'host', 'scheme', 'is_primary', 'routing_behavior', 'status', 'origin',
                'verification_method', 'verification_token', 'verification_expires_at',
                'verification_attempts', 'verified_at', 'last_checked_at', 'verification_error',
                'published_at', 'last_probe_at', 'publication_error',
            ]);
            $lockedDomain->delete();
            if ($snapshot['is_primary']) {
                $lockedSite->domains()
                    ->where('status', Domain::STATUS_ACTIVE)
                    ->orderBy('origin')
                    ->orderBy('id')
                    ->first()?->update(['is_primary' => true]);
            }
        });

        try {
            $this->domainConfig->sync();
        } catch (\Throwable $exception) {
            DB::connection('saas')->transaction(function () use ($site, $snapshot): void {
                $lockedSite = Site::query()->lockForUpdate()->findOrFail($site->getKey());
                if ($snapshot['is_primary']) {
                    $lockedSite->domains()->update(['is_primary' => false]);
                }
                Domain::query()->create($snapshot);
            });
            $this->audit->record($site, 'domain.remove', 'failed', $exception->getMessage(), ['host' => $host]);

            throw $exception;
        }
        $this->audit->record($site, 'domain.remove', 'success', null, ['host' => $host]);
    }

    public function makePrimary(Site $site, Domain $domain): Domain
    {
        return $this->domainConfig->synchronized(
            fn (): Domain => $this->makePrimarySynchronized($site, $domain)
        );
    }

    private function makePrimarySynchronized(Site $site, Domain $domain): Domain
    {
        if ($domain->site_id !== $site->id || ! $domain->isRoutable()) {
            throw new RuntimeException('仅可将本站已启用的域名设为主域名。');
        }

        $previousPrimaryId = null;
        DB::connection('saas')->transaction(function () use ($site, $domain, &$previousPrimaryId): void {
            $lockedSite   = Site::query()->lockForUpdate()->findOrFail($site->getKey());
            $lockedDomain = Domain::query()->lockForUpdate()->findOrFail($domain->getKey());
            if ($lockedDomain->site_id !== $lockedSite->id || ! $lockedDomain->isRoutable()) {
                throw new RuntimeException('仅可将本站已启用的域名设为主域名。');
            }

            $previousPrimaryId = $lockedSite->domains()->where('is_primary', true)->value('id');
            $lockedSite->domains()->where('is_primary', true)->update([
                'is_primary'       => false,
                'routing_behavior' => Domain::ROUTING_REDIRECT,
            ]);
            $lockedDomain->forceFill([
                'is_primary'       => true,
                'routing_behavior' => Domain::ROUTING_REDIRECT,
            ])->save();
        });

        try {
            $this->domainConfig->sync();
        } catch (\Throwable $exception) {
            DB::connection('saas')->transaction(function () use ($site, $previousPrimaryId): void {
                $lockedSite = Site::query()->lockForUpdate()->findOrFail($site->getKey());
                $lockedSite->domains()->update(['is_primary' => false]);
                if ($previousPrimaryId) {
                    $lockedSite->domains()->whereKey($previousPrimaryId)->update(['is_primary' => true]);
                }
            });
            $this->audit->record($site, 'domain.primary', 'failed', $exception->getMessage(), ['host' => $domain->host]);

            throw $exception;
        }
        $this->audit->record($site, 'domain.primary', 'success', null, ['host' => $domain->host]);

        return $domain->refresh();
    }

    public function setDomainRouting(Site $site, Domain $domain, string $mode): Domain
    {
        return $this->domainConfig->synchronized(
            fn (): Domain => $this->setDomainRoutingSynchronized($site, $domain, $mode)
        );
    }

    private function setDomainRoutingSynchronized(Site $site, Domain $domain, string $mode): Domain
    {
        if (! in_array($mode, [Domain::ROUTING_REDIRECT, Domain::ROUTING_ALIAS], true)) {
            throw new RuntimeException('非主域名仅支持 redirect 或 alias 模式。');
        }

        $previous = null;
        DB::connection('saas')->transaction(function () use ($site, $domain, $mode, &$previous): void {
            $lockedSite   = Site::query()->lockForUpdate()->findOrFail($site->getKey());
            $lockedDomain = Domain::query()->lockForUpdate()->findOrFail($domain->getKey());
            if ($lockedDomain->site_id !== $lockedSite->id || ! $lockedDomain->isRoutable()) {
                throw new RuntimeException('仅可设置本站已启用域名的路由模式。');
            }
            if ($lockedDomain->is_primary) {
                throw new RuntimeException('主域名始终直接提供店面，无需设置跳转或别名模式。');
            }

            $previous = $lockedDomain->routing_behavior;
            $lockedDomain->forceFill(['routing_behavior' => $mode])->save();
        });

        try {
            $this->domainConfig->sync();
        } catch (\Throwable $exception) {
            Domain::query()->whereKey($domain->getKey())->update(['routing_behavior' => $previous]);
            $this->audit->record($site, 'domain.routing', 'failed', $exception->getMessage(), [
                'host' => $domain->host,
                'mode' => $mode,
            ]);

            throw $exception;
        }

        $this->audit->record($site, 'domain.routing', 'success', null, [
            'host' => $domain->host,
            'from' => $previous,
            'to'   => $mode,
        ]);

        return $domain->refresh();
    }

    private function assertPlatformManagedHost(string $host): void
    {
        try {
            $baseDomain = DomainName::normalize((string) config('saas.base_domain'));
        } catch (\Throwable $exception) {
            throw new RuntimeException('SAAS_BASE_DOMAIN 未配置或无效，不能直接发布平台子域名。', 0, $exception);
        }

        if ($host === $baseDomain || ! str_ends_with($host, '.' . $baseDomain)) {
            throw new RuntimeException('该域名不属于平台子域名命名空间，请改用自有域名 TXT 验证流程。');
        }

        foreach ((array) config('saas.control_domains', []) as $controlDomain) {
            try {
                $controlHost = DomainName::normalize((string) $controlDomain);
            } catch (\Throwable) {
                continue;
            }
            if ($host === $controlHost) {
                throw new RuntimeException('控制站域名不能分配给租户。');
            }
        }

        $relative = substr($host, 0, -strlen('.' . $baseDomain));
        $reserved = array_map('strtolower', (array) config('saas.domains.reserved_subdomains', []));
        if (! str_contains($relative, '.') && in_array($relative, $reserved, true)) {
            throw new RuntimeException("平台子域名 {$relative} 已被系统保留。");
        }
    }
}
