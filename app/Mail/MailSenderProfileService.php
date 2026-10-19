<?php

namespace App\Mail;

use App\Mail\Exceptions\MailSenderAuthorizationException;
use App\Saas\Models\Domain;
use App\Saas\Models\MailSenderDomain;
use App\Saas\Models\Site;
use Exception;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves and authorizes per-tenant sender identities.
 *
 * Sender profiles deliberately live in the tenant settings database while
 * domain ownership lives in the SaaS control database. This keeps a tenant
 * administrator from claiming another tenant's address by editing a form (or
 * even their own isolated database) directly.
 */
class MailSenderProfileService
{
    private ?bool $senderAssignmentTableReady = null;

    /** @var array<string, array<int, string>> */
    private array $senderAssignmentsBySite = [];

    public const DEFAULT = 'default';

    public const ORDERS = 'orders';

    public const ACCOUNTS = 'accounts';

    public const RETURNS = 'returns';

    public const MARKETING = 'marketing';

    /** @return array<string, string> */
    public static function categories(): array
    {
        return [
            self::DEFAULT   => 'mail_sender_category_default',
            self::ORDERS    => 'mail_sender_category_orders',
            self::ACCOUNTS  => 'mail_sender_category_accounts',
            self::RETURNS   => 'mail_sender_category_returns',
            self::MARKETING => 'mail_sender_category_marketing',
        ];
    }

    public static function categoryForMailType(?string $mailType): string
    {
        $mailType = strtolower(trim((string) $mailType));

        return match (true) {
            str_contains($mailType, 'order') => self::ORDERS,
            str_contains($mailType, 'registration'), str_contains($mailType, 'forgotten'), str_contains($mailType, 'account') => self::ACCOUNTS,
            str_contains($mailType, 'rma'), str_contains($mailType, 'return'), str_contains($mailType, 'support') => self::RETURNS,
            str_contains($mailType, 'marketing'), str_contains($mailType, 'newsletter') => self::MARKETING,
            default => self::DEFAULT,
        };
    }

    /**
     * Return normalized profiles, including a legacy mail_from fallback.
     *
     * @return array<string, array{address: string, name: string, reply_to: string}>
     */
    public function profiles(): array
    {
        $stored = system_setting('base.mail_senders', []);
        $stored = is_array($stored) ? $stored : [];
        $legacy = system_setting('base.mail_from', []);
        $legacy = is_array($legacy) ? $legacy : [];

        if ($this->usesBundledRelay()) {
            return $this->repairBundledRelayProfiles($stored, $legacy);
        }

        return $this->normalizeProfiles($stored, $legacy);
    }

    /**
     * Make a legacy bundled-relay configuration immediately usable.
     *
     * Existing installations can contain the template address
     * support@example.com, missing category identities, or a shared platform
     * address belonging to another tenant. Runtime mail must fail safe to the
     * signed tenant identity even before the upgrade migration persists the
     * repaired settings.
     *
     * @param array<string, mixed> $profiles
     * @param array<string, mixed> $legacy
     * @return array<string, array{address: string, name: string, reply_to: string}>
     */
    public function repairBundledRelayProfiles(array $profiles, array $legacy = []): array
    {
        try {
            $normalized = $this->normalizeProfiles($profiles, $legacy);
        } catch (Exception) {
            // A malformed value already stored in the database must not make
            // every notification or the settings page return HTTP 500.
            $normalized = $this->normalizeProfiles([], [], true);
        }

        foreach ($normalized as $category => &$profile) {
            $address    = $profile['address'];
            $authorized = false;
            if (filter_var($address, FILTER_VALIDATE_EMAIL)) {
                try {
                    $this->assertAuthorized($address);
                    $authorized = true;
                } catch (Exception) {
                    // Replace only the unusable sender. Valid verified custom
                    // sender domains remain exactly as the tenant configured.
                }
            }

            if (! $authorized) {
                $profile['address'] = $this->suggestedAddress($category);
            }
            if ($profile['reply_to'] !== '' && ! filter_var($profile['reply_to'], FILTER_VALIDATE_EMAIL)) {
                $profile['reply_to'] = '';
            }
        }
        unset($profile);

        return $normalized;
    }

