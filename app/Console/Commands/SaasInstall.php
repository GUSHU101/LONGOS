<?php

namespace App\Console\Commands;

use App\Saas\DomainConfigWriter;
use App\Saas\Models\Domain;
use App\Saas\Models\Site;
use App\Saas\Support\DomainName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SaasInstall extends Command
{
    protected $signature = 'saas:install
        {--domain= : 当前站点的主域名，默认使用 APP_URL}
        {--code=main : 当前站点 code}
        {--name=LONGOS Main Site : 当前站点名称}';

    protected $description = '将已有单站注册为 SaaS 控制站和第一个租户';

    public function handle(DomainConfigWriter $domainConfig): int
    {
        $site          = null;
        $alreadyActive = false;

        try {
            if (config('saas.enabled')) {
                throw new \RuntimeException('执行 saas:install 前必须保持 SAAS_ENABLED=false，避免首站注册过程接收流量。');
            }
            if (! DB::connection('saas')->getSchemaBuilder()->hasTable('saas_sites')) {
                $this->components->error('SaaS 控制表未就绪，请在 Coolify 重新部署当前 Git 版本，容器入口会自动完成迁移。');

                return self::FAILURE;
            }

            $baseConnection = (string) config('saas.tenant_connection', config('database.default'));
            $database       = (string) config("database.connections.{$baseConnection}.database", '');
            if ($database === '') {
                $database = (string) config('database.connections.tenant_admin.database', '');
            }
            if ($database === '') {
                throw new \RuntimeException('无法识别当前 DB_DATABASE。');
            }

            $domainInput = trim((string) $this->option('domain')) ?: (string) config('app.url');
            $domain      = DomainName::parse($domainInput, (string) config('saas.default_scheme', 'https'));
            $code        = strtolower(Str::slug((string) $this->option('code'))) ?: 'main';

            $site = DB::connection('saas')->transaction(function () use ($database, $domain, $code, &$alreadyActive): Site {
                $site = Site::query()->where('database_name', $database)->lockForUpdate()->first()
                    ?? new Site(['database_name' => $database]);
                if ($site->exists && $site->status === Site::STATUS_ACTIVE) {
                    $boundDomain       = Domain::query()->where('host', $domain['host'])->lockForUpdate()->first();
                    $isSameControlSite = (bool) data_get($site->meta, 'control_site', false)
                        && $site->code            === $code
                        && $boundDomain?->site_id === $site->id
                        && $boundDomain->status   === Domain::STATUS_ACTIVE
                        && (bool) $boundDomain->is_primary;
                    if (! $isSameControlSite) {
                        throw new \RuntimeException('当前数据库已注册为其他上线站点，不能自动覆盖。');
                    }

                    // A PHP-FPM worker can finish registration and be killed
                    // before the installer persists its recovery stage. An
                    // exact retry is safe and must be treated as success.
                    $alreadyActive = true;

                    return $site->load('domains');
                }
                if (! $site->exists) {
                    $site->uuid = (string) Str::uuid();
                }
                $site->fill([
                    'code'           => $code,
                    'name'           => (string) $this->option('name'),
                    // ACTIVATING is included in the generated web-server
                    // config but rejected by TenantFinder. The site becomes
                    // routable only after configuration sync succeeds.
                    'status'         => Site::STATUS_ACTIVATING,
                    'locale'         => (string) system_setting('base.locale', 'zh_cn'),
                    'timezone'       => (string) config('app.timezone', 'Asia/Shanghai'),
                    'provisioned_at' => now(),
                    'meta'           => array_merge((array) $site->meta, [
                        'isolate_files'   => false,
                        'control_site'    => true,
                        'database_mode'   => 'control',
                        'database_prefix' => '',
                    ]),
                ])->save();

                $domainOwner = Domain::query()->where('host', $domain['host'])->first();
                if ($domainOwner && $domainOwner->site_id !== $site->id) {
                    throw new \RuntimeException("域名 {$domain['host']} 已绑定到其他站点。");
                }

                Domain::query()->where('site_id', $site->id)->update([
                    'is_primary'       => false,
                    'routing_behavior' => Domain::ROUTING_REDIRECT,
                ]);
                Domain::query()->updateOrCreate(
                    ['host' => $domain['host']],
                    [
                        'site_id'          => $site->id,
                        'scheme'           => $domain['scheme'],
                        'is_primary'       => true,
                        'routing_behavior' => Domain::ROUTING_REDIRECT,
                        'status'           => Domain::STATUS_ACTIVE,
                        'verified_at'      => null,
                    ]
                );

                return $site->load('domains');
            });

            $sync      = $domainConfig->sync();
            if (! $alreadyActive) {
                DB::connection('saas')->transaction(function () use ($site): void {
                    $activated = Site::query()
                        ->whereKey($site->getKey())
                        ->where('status', Site::STATUS_ACTIVATING)
                        ->update([
                            'status'     => Site::STATUS_ACTIVE,
                            'updated_at' => now(),
                        ]);
                    if ($activated !== 1) {
                        throw new \RuntimeException('域名配置已发布，但首站激活状态已变更。');
                    }
                    Domain::query()
                        ->where('site_id', $site->getKey())
                        ->where('status', Domain::STATUS_ACTIVE)
                        ->update([
                            'published_at'       => now(),
                            'publication_error'  => null,
                            'updated_at'         => now(),
                        ]);
                });
                $site->refresh();
            }
            $message = $alreadyActive ? '已确认现有 SaaS 主站' : '已将当前站点注册为 SaaS 主站';
            $this->components->info("{$message}：{$site->primary_url}");
            $this->table(['站点', '数据库', '域名数', '域名配置'], [[
                $site->code,
                $site->database_name,
                $sync['hosts'],
                $sync['config_path'],
            ]]);

            if (! config('saas.enabled')) {
                $this->components->warn('请在 .env 设置 SAAS_ENABLED=true，再执行 php artisan optimize:clear。');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($site) {
                $failed = Site::query()
                    ->whereKey($site->getKey())
                    ->where('status', Site::STATUS_ACTIVATING)
                    ->update([
                        'status'     => Site::STATUS_FAILED,
                        'updated_at' => now(),
                    ]);
                if ($failed === 1) {
                    try {
                        // Remove any host that may have been published before
                        // the final activation update failed.
                        $domainConfig->sync();
                    } catch (Throwable $cleanupException) {
                        report($cleanupException);
                    }
                }
            }
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
