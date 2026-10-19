<?php

namespace App\Mail;

use App\Mail\Exceptions\MailSenderAuthorizationException;
use App\Saas\Models\Domain;
use App\Saas\Models\MailSenderDomain;
use App\Saas\Models\Site;
use App\Saas\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves a Cloud Mail submission to its authoritative LONGOS site.
 *
 * Cloud Mail has one platform credential and always calls the control-domain
 * gateway. Tenant identity is derived from the already-authorized From
 * address, never from caller-provided senderContext fields. Shared platform
 * addresses use a stable +site-code suffix, so changing a storefront domain
 * does not require a new token or mailbox identity.
 */
class CloudMailTenantResolver
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function gatewayAvailable(Request $request): bool
    {
        if (! (bool) config('saas.enabled')) {
            return true;
        }

        $site = Site::current();

        return $site?->status === Site::STATUS_ACTIVE
            && (bool) data_get($site->meta, 'control_site', false)
            && $this->tenants->isControlHost($request->getHost());
    }

    public function resolve(string $address): ?Site
    {
        if (! (bool) config('saas.enabled')) {
            return null;
        }

        $controlSite = Site::current();
        if (! $controlSite || ! (bool) data_get($controlSite->meta, 'control_site', false)) {
            throw new MailSenderAuthorizationException('Cloud Mail 只能通过 LONGOS 主站控制域名调用。');
        }

        [$local, $domain] = $this->addressParts($address);
        $platformDomain   = strtolower(trim((string) config('mail.relay.platform_sender_domain')));

        if ($platformDomain !== '' && $domain === $platformDomain) {
            $separator = strrpos($local, '+');
            if ($separator === false) {
                return $controlSite;
            }

            $siteCode = substr($local, $separator + 1);
            if ($siteCode === '' || ! preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $siteCode)) {
                throw new MailSenderAuthorizationException('共享发件地址中的租户标识无效。');
            }

            $site = Site::query()
                ->where('status', Site::STATUS_ACTIVE)
                ->where('code', $siteCode)
                ->first();
            if (! $site) {
                throw new MailSenderAuthorizationException("共享发件地址无法匹配有效租户：{$siteCode}。");
            }

            return $site;
        }

        if (Schema::connection('saas')->hasTable('saas_mail_sender_domains')) {
            $assignment = MailSenderDomain::query()
                ->with('site')
                ->where('domain', $domain)
                ->whereHas('site', static fn ($query) => $query->where('status', Site::STATUS_ACTIVE))
                ->first();

            return $assignment?->site ?: $controlSite;
        }

        // Compatibility path used only while upgrading from a release without
        // the explicit sender-domain assignment table.
        $candidates = $this->domainSuffixes($domain);
        $owned      = Domain::query()
            ->with('site')
            ->whereIn('host', $candidates)
            ->routable()
            ->whereHas('site', static fn ($query) => $query->where('status', Site::STATUS_ACTIVE))
            ->get()
            ->sortByDesc(static fn (Domain $item): int => strlen((string) $item->host))
            ->first();

        return $owned?->site ?: $controlSite;
    }

    /** @return array{0: string, 1: string} */
    private function addressParts(string $address): array
    {
        $address = strtolower(trim($address));
        if (! filter_var($address, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n\x00]/', $address)) {
            throw new MailSenderAuthorizationException('发件邮箱格式不正确。');
        }

        $at = strrpos($address, '@');

        return [substr($address, 0, $at), substr($address, $at + 1)];
    }

    /** @return array<int, string> */
    private function domainSuffixes(string $domain): array
    {
        $labels = explode('.', $domain);
        $result = [];
        // A public suffix alone can never be a tenant-owned sender host.
        for ($index = 0, $last = count($labels) - 1; $index < $last; $index++) {
            $result[] = implode('.', array_slice($labels, $index));
        }

        return $result;
    }
}