    /**
     * Resolve one profile. Empty category fields inherit from the default
     * identity so existing stores keep working after the upgrade.
     *
     * @return array{address: string, name: string, reply_to: string}
     */
    public function resolve(string $category = self::DEFAULT): array
    {
        $profiles = $this->profiles();
        $default  = $profiles[self::DEFAULT];
        $profile  = $profiles[$category] ?? $default;

        return [
            'address'  => $profile['address'] ?: $default['address'],
            'name'     => $profile['name'] ?: $default['name'],
            'reply_to' => $profile['reply_to'] ?: $default['reply_to'],
        ];
    }

    public function apply(Mailable $mailable, string $category, ?string $deliveryId = null): Mailable
    {
        $profile = $this->resolve($category);
        if ($profile['address'] !== '') {
            $mailable->from($profile['address'], $profile['name'] ?: null);
        }
        if ($profile['reply_to'] !== '') {
            $mailable->replyTo($profile['reply_to']);
        }
        $mailable->withSymfonyMessage(static function ($message) use ($category, $deliveryId): void {
            $message->getHeaders()->addTextHeader('X-Longos-Sender-Category', $category);
            if (is_string($deliveryId) && preg_match('/^[a-zA-Z0-9._:-]{1,128}$/', $deliveryId)) {
                $message->getHeaders()->addTextHeader('X-Longos-Delivery-Id', $deliveryId);
            }
        });

        return $mailable;
    }

    /**
     * Validate the complete write payload and preserve a stable shape.
     *
     * @param array<string, mixed> $profiles
     * @param array<string, mixed> $legacy
     * @return array<string, array{address: string, name: string, reply_to: string}>
     */
    public function prepareForStorage(array $profiles, array $legacy, string $engine): array
    {
        $normalized = $this->normalizeProfiles($profiles, $legacy);
        foreach ($normalized as $category => $profile) {
            foreach (['address', 'reply_to'] as $field) {
                $value = $profile[$field];
                if ($value !== '' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception("{$category} 发件身份的 {$field} 邮箱格式不正确。");
                }
            }

            if ($profile['address'] !== '' && $engine === 'self_hosted') {
                $this->assertAuthorized($profile['address']);
            }
        }

        if ($engine !== '' && $normalized[self::DEFAULT]['address'] === '') {
            throw new Exception('请先填写默认发件邮箱。');
        }

        return $normalized;
    }

    public function assertAuthorized(string $address): void
    {
        $this->assertAuthorizedForSite($address, Site::current());
    }

    /**
     * Authorize a sender for an explicitly resolved site.
     *
     * The explicit form is used by the control-domain Cloud Mail gateway,
     * which serves every tenant without switching the current request away
     * from the control database.
     */
    public function assertAuthorizedForSite(string $address, ?Site $site): void
    {
        $address = trim($address);
        if (! filter_var($address, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n\x00]/', $address)) {
            throw new MailSenderAuthorizationException('发件邮箱格式不正确。');
        }

        $at                    = strrpos($address, '@');
        $local                 = $at === false ? '' : strtolower(substr($address, 0, $at));
        $domain                = $at === false ? '' : strtolower(substr($address, $at + 1));
        $allowedSigningDomains = $this->configuredDomains();

        if (! in_array($domain, $allowedSigningDomains, true)) {
            $platformDomain = strtolower(trim((string) config('mail.relay.platform_sender_domain')));
            $message        = "发件域名 {$domain} 尚未由内置邮件引擎签名。";
            if ($platformDomain !== '') {
                $message .= " 网站已自动配置平台发件域 {$platformDomain}，请使用页面中自动生成的建议地址。";
            }
            $message .= ' 如需换用新域名，请先在邮局中生成 DKIM、发布 DNS，并将它加入 MAIL_RELAY_DOMAINS 和 MAIL_RELAY_ALLOWED_SENDER_DOMAINS。';

            throw new MailSenderAuthorizationException($message);
        }

        if (! (bool) config('saas.enabled')) {
            return;
        }

        if (! $site) {
            throw new MailSenderAuthorizationException('无法确定当前租户，已拒绝使用内置邮件发件人。');
        }

        $platformDomain = strtolower(trim((string) config('mail.relay.platform_sender_domain')));
        if ($platformDomain !== '' && $domain === $platformDomain) {
            if ((bool) data_get($site->meta, 'control_site', false)) {
                return;
            }

            $suffix = '+' . $this->siteToken($site);
            if (! str_ends_with($local, $suffix) || strlen($local) <= strlen($suffix)) {
                throw new MailSenderAuthorizationException("共享发件域名的邮箱必须以 {$suffix} 结尾，例如 orders{$suffix}@{$domain}，以隔离租户身份。");
            }

            return;
        }

        if (! $this->siteOwnsDomain($site, $domain)) {
            throw new MailSenderAuthorizationException("发件域名 {$domain} 未由 LONGOS 主站分配给当前租户。");
        }
    }

