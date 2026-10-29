<?php

namespace Tests\Unit;

use Beike\Notifications\Concerns\HasMailSenderProfile;
use PHPUnit\Framework\TestCase;

class LongosRelayTransportContractTest extends TestCase
{
    public function test_bundled_mailer_uses_hmac_api_instead_of_trusted_smtp(): void
    {
        $config     = file_get_contents(dirname(__DIR__, 2) . '/config/mail.php');
        $compose    = file_get_contents(dirname(__DIR__, 2) . '/docker-compose.yml');
        $envExample = file_get_contents(dirname(__DIR__, 2) . '/.env.example');
        $entrypoint = file_get_contents(dirname(__DIR__, 2) . '/docker/mail-relay/entrypoint.sh');
        $source     = file_get_contents(dirname(__DIR__, 2) . '/beike/Mail/Transport/LongosRelayTransport.php');
        $provider   = file_get_contents(dirname(__DIR__, 2) . '/beike/Shop/Providers/ShopServiceProvider.php');

        self::assertMatchesRegularExpression("/'transport'\\s*=>\\s*'longos_relay'/", $config);
        self::assertStringContainsString("hash_hmac('sha256'", $source);
        self::assertStringContainsString("'X-Relay-Nonce'", $source);
        self::assertStringContainsString("'X-Relay-Client'    => 'longos'", $source);
        self::assertStringContainsString("'X-Longos-Delivery-Id'", $source);
        self::assertStringContainsString("unset(\$payload['text'])", $source);
        self::assertStringContainsString("unset(\$payload['html'])", $source);
        self::assertStringContainsString('SMTP_INET_INTERFACES: loopback-only', $compose);
        self::assertStringContainsString('LONGOS_RELAY_SHARED_SECRET: ${SERVICE_PASSWORD_64_MAILRELAY}', $compose);
        self::assertStringContainsString('CLOUD_MAIL_RELAY_SHARED_SECRET: ${SERVICE_PASSWORD_64_CLOUDMAIL}', $compose);
        self::assertStringContainsString('MAIL_RELAY_DOMAINS:-${SAAS_BASE_DOMAIN}', $compose);
        self::assertStringContainsString('MAIL_RELAY_DNS_RECORDS_PATH: /var/lib/longos-mail-dns', $compose);
        self::assertStringContainsString('MAIL_RELAY_MAX_REQUEST_BYTES:-33554432', $compose);
        self::assertStringContainsString('MAIL_RELAY_MAX_REQUEST_BYTES=33554432', $envExample);
        self::assertStringNotContainsString('MAIL_RELAY_MAX_REQUEST_BYTES=26214400', $envExample);
        self::assertStringContainsString('mail-relay-dns:/var/lib/longos-mail-dns:ro', $compose);
        self::assertStringContainsString('mail-relay-dns:/var/lib/mail-relay-dns', $compose);
        self::assertStringContainsString('DNS_RECORDS_PATH: /var/lib/mail-relay-dns', $compose);
        self::assertStringContainsString('start_period: 30s', $compose);
        self::assertStringContainsString("env('SAAS_BASE_DOMAIN', '')", $config);
        self::assertStringContainsString("'no-reply@' . \$platformSenderDomain", $config);
        self::assertStringContainsString("trim((string) env('MAIL_FROM_ADDRESS', ''))", $config);
        self::assertStringContainsString('in_array($configuredMailFromDomain, $allowedDomains, true)', $config);
        self::assertStringContainsString("'configured_from_address_rejected'", $config);
        self::assertStringContainsString("\$baselineMail = trim((string) config('mail.from.address'))", $provider);
        self::assertStringContainsString('$storeMail = $baselineMail;', $provider);
        self::assertStringNotContainsString("\n      RELAY_SHARED_SECRET:", $compose);
        self::assertStringContainsString('load_secret LONGOS_RELAY_SHARED_SECRET', $entrypoint);
        self::assertStringContainsString('load_secret CLOUD_MAIL_RELAY_SHARED_SECRET', $entrypoint);
        self::assertStringContainsString('cannot both be configured', $entrypoint);
        self::assertStringNotContainsString('${RELAY_SHARED_SECRET:?', $entrypoint);
        self::assertStringContainsString('must be different', $entrypoint);
        self::assertStringContainsString('must each contain at least 32 characters', $entrypoint);
        self::assertStringContainsString('Migrated legacy mail relay request limit from 25 MiB to 32 MiB.', $entrypoint);
        self::assertStringContainsString('[ "$MAX_REQUEST_BYTES" = "26214400" ]', $entrypoint);
        self::assertStringNotContainsString('eval ', $entrypoint);
        self::assertStringContainsString("sed 's/^[[:space:]]*//; s/[[:space:]]*$//'", $entrypoint);
        self::assertStringContainsString('MAIL_DOMAINS must contain at least one valid signing domain', $entrypoint);
        self::assertStringContainsString('postfix_chroot_etc', $entrypoint);
        self::assertStringContainsString('cp -L "$postfix_runtime_source"', $entrypoint);
        self::assertStringContainsString('ALLOWED_SENDER_DOMAINS must contain at least one valid signing domain', $entrypoint);
        self::assertStringContainsString('[a-zA-Z0-9_-]{0,62}', $entrypoint);
        self::assertStringContainsString('dkim_value=', $entrypoint);
        self::assertStringContainsString('chmod 0644 "$public_temp"', $entrypoint);
        self::assertStringNotContainsString('- "25"', $compose);
    }

