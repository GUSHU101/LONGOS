<?php

namespace Tests\Unit;

use App\Mail\Exceptions\MailSenderAuthorizationException;
use App\Mail\MailSenderProfileService;
use App\Saas\Models\Site;
use Beike\Mail\Transport\LongosRelayTransport;
use Exception;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Mime\Email;

class MailSenderProfileSecurityTest extends TestCase
{
    private $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $this->app->make(Kernel::class)->bootstrap();
        config([
            'saas.enabled'                        => true,
            'mail.relay.allowed_sender_domains'   => ['mail.example.com'],
            'mail.relay.platform_sender_domain'   => 'mail.example.com',
        ]);
        $site       = new Site;
        $site->id   = 42;
        $site->uuid = '2db5782d-fbbe-4b24-bc98-6803f9605c42';
        $site->code = 'shop-a';
        $site->meta = ['control_site' => false];
        $this->app->instance(config('multitenancy.current_tenant_container_key'), $site);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        Facade::clearResolvedInstances();
        $this->app->flush();
        parent::tearDown();
    }

    public function test_shared_domain_sender_is_scoped_to_current_tenant(): void
    {
        $service = new MailSenderProfileService;
        $service->assertAuthorized('orders+shop-a@mail.example.com');
        self::assertSame('orders+shop-a@mail.example.com', $service->suggestedAddress(MailSenderProfileService::ORDERS));
    }

    public function test_control_site_uses_only_the_platform_sender_domain(): void
    {
        $control       = new Site;
        $control->id   = 1;
        $control->uuid = '04cf0115-cc86-4262-88e9-c7100872b5da';
        $control->code = 'control';
        $control->meta = ['control_site' => true];
        config(['mail.relay.allowed_sender_domains' => ['brand-a.example.com', 'mail.example.com']]);
        $this->app->instance(config('multitenancy.current_tenant_container_key'), $control);

        $service = new MailSenderProfileService;
        self::assertSame(['mail.example.com'], $service->availableDomains());
        self::assertSame('orders@mail.example.com', $service->suggestedAddress(MailSenderProfileService::ORDERS));

        $this->expectException(MailSenderAuthorizationException::class);
        $this->expectExceptionMessage('主站只能使用平台发件域');
        $service->assertAuthorizedForSite('orders@brand-a.example.com', $control);
    }

    public function test_control_gateway_can_authorize_an_explicit_tenant_without_switching_current_site(): void
    {
        $target       = new Site;
        $target->id   = 84;
        $target->uuid = 'fea9285f-6f46-45d6-aeb8-89c472d52691';
        $target->code = 'shop-b';
        $target->meta = ['control_site' => false];
        $service      = new MailSenderProfileService;

        $service->assertAuthorizedForSite('orders+shop-b@mail.example.com', $target);

        $this->expectException(MailSenderAuthorizationException::class);
        $service->assertAuthorizedForSite('orders+shop-a@mail.example.com', $target);
    }

    public function test_generic_or_other_tenant_shared_sender_is_rejected(): void
    {
        $service = new MailSenderProfileService;

        foreach (['orders@mail.example.com', 'orders+shop-b@mail.example.com'] as $address) {
            try {
                $service->assertAuthorized($address);
                self::fail("Expected {$address} to be rejected.");
            } catch (Exception $exception) {
                self::assertStringContainsString('+shop-a', $exception->getMessage());
            }
        }

        config(['saas.enabled' => false]);
        foreach (['orders@@mail.example.com', '@mail.example.com', "orders\r\n@mail.example.com"] as $address) {
            try {
                $service->assertAuthorized($address);
                self::fail("Expected malformed sender {$address} to be rejected.");
            } catch (MailSenderAuthorizationException $exception) {
                self::assertStringContainsString('格式', $exception->getMessage());
            }
        }
    }

    public function test_unsigned_domain_is_rejected_before_submission(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('DKIM');

        (new MailSenderProfileService)->assertAuthorized('orders+shop-a@unsigned.example.net');
    }

    public function test_fresh_bundled_relay_profiles_are_filled_with_signed_tenant_identities(): void
    {
        $service  = new MailSenderProfileService;
        $method   = new ReflectionMethod($service, 'normalizeProfiles');
        $profiles = $method->invoke($service, [], [
            'address' => 'support@example.com',
            'name'    => 'Legacy sender',
        ], true);

        self::assertSame('no-reply+shop-a@mail.example.com', $profiles[MailSenderProfileService::DEFAULT]['address']);
        self::assertSame('orders+shop-a@mail.example.com', $profiles[MailSenderProfileService::ORDERS]['address']);
        self::assertSame('accounts+shop-a@mail.example.com', $profiles[MailSenderProfileService::ACCOUNTS]['address']);
        self::assertSame('returns+shop-a@mail.example.com', $profiles[MailSenderProfileService::RETURNS]['address']);
        self::assertSame('marketing+shop-a@mail.example.com', $profiles[MailSenderProfileService::MARKETING]['address']);
    }

    public function test_legacy_invalid_profiles_are_repaired_without_overwriting_valid_tenant_identity(): void
    {
        $profiles = (new MailSenderProfileService)->repairBundledRelayProfiles([
            MailSenderProfileService::DEFAULT => [
                'address'  => 'support@example.com',
                'name'     => 'Legacy Store',
                'reply_to' => 'not-an-email',
            ],
            MailSenderProfileService::ORDERS => [
                'address'  => 'orders+shop-a@mail.example.com',
                'name'     => 'Orders Team',
                'reply_to' => 'support@example.net',
            ],
        ]);

        self::assertSame('no-reply+shop-a@mail.example.com', $profiles[MailSenderProfileService::DEFAULT]['address']);
        self::assertSame('', $profiles[MailSenderProfileService::DEFAULT]['reply_to']);
        self::assertSame('orders+shop-a@mail.example.com', $profiles[MailSenderProfileService::ORDERS]['address']);
        self::assertSame('Orders Team', $profiles[MailSenderProfileService::ORDERS]['name']);
        self::assertSame('accounts+shop-a@mail.example.com', $profiles[MailSenderProfileService::ACCOUNTS]['address']);
    }

    public function test_verified_subdomain_does_not_authorize_sibling_domains(): void
    {
        self::assertTrue(MailSenderProfileService::domainIsWithinVerifiedHost('mail.shop.example.com', 'shop.example.com'));
        self::assertFalse(MailSenderProfileService::domainIsWithinVerifiedHost('mail.example.com', 'shop.example.com'));
        self::assertFalse(MailSenderProfileService::domainIsWithinVerifiedHost('evilshop.example.com', 'shop.example.com'));
    }

    public function test_mail_log_retries_map_back_to_the_same_sender_category(): void
    {
        self::assertSame(MailSenderProfileService::ORDERS, MailSenderProfileService::categoryForMailType('order_update'));
        self::assertSame(MailSenderProfileService::ACCOUNTS, MailSenderProfileService::categoryForMailType('customer_forgotten'));
        self::assertSame(MailSenderProfileService::RETURNS, MailSenderProfileService::categoryForMailType('rma_new'));
        self::assertSame(MailSenderProfileService::DEFAULT, MailSenderProfileService::categoryForMailType('system_test'));
    }

    public function test_one_default_sender_is_sufficient_and_optional_profiles_remain_blank(): void
    {
        $profiles = (new MailSenderProfileService)->prepareForStorage([
            MailSenderProfileService::DEFAULT => [
                'address'  => 'store@example.com',
                'name'     => 'Example Store',
                'reply_to' => 'support@example.com',
            ],
        ], [], 'smtp');

        self::assertSame('store@example.com', $profiles[MailSenderProfileService::DEFAULT]['address']);
        foreach ([
            MailSenderProfileService::ORDERS,
            MailSenderProfileService::ACCOUNTS,
            MailSenderProfileService::RETURNS,
            MailSenderProfileService::MARKETING,
        ] as $category) {
            self::assertSame(['address' => '', 'name' => '', 'reply_to' => ''], $profiles[$category]);
        }
    }

    public function test_transport_payload_preserves_recipients_and_stable_delivery_id(): void
    {
        $email = (new Email)
            ->from('orders+shop-a@mail.example.com')
            ->to('to@example.net')
            ->cc('cc@example.net')
            ->bcc('bcc@example.net')
            ->replyTo('support@example.net')
            ->subject('Order')
            ->text('Body');
        $email->getHeaders()->addTextHeader('X-Longos-Delivery-Id', 'delivery:42');
        $email->getHeaders()->addTextHeader('X-Longos-Sender-Category', MailSenderProfileService::ORDERS);

        $transport = new LongosRelayTransport('http://mail-relay:3000', str_repeat('a', 32));
        $method    = new ReflectionMethod($transport, 'payload');
        $payload   = $method->invoke($transport, $email);

        self::assertSame('delivery:42', $payload['requestId']);
        self::assertSame(['to@example.net'], $payload['to']);
        self::assertSame(['cc@example.net'], $payload['cc']);
        self::assertSame(['bcc@example.net'], $payload['bcc']);
        self::assertSame('orders', $payload['senderContext']['category']);
        self::assertSame('shop-a', $payload['senderContext']['tenantCode']);
    }
}