    /**
     * Return only mail-engine signing domains that the selected site may use.
     *
     * A domain being signed by the relay is necessary but not sufficient in
     * SaaS mode: the control site must also assign that exact mail domain to
     * this tenant. This is intentionally independent from storefront routing,
     * allowing www.example.com to send as orders@example.com without unsafe
     * parent/sibling-domain inference.
     *
     * @return array<int, string>
     */
    public function availableDomains(?Site $site = null): array
    {
        $domains = $this->configuredDomains();
        if (! (bool) config('saas.enabled')) {
            return $domains;
        }

        $site ??= Site::current();
        if (! $site) {
            return [];
        }

        $platformDomain = strtolower(trim((string) config('mail.relay.platform_sender_domain'), '.'));

        return array_values(array_filter($domains, function (string $domain) use ($platformDomain, $site): bool {
            if ($platformDomain !== '' && $domain === $platformDomain) {
                return true;
            }

            return $this->siteOwnsDomain($site, $domain);
        }));
    }

    /**
     * @param array<int, string>|null $availableDomains
     * @return array<int, string>
     */
    public function suggestedAddresses(string $category, ?array $availableDomains = null): array
    {
        $site = Site::current();

        return array_map(
            fn (string $domain): string => $this->buildSuggestedAddress($category, $domain, $site),
            $availableDomains ?? $this->availableDomains($site),
        );
    }

    public function suggestedAddress(string $category): string
    {
        $domain = strtolower(trim((string) config('mail.relay.platform_sender_domain')));
        if ($domain === '' || ! in_array($domain, $this->configuredDomains(), true)) {
            $domain = $this->availableDomains()[0] ?? '';
        }
        if ($domain === '') {
            return '';
        }

        return $this->buildSuggestedAddress($category, $domain, Site::current());
    }

    private function buildSuggestedAddress(string $category, string $domain, ?Site $site): string
    {
        $domain = strtolower(trim($domain, '.'));

        $local = match ($category) {
            self::ORDERS    => 'orders',
            self::ACCOUNTS  => 'accounts',
            self::RETURNS   => 'returns',
            self::MARKETING => 'marketing',
            default         => 'no-reply',
        };
        $platformDomain = strtolower(trim((string) config('mail.relay.platform_sender_domain'), '.'));
        if ((bool) config('saas.enabled') && $site && $domain === $platformDomain && ! (bool) data_get($site->meta, 'control_site', false)) {
            $local .= '+' . $this->siteToken($site);
        }

        return "{$local}@{$domain}";
    }

    public static function domainIsWithinVerifiedHost(string $senderDomain, string $verifiedHost): bool
    {
        $senderDomain = strtolower(trim($senderDomain, '.'));
        $verifiedHost = strtolower(trim($verifiedHost, '.'));

        return $senderDomain !== '' && $verifiedHost !== ''
                                    && ($senderDomain === $verifiedHost || str_ends_with($senderDomain, '.' . $verifiedHost));
    }