    public function test_public_cloud_mail_path_terminates_at_the_scoped_laravel_gateway(): void
    {
        $routes     = file_get_contents(dirname(__DIR__, 2) . '/routes/integrations.php');
        $controller = file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/CloudMailRelayController.php');
        $compose    = file_get_contents(dirname(__DIR__, 2) . '/docker-compose.yml');

        self::assertStringContainsString("Route::prefix('_longos/mail-relay')", $routes);
        self::assertStringContainsString("Route::get('dns/manifest'", $routes);
        self::assertStringContainsString('throttle:cloud-mail-dns-manifest', $routes);
        self::assertStringContainsString("RateLimiter::for('cloud-mail-dns-manifest'", file_get_contents(dirname(__DIR__, 2) . '/app/Providers/RouteServiceProvider.php'));
        self::assertStringNotContainsString('cloudflare_api_token', $controller);
        self::assertStringContainsString('ABILITY_CLOUD_MAIL_SEND', $controller);
        self::assertStringContainsString('ABILITY_CLOUD_MAIL_HEALTH', $controller);
        self::assertStringContainsString('assertAuthorized', $controller);
        self::assertStringContainsString("'X-Relay-Client'    => 'cloud-mail'", $controller);
        self::assertStringContainsString('- "caddy_0.reverse_proxy={{upstreams 80}}"', $compose);
        self::assertStringNotContainsString('reverse_proxy: "mail-relay:3000"', $compose);
        self::assertStringNotContainsString('ports:', $this->mailRelayServiceBlock($compose));
    }

    public function test_relay_contract_preserves_all_recipient_classes_and_context(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/docker/mail-relay/src/server.mjs');

        self::assertStringContainsString('normalizeAddressList(body.cc', $source);
        self::assertStringContainsString('normalizeAddressList(body.bcc', $source);
        self::assertStringContainsString('normalizeSenderContext(body.senderContext)', $source);
        self::assertStringContainsString('globalRateLimitExceeded()', $source);
        self::assertStringContainsString('senderRateLimitExceeded(rateKey)', $source);
        self::assertStringContainsString('Relay idempotency capacity is exhausted', $source);
        self::assertStringContainsString('receiptStore: receiptCapacityAvailable()', $source);
        self::assertStringContainsString('idempotencyCapacity: maxIdempotencyRecords', $source);
        self::assertStringContainsString('submissionId: requestId', $source);
        self::assertStringContainsString('base64 attachment expansion and message metadata', $source);
        self::assertStringContainsString("'allow_redirects' => false", file_get_contents(dirname(__DIR__, 2) . '/beike/Mail/Transport/LongosRelayTransport.php'));
        self::assertStringNotContainsString('while (completedRequests.size + uncertainRequests.size > maxIdempotencyRecords)', $source);
        self::assertStringContainsString('const requestKey = `${relayClient}:${requestId}`', $source);
        self::assertStringContainsString('nonceScope: relayClient', $source);
        self::assertStringNotContainsString('legacySharedSecret', $source);
        self::assertStringContainsString('clientSecrets.size !== 2', $source);
        self::assertStringContainsString('Math.max(86_400, signatureWindowSeconds * 2)', $source);
        self::assertStringContainsString('maxNonceRecords', $source);
        self::assertStringContainsString('server.headersTimeout', $source);
        self::assertStringContainsString('server.requestTimeout', $source);
        self::assertStringContainsString('maxIdempotencyRecords', $source);
        self::assertStringContainsString('requestHash', $source);
        self::assertStringContainsString('MAX_ATTACHMENTS', $source);
        self::assertStringContainsString('Message text must be a string', $source);
        self::assertStringContainsString('IDEMPOTENCY_STORE_PATH', $source);
        self::assertStringContainsString('IDEMPOTENCY_STORE_PATH is required for restart-safe delivery', $source);
        self::assertStringContainsString("state: 'pending'", $source);
        self::assertStringContainsString('fsyncSync(descriptor)', $source);
        self::assertStringContainsString('Previous delivery status is uncertain', $source);
        self::assertStringContainsString("spawnSync('pgrep', ['-x', 'opendkim']", $source);
        self::assertStringContainsString("spawnSync('postqueue', ['-j']", $source);
        self::assertStringContainsString('postfixResolverFilesReady()', $source);
        self::assertStringContainsString("spawnSync('getent', ['ahostsv4', 'example.com']", $source);
        self::assertStringContainsString('mail-relay-state:/var/lib/mail-relay-state', file_get_contents(dirname(__DIR__, 2) . '/docker-compose.yml'));
    }

    public function test_mail_diagnostics_rejects_redirects_and_false_health_pages(): void
    {
        $source      = file_get_contents(dirname(__DIR__, 2) . '/app/Mail/MailDiagnosticsService.php');
        $ptrResolver = file_get_contents(dirname(__DIR__, 2) . '/app/Mail/MailReverseDnsResolver.php');

        self::assertStringContainsString("'allow_redirects' => false", $source);
        self::assertStringContainsString("(\$payload['status'] ?? null) === 'ok'", $source);
        self::assertStringContainsString('MailRelayPublicIpResolver::class', $source);
        self::assertStringContainsString('MailReverseDnsResolver::class', $source);
        self::assertStringContainsString('服务器自动识别', $source);
        self::assertStringContainsString("'allow_redirects' => false", $ptrResolver);
        self::assertStringContainsString("'application/dns-json'", $ptrResolver);
        self::assertStringContainsString("2       => 'SERVFAIL'", $ptrResolver);
        self::assertStringContainsString("5       => 'REFUSED'", $ptrResolver);
        self::assertStringContainsString("\$queueDepth > 0 ? 'warning' : 'ok'", $source);
        self::assertStringContainsString('这些邮件尚未完成公网投递', $source);
        self::assertStringContainsString('不代表收件方已经接收', file_get_contents(dirname(__DIR__, 2) . '/lang/zh_cn/admin/setting.php'));
    }