    /** @return array<string, array{address: string, name: string, reply_to: string}> */
    private function normalizeProfiles(array $profiles, array $legacy, bool $autoFill = false): array
    {
        $storeName    = trim((string) system_setting('base.store_name', config('app.name')));
        $contactEmail = trim((string) system_setting('base.email', ''));
        $contactEmail = filter_var($contactEmail, FILTER_VALIDATE_EMAIL) ? $contactEmail : '';
        $result       = [];
        foreach (array_keys(self::categories()) as $category) {
            $profile = $profiles[$category] ?? [];
            $profile = is_array($profile) ? $profile : [];
            if ($category === self::DEFAULT) {
                $profile['address'] = $profile['address'] ?? ($legacy['address'] ?? ($autoFill ? '' : $contactEmail));
                $profile['name']    = $profile['name']    ?? ($legacy['name'] ?? $storeName);
            }

            if ($autoFill) {
                // A fresh bundled-relay configuration must not inherit the
                // template's support@example.com or a tenant contact address.
                // Generate an actually signed, tenant-scoped identity for
                // every mail category instead.
                $profile['address']  = $this->suggestedAddress($category);
                $profile['name']     = ($profile['name'] ?? '') ?: $storeName;
                $profile['reply_to'] = $profile['reply_to'] ?? $contactEmail;
            }

            $result[$category] = [
                'address'  => $this->clean($profile['address'] ?? '', 320),
                'name'     => $this->clean($profile['name'] ?? '', 200),
                'reply_to' => $this->clean($profile['reply_to'] ?? '', 320),
            ];
        }

        return $result;
    }

    private function usesBundledRelay(): bool
    {
        $engine = strtolower(trim((string) system_setting('base.mail_engine', '')));

        return $engine === 'self_hosted' || ($engine === '' && (bool) config('mail.relay.enabled'));
    }

    private function clean(mixed $value, int $maxLength): string
    {
        $value = trim((string) $value);
        if (preg_match('/[\r\n\x00]/', $value) || mb_strlen($value) > $maxLength) {
            throw new Exception('发件人配置包含无效字符或超过长度限制。');
        }

        return $value;
    }

    /** @return array<int, string> */
    public function configuredDomains(): array
    {
        $domains = config('mail.relay.allowed_sender_domains', []);
        if (is_string($domains)) {
            $domains = explode(',', $domains);
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $domain): string => strtolower(trim((string) $domain, " .\t\n\r\0\x0B")),
            is_array($domains) ? $domains : []
        ))));
    }

    private function siteOwnsDomain(Site $site, string $senderDomain): bool
    {
        $assigned = $this->assignedDomains($site);
        if ($assigned !== null) {
            return in_array(strtolower(trim($senderDomain, '.')), $assigned, true);
        }

        // Upgrade compatibility before the new control-plane migration has
        // run. Once the assignment table exists, routing hosts are never used
        // as an implicit grant for a mail identity.
        $ownerId = Domain::query()
            ->whereIn('host', $this->domainSuffixes($senderDomain))
            ->routable()
            ->get(['site_id', 'host'])
            ->sortByDesc(static fn (Domain $domain): int => strlen((string) $domain->host))
            ->first()?->site_id;

        return $ownerId !== null && (string) $ownerId === (string) $site->getKey();
    }

    /** @return array<int, string>|null */
    private function assignedDomains(Site $site): ?array
    {
        $this->senderAssignmentTableReady ??= Schema::connection('saas')->hasTable('saas_mail_sender_domains');
        if (! $this->senderAssignmentTableReady) {
            return null;
        }

        $key = (string) $site->getKey();
        if (! array_key_exists($key, $this->senderAssignmentsBySite)) {
            $this->senderAssignmentsBySite[$key] = MailSenderDomain::query()
                ->where('site_id', $site->getKey())
                ->pluck('domain')
                ->map(static fn (mixed $domain): string => strtolower(trim((string) $domain, '.')))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return $this->senderAssignmentsBySite[$key];
    }

    /** @return array<int, string> */
    private function domainSuffixes(string $domain): array
    {
        $labels   = explode('.', strtolower(trim($domain, '.')));
        $suffixes = [];
        // A public suffix alone cannot establish sender ownership.
        for ($index = 0, $last = count($labels) - 1; $index < $last; $index++) {
            $suffixes[] = implode('.', array_slice($labels, $index));
        }

        return $suffixes;
    }

    private function siteToken(Site $site): string
    {
        $token = strtolower((string) $site->code);
        $token = trim((string) preg_replace('/[^a-z0-9-]+/', '-', $token), '-');

        return $token !== '' ? $token : strtolower(substr((string) $site->uuid, 0, 12));
    }
}