    public function test_admin_mail_test_returns_only_a_sanitized_failure_reason(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2) . '/beike/Admin/Http/Controllers/SettingController.php');
        $mailLog    = file_get_contents(dirname(__DIR__, 2) . '/beike/Models/MailLog.php');

        self::assertStringContainsString('$safeError = $this->redactMailTestError($e->getMessage())', $controller);
        self::assertStringContainsString("'reason'    => \$safeError", $controller);
        self::assertStringContainsString('json_fail($this->mailTestFailureMessage($safeError))', $controller);
        self::assertStringContainsString('SensitiveValueRedactor::redact', $mailLog);
    }

    public function test_platform_mail_topology_editor_is_shared_control_only_and_never_accepts_secrets(): void
    {
        $runtime    = file_get_contents(dirname(__DIR__, 2) . '/app/Mail/MailRelayRuntimeSettings.php');
        $controller = file_get_contents(dirname(__DIR__, 2) . '/beike/Admin/Http/Controllers/SettingController.php');
        $routes     = file_get_contents(dirname(__DIR__, 2) . '/beike/Admin/Routes/admin.php');
        $view       = file_get_contents(dirname(__DIR__, 2) . '/resources/beike/admin/views/pages/setting/mail.blade.php');

        self::assertStringContainsString("'saas.platform-admin'", $routes);
        self::assertStringContainsString('canManagePlatformMailRelay', $controller);
        self::assertStringContainsString('user?->is_root', $controller);
        self::assertStringContainsString("storage_path('app/longos/mail-relay-runtime.json')", $runtime);
        self::assertStringContainsString("'mail.mailers.self_hosted.endpoint'", $runtime);
        self::assertStringContainsString('mail-relay-runtime-form', $view);
        self::assertStringContainsString('mail_relay[endpoint]', $view);
        self::assertStringContainsString('mail_relay[public_ips]', $view);
        self::assertStringContainsString('mail_relay[node][relay_http_port]', $view);
        self::assertStringContainsString('MailRelayNodeProfile::VERSION', $view);
        self::assertStringContainsString('mail-relay-download-node-config', $view);
        self::assertStringContainsString('id="mail-relay-diagnostics-shortcut"', $view);
        self::assertStringContainsString('app.runDiagnostics();', $view);
        self::assertStringNotContainsString('href="#mail-diagnostics"', $view);
        self::assertStringNotContainsString('SERVICE_PASSWORD_64_MAILRELAY', $view);
        self::assertStringNotContainsString('SERVICE_PASSWORD_64_CLOUDMAIL', $view);
        self::assertStringNotContainsString('mail_relay[longos_secret]', $view);
        self::assertStringNotContainsString('mail_relay[cloud_mail_secret]', $view);
    }

    public function test_queued_mail_failures_are_rethrown_for_laravel_retry(): void
    {
        $channel = file_get_contents(dirname(__DIR__, 2) . '/beike/Channels/BkMailChannel.php');
        $trait   = file_get_contents(dirname(__DIR__, 2) . '/beike/Notifications/Concerns/HasMailSenderProfile.php');

        self::assertStringContainsString('catch (\\Throwable $e)', $channel);
        self::assertStringContainsString('throw $e;', $channel);
        self::assertStringNotContainsString('// 不抛出异常，静默失败', $channel);
        self::assertStringContainsString('public int $tries = 5;', $trait);
        self::assertStringContainsString('public array $backoff = [30, 120, 300, 900];', $trait);
        self::assertStringContainsString('$mailDeliveryId', $trait);
        self::assertStringContainsString('hash(\'sha256\', implode(\',\', $recipients))', $trait);
    }

    public function test_delivery_id_survives_queue_serialization(): void
    {
        $notification = new MailDeliveryIdProbe;
        $deliveryId   = $notification->deliveryId();
        $restored     = unserialize(serialize($notification));

        self::assertNotEmpty($deliveryId);
        self::assertSame($deliveryId, $restored->deliveryId());
    }

    private function mailRelayServiceBlock(string $compose): string
    {
        $start = strpos($compose, "\n  mail-relay:");
        self::assertNotFalse($start);

        return substr($compose, (int) $start);
    }
}

final class MailDeliveryIdProbe
{
    use HasMailSenderProfile;

    public function __construct()
    {
        $this->initializeMailDeliveryId();
    }

    public function deliveryId(): ?string
    {
        return $this->mailDeliveryId;
    }
}
