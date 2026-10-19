<?php

namespace Beike\Services;

use InvalidArgumentException;

/**
 * Builds the standard storefront legal and customer-service documents from a
 * small, validated merchant profile.
 *
 * Templates deliberately use plain, readable English because the LONGOS
 * storefront locale is fixed to English. They are an operational starting
 * point, not a substitute for jurisdiction-specific legal review.
 */
class StorePolicyTemplateService
{
    public const RETURN_SHIPPING_OPTIONS = ['customer', 'merchant'];

    public const DUTIES_OPTIONS = ['customer', 'merchant'];

    public const DATA_SALE_OPTIONS = ['no', 'yes'];

    public static function profile(): array
    {
        $saved    = system_setting('base.store_policy_profile', []);
        $saved    = is_array($saved) ? $saved : [];
        $defaults = [
            'business_name'             => trim((string) system_setting('base.store_name', site_brand_name())),
            'contact_email'             => trim((string) system_setting('base.email', '')),
            'contact_phone'             => trim((string) system_setting('base.telephone', '')),
            'business_address'          => '',
            'support_hours'             => 'Monday–Friday, 9:00 AM–5:00 PM',
            'support_response_hours'    => 48,
            'jurisdiction'              => '',
            'effective_date'            => date('Y-m-d'),
            'cancellation_window_hours' => 2,
            'return_window_days'        => 30,
            'refund_processing_days'    => 10,
            'processing_days_min'       => 1,
            'processing_days_max'       => 3,
            'transit_days_min'          => 5,
            'transit_days_max'          => 10,
            'return_shipping'           => 'customer',
            'international_shipping'    => true,
            'duties_responsibility'     => 'customer',
            'data_sale'                 => 'no',
        ];

        try {
            return self::normalizeProfile(array_merge($defaults, $saved));
        } catch (InvalidArgumentException) {
            // A malformed value imported from an older release must not make
            // the whole System Settings page unavailable. Render safe defaults
            // and let the administrator save a clean profile.
            return self::normalizeProfile($defaults);
        }
    }

    /**
     * Normalize values before either saving or rendering them into HTML.
     */
    public static function normalizeProfile(mixed $profile, bool $strict = false): array
    {
        if (! is_array($profile)) {
            throw new InvalidArgumentException('政策模板资料格式不正确。');
        }

        $normalized = [
            'business_name'             => self::text($profile['business_name'] ?? '', 120),
            'contact_email'             => strtolower(self::text($profile['contact_email'] ?? '', 190)),
            'contact_phone'             => self::text($profile['contact_phone'] ?? '', 60),
            'business_address'          => self::text($profile['business_address'] ?? '', 500),
            'support_hours'             => self::text($profile['support_hours'] ?? '', 180),
            'support_response_hours'    => self::integer($profile['support_response_hours'] ?? 48, 1, 336, '客服回复时间'),
            'jurisdiction'              => self::text($profile['jurisdiction'] ?? '', 160),
            'effective_date'            => self::date($profile['effective_date'] ?? date('Y-m-d')),
            'cancellation_window_hours' => self::integer($profile['cancellation_window_hours'] ?? 2, 0, 168, '订单取消时限'),
            'return_window_days'        => self::integer($profile['return_window_days'] ?? 30, 1, 365, '退货期限'),
            'refund_processing_days'    => self::integer($profile['refund_processing_days'] ?? 10, 1, 60, '退款处理时间'),
            'processing_days_min'       => self::integer($profile['processing_days_min'] ?? 1, 0, 30, '备货时间下限'),
            'processing_days_max'       => self::integer($profile['processing_days_max'] ?? 3, 0, 60, '备货时间上限'),
            'transit_days_min'          => self::integer($profile['transit_days_min'] ?? 5, 1, 90, '运输时间下限'),
            'transit_days_max'          => self::integer($profile['transit_days_max'] ?? 10, 1, 180, '运输时间上限'),
            'return_shipping'           => self::choice($profile['return_shipping'] ?? 'customer', self::RETURN_SHIPPING_OPTIONS, '退件运费承担方'),
            'international_shipping'    => self::boolean($profile['international_shipping'] ?? true),
            'duties_responsibility'     => self::choice($profile['duties_responsibility'] ?? 'customer', self::DUTIES_OPTIONS, '关税承担方'),
            'data_sale'                 => self::choice($profile['data_sale'] ?? 'no', self::DATA_SALE_OPTIONS, '个人信息出售/共享状态'),
        ];

        if ($normalized['processing_days_min'] > $normalized['processing_days_max']) {
            throw new InvalidArgumentException('备货时间下限不能大于上限。');
        }
        if ($normalized['transit_days_min'] > $normalized['transit_days_max']) {
            throw new InvalidArgumentException('运输时间下限不能大于上限。');
        }
        if ($normalized['contact_email'] !== '' && ! filter_var($normalized['contact_email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('政策联系邮箱格式不正确。');
        }
        if ($normalized['contact_phone'] !== '' && ! preg_match('/^\+?[0-9\s().-]{3,60}$/', $normalized['contact_phone'])) {
            throw new InvalidArgumentException('客服联系电话格式不正确。');
        }

        if ($strict) {
            foreach ([
                'business_name'    => '商家/公司名称',
                'contact_email'    => '政策联系邮箱',
                'business_address' => '商家联系地址',
                'jurisdiction'     => '适用法律与管辖地',
            ] as $key => $label) {
                if ($normalized[$key] === '') {
                    throw new InvalidArgumentException("请填写{$label}后再生成政策。");
                }
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, array{enabled: bool, title: string, content: string}>
     */
    public static function generate(mixed $profile): array
    {
        $profile = self::normalizeProfile($profile, true);

        $business     = self::h($profile['business_name']);
        $email        = self::h($profile['contact_email']);
        $phone        = self::h($profile['contact_phone']);
        $address      = self::h($profile['business_address']);
        $supportHours = self::h($profile['support_hours']);
        $jurisdiction = self::h($profile['jurisdiction']);
        $effective    = self::h($profile['effective_date']);
        $contact      = '<a href="mailto:' . $email . '">' . $email . '</a>';
        $phoneHref    = preg_replace('/[^0-9+]/', '', $profile['contact_phone']) ?? '';
        $phoneLine    = $phone !== '' && $phoneHref !== ''
            ? '<br><strong>Phone:</strong> <a href="tel:' . self::h($phoneHref) . '">' . $phone . '</a>'
            : '';
        $hoursLine = $supportHours !== ''
            ? '<br><strong>Support hours:</strong> ' . $supportHours
            : '';
        $cancellationWindow = $profile['cancellation_window_hours'] > 0
            ? "within {$profile['cancellation_window_hours']} hours after the order is placed and before fulfillment begins"
            : 'before fulfillment begins';

        $returnShipping = $profile['return_shipping'] === 'merchant'
            ? 'We provide or reimburse reasonable return shipping for authorized returns.'
            : 'Customers are responsible for return shipping unless the item is defective, damaged, or incorrect.';
        $international = $profile['international_shipping']
            ? 'International delivery is available to supported destinations shown at checkout.'
            : 'We currently ship only to destinations made available at checkout and do not offer general international delivery.';
        $duties = $profile['duties_responsibility'] === 'merchant'
            ? 'Where import duties or taxes apply, they are included or paid by us unless checkout clearly states otherwise.'
            : 'The recipient is responsible for import duties, taxes, and customs charges not collected at checkout.';
        $dataSale = $profile['data_sale'] === 'yes'
            ? 'We may sell or share personal information for cross-context behavioral advertising where disclosed. Where applicable, you may opt out by contacting us or using the privacy control presented on our storefront.'
            : 'We do not sell personal information for money or share it for cross-context behavioral advertising. If this practice changes, we will update this policy and provide any controls required by law.';

        return [
            'refund' => [
                'enabled' => true,
                'title'   => 'Refund Policy',
                'content' => <<<HTML
<p><strong>Effective date:</strong> {$effective}</p>
<p>We want you to be satisfied with your purchase from {$business}. This policy explains when and how returns, exchanges, and refunds are handled.</p>
<h2>Return window</h2>
<p>You may request a return within {$profile['return_window_days']} calendar days after delivery. Mandatory cancellation or withdrawal rights that apply in your country remain unaffected.</p>
<h2>Return eligibility</h2>
<ul><li>The item must be unused, unworn, and in substantially the same condition in which it was received.</li><li>Original packaging, accessories, manuals, and proof of purchase should be included where reasonably possible.</li><li>Personalized, made-to-order, perishable, hygiene-sensitive, final-sale, gift-card, and digital items may be excluded where permitted by law and clearly identified before purchase.</li></ul>
<h2>How to start a return</h2>
<p>Email {$contact} with your order number, the item you wish to return, and the reason for the request. Do not send an item back until return instructions have been provided.</p>
<h2>Damaged, defective, or incorrect items</h2>
<p>Inspect your order promptly after delivery. Contact us as soon as possible with photographs or other useful details if an item is damaged, defective, or incorrect so that we can provide an appropriate replacement, repair, or refund.</p>
<h2>Return shipping</h2>
<p>{$returnShipping} Original shipping charges are non-refundable unless required by law or the return results from our error.</p>
<h2>Refunds</h2>
<p>We will inspect the returned item and notify you of the decision. Approved refunds are sent to the original payment method within {$profile['refund_processing_days']} business days after approval. Your bank or payment provider may need additional time to post the credit.</p>
<h2>Contact</h2>
<p>{$business}<br>{$address}<br>{$contact}</p>
HTML,
            ],
            'shipping' => [
                'enabled' => true,
                'title'   => 'Shipping Policy',
                'content' => <<<HTML
<p><strong>Effective date:</strong> {$effective}</p>
<p>This Shipping Policy describes order processing and delivery by {$business}.</p>
<h2>Order processing</h2>
<p>Orders are normally prepared within {$profile['processing_days_min']}–{$profile['processing_days_max']} business days, excluding weekends and public holidays. High-volume periods, address verification, customized products, or events outside our control may require additional time.</p>
<h2>Delivery estimates</h2>
<p>Estimated transit time is {$profile['transit_days_min']}–{$profile['transit_days_max']} business days after dispatch. Available services, delivery estimates, and shipping charges are shown at checkout before payment. Estimates are not guarantees unless expressly stated.</p>
<h2>Tracking</h2>
<p>When tracking is available, we will send the tracking number after dispatch. Carrier scans may take time to appear.</p>
<h2>Delivery destinations and customs</h2>
<p>{$international} {$duties}</p>
<h2>Address accuracy and delivery issues</h2>
<p>Please verify the delivery address before submitting the order. Contact us promptly at {$contact} if you notice an error. If tracking shows a delivery problem, loss, or material delay, contact us so we can investigate with the carrier.</p>
<h2>Delays</h2>
<p>We ship within the timeframe stated at checkout or in the order confirmation. If no timeframe is stated, we ship within the period required by applicable law. If a material delay occurs, we will provide updated information and any choice to accept the delay or cancel for a refund that applicable law requires.</p>
<h2>Contact</h2>
<p>{$business}<br>{$address}<br>{$contact}</p>
HTML,
            ],
            'privacy' => [
                'enabled' => true,
                'title'   => 'Privacy Policy',
                'content' => <<<HTML
<p><strong>Effective date:</strong> {$effective}</p>
<p>{$business}, located at {$address}, is responsible for the personal information described in this Privacy Policy. Questions and privacy requests may be sent to {$contact}.</p>
<h2>Information we collect</h2>
<p>Depending on how you use the store, we may collect contact and account details, billing and shipping information, order and transaction records, customer-support messages, device and browser information, IP address, cookie and analytics data, and marketing preferences. Payment credentials are processed by our payment providers and are not stored by us except where expressly stated.</p>
<h2>How we use information</h2>
<ul><li>To operate the storefront, create accounts, process payments, fulfill and track orders, and handle returns.</li><li>To communicate about orders, support requests, security, and service changes.</li><li>To prevent fraud, protect the service, maintain records, and meet legal obligations.</li><li>With consent or another lawful basis, to measure performance and send marketing communications that can be opted out of.</li></ul>
<h2>Legal bases</h2>
<p>Where applicable, we process information to perform a contract, comply with law, protect legitimate business and security interests, or based on consent. Consent may be withdrawn without affecting earlier lawful processing.</p>
<h2>Disclosure of information</h2>
<p>We disclose information only as needed to service providers such as hosting, payment, fraud-prevention, analytics, communication, fulfillment, and delivery providers; to professional advisers; during a legitimate business transfer; or when required by law. Providers may use information only for permitted services and legal obligations.</p>
<h2>Sale or sharing of personal information</h2>
<p>{$dataSale}</p>
<h2>Cookies and analytics</h2>
<p>We use necessary cookies to operate the store and may use optional analytics or advertising technologies where enabled. Available cookie choices and browser controls can be used to manage optional technologies.</p>
<h2>Retention, security, and international transfers</h2>
<p>We retain information only for as long as reasonably needed for the purposes above, including tax, accounting, dispute, fraud-prevention, and legal requirements. We use reasonable administrative, technical, and organizational safeguards. When information is transferred internationally, we use safeguards required by applicable law.</p>
<h2>Your privacy rights</h2>
<p>Depending on your location, you may have rights to know or access, correct, delete, restrict or object to processing, receive portable data, withdraw consent, opt out of certain sale, sharing, or targeted advertising, and appeal or complain to a regulator. We will not unlawfully discriminate against you for exercising a privacy right. Submit a request to {$contact}; identity verification may be required.</p>
<h2>Children and policy changes</h2>
<p>The store is not directed to children who cannot legally consent to data processing. We may update this policy and will post the revised effective date here; material notices will be provided where required.</p>
HTML,
            ],
            'terms' => [
                'enabled' => true,
                'title'   => 'Terms of Service',
                'content' => <<<HTML
<p><strong>Effective date:</strong> {$effective}</p>
<p>These Terms of Service govern your use of the {$business} storefront and purchases from us. By using the store or placing an order, you agree to these terms. Nothing in these terms limits rights that cannot lawfully be excluded.</p>
<h2>Store eligibility and accounts</h2>
<p>You must be legally capable of entering a purchase contract. You are responsible for accurate account and order information, safeguarding credentials, and activity performed through your account.</p>
<h2>Products, pricing, and availability</h2>
<p>We aim to describe products, availability, prices, taxes, and images accurately, but minor display differences may occur. We may correct genuine errors before dispatch and will give you the option to confirm a material correction or cancel for a refund where required.</p>
<h2>Orders and payment</h2>
<p>An order is an offer to purchase. We may verify, accept, limit, or cancel an order for lawful reasons such as stock errors, suspected fraud, payment failure, or delivery restrictions. A contract is formed when we confirm acceptance. Charges, currency, shipping costs, taxes, and available payment methods are displayed at checkout.</p>
<h2>Shipping, returns, and refunds</h2>
<p>Our Shipping Policy and Refund Policy form part of these terms. Delivery estimates and return conditions are disclosed in those policies and at checkout. Mandatory consumer guarantees and withdrawal rights remain unaffected.</p>
<h2>Acceptable use and intellectual property</h2>
<p>You may not misuse the storefront, interfere with security or availability, introduce malicious code, scrape content unlawfully, commit fraud, or violate another person's rights. Store content, trademarks, designs, software, and media are owned by or licensed to us and may not be commercially reused without permission.</p>
<h2>Third-party services</h2>
<p>Payment, delivery, social, or other third-party services may have separate terms. We are not responsible for independent third-party services beyond responsibilities imposed by applicable law.</p>
<h2>Disclaimers and liability</h2>
<p>To the maximum extent permitted by law, the storefront is provided without warranties beyond those expressly stated or legally required. We do not exclude liability that cannot legally be excluded. For other claims, liability is limited to losses that are reasonably foreseeable and directly related to the affected order.</p>
<h2>Governing law and changes</h2>
<p>These terms are governed by {$jurisdiction}, without depriving consumers of mandatory protections available where they live. We may update these terms prospectively by posting a revised effective date. The terms in effect when an order was accepted continue to govern that order unless law requires otherwise.</p>
<h2>Contact</h2>
<p>{$business}<br>{$address}<br>{$contact}</p>
HTML,
            ],
            'contact' => [
                'enabled' => true,
                'title'   => 'Contact Us',
                'content' => <<<HTML
<p><strong>Effective date:</strong> {$effective}</p>
<p>We are here to help with product questions, orders, delivery, returns, payments, and privacy requests.</p>
<h2>Contact details</h2>
<p><strong>{$business}</strong><br><strong>Email:</strong> {$contact}{$phoneLine}<br><strong>Postal address:</strong> {$address}{$hoursLine}</p>
<h2>When contacting us</h2>
<p>For order-related questions, include your order number and the email address used at checkout. For a damaged, defective, or incorrect item, include a description and clear photographs where possible. Do not send passwords or full payment-card details.</p>
<h2>Response times</h2>
<p>We aim to respond within {$profile['support_response_hours']} hours, excluding weekends and public holidays. Complex delivery investigations, payment reviews, or legal and privacy requests may require additional time, but we will keep you informed.</p>
<h2>Returns and cancellations</h2>
<p>Contacting us does not by itself approve a return or cancel an order. Follow the instructions in our Refund Policy and Order Cancellation Policy, and wait for confirmation before returning an item.</p>
<h2>Business correspondence</h2>
<p>Formal correspondence may be sent to {$address}. Electronic support and policy enquiries should be sent to {$contact}.</p>
HTML,
            ],
            'payment' => [
                'enabled' => true,
                'title'   => 'Payment Policy',
                'content' => <<<HTML
<p><strong>Effective date:</strong> {$effective}</p>
<p>This Payment Policy explains how payments for orders placed with {$business} are handled.</p>
<h2>Accepted payment methods</h2>
<p>The payment methods currently available for your country, currency, device, and order are displayed at checkout. A payment logo elsewhere on the storefront does not override the methods presented during checkout.</p>
<h2>Currency, taxes, and total price</h2>
<p>The checkout shows the transaction currency, product price, shipping charges, discounts, and taxes collected by us before you confirm payment. Your card issuer or payment provider may apply its own currency-conversion or cross-border fees.</p>
<h2>Authorization and capture</h2>
<p>When you submit an order, your payment provider may authorize, reserve, or capture the amount according to the selected method. An order is not paid until the provider confirms a successful payment to us.</p>
<h2>Payment security</h2>
<p>Payments are processed through enabled third-party payment providers using encrypted connections. We do not ask you to send a password, one-time code, or full card number by email or customer-support message.</p>
<h2>Failed, pending, or duplicate payments</h2>
<p>If payment is declined, pending, or interrupted, verify the order status before trying again. A temporary authorization may appear even when an order is not completed; the issuing bank controls when such holds are released. Contact {$contact} if you believe you were charged more than once.</p>
<h2>Fraud prevention and verification</h2>
<p>We and our payment providers may perform lawful fraud, identity, address, and transaction checks. An order may be held, limited, or cancelled when payment cannot be verified or misuse is reasonably suspected.</p>
<h2>Refunds and disputes</h2>
<p>Approved refunds are returned to the original payment method as described in our Refund Policy. Contact us before starting a payment dispute so we have an opportunity to investigate; this does not limit any non-waivable right to contact your payment provider.</p>
HTML,
            ],
            'cancellation' => [
                'enabled' => true,
                'title'   => 'Order Cancellation Policy',
                'content' => <<<HTML
<p><strong>Effective date:</strong> {$effective}</p>
<p>This policy explains how to request an order cancellation from {$business}.</p>
<h2>Cancellation window</h2>
<p>You may request cancellation {$cancellationWindow}. We will make a reasonable effort to stop fulfillment, but a request is not accepted until we confirm the cancellation.</p>
<h2>How to request cancellation</h2>
<p>Email {$contact} promptly with your order number, the email address used at checkout, and a clear cancellation request. Do not send payment credentials.</p>
<h2>Orders already processed or shipped</h2>
<p>Once an order has entered fulfillment, been personalized, or been dispatched, cancellation may no longer be possible. Eligible items may instead be returned under our Refund Policy after delivery. Do not refuse delivery unless we instruct you to do so.</p>
<h2>Address and order changes</h2>
<p>Requests to change an address, product, quantity, or variant are handled like cancellation requests and are not guaranteed. We may need to cancel the original order and ask you to place a new one.</p>
<h2>Cancellations by us</h2>
<p>We may cancel an order for lawful reasons including unavailable stock, pricing or inventory errors, failed payment, suspected fraud, or delivery restrictions. If we cancel a paid order, we will issue a refund to the original payment method.</p>
<h2>Refund timing</h2>
<p>Approved cancellation refunds are submitted promptly. Your bank or payment provider controls the final posting time; see our Refund Policy for the normal processing period.</p>
HTML,
            ],
            'cookies' => [
                'enabled' => true,
                'title'   => 'Cookie Policy',
                'content' => <<<HTML
<p><strong>Effective date:</strong> {$effective}</p>
<p>This Cookie Policy explains how {$business} may use cookies and similar technologies on the storefront. It should be read with our Privacy Policy.</p>
<h2>What cookies are</h2>
<p>Cookies are small data files stored by a browser. Similar technologies include local storage, pixels, tags, and software identifiers. They may remember information during a visit or across visits.</p>
<h2>How we use these technologies</h2>
<ul><li><strong>Necessary:</strong> to operate security, sessions, cart, checkout, fraud prevention, and user-requested features.</li><li><strong>Preferences:</strong> to remember choices such as language, currency, or display preferences where available.</li><li><strong>Analytics:</strong> to understand storefront performance and usage when analytics tools are enabled.</li><li><strong>Advertising:</strong> to measure campaigns or personalize advertising only when such tools are enabled and permitted.</li></ul>
<h2>Third-party technologies</h2>
<p>Enabled payment, analytics, advertising, support, social, and embedded-content providers may set or read their own technologies. Their processing is governed by their privacy notices and the choices available through their services.</p>
<h2>Your choices</h2>
<p>You can use available storefront privacy controls, provider controls, and browser settings to block or delete optional cookies. Blocking necessary storage may prevent the cart, account, checkout, security, or other requested features from working correctly.</p>
<h2>Global privacy signals</h2>
<p>Where required by applicable law and technically supported, we will treat a recognized opt-out preference signal as a request for the browser or device that sends it.</p>
<h2>Changes and contact</h2>
<p>We may update this policy when technology or legal requirements change. Questions about cookies or privacy may be sent to {$contact}.</p>
HTML,
            ],
        ];
    }

    private static function text(mixed $value, int $limit): string
    {
        $value = is_scalar($value) ? trim(strip_tags((string) $value)) : '';
        $value = preg_replace('/[\x{0000}-\x{001F}\x{007F}]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value)                          ?? '';

        return mb_substr($value, 0, $limit);
    }

    private static function integer(mixed $value, int $min, int $max, string $label): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number < $min || $number > $max) {
            throw new InvalidArgumentException("{$label}必须是 {$min} 至 {$max} 之间的整数。");
        }

        return $number;
    }

    private static function choice(mixed $value, array $choices, string $label): string
    {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';
        if (! in_array($value, $choices, true)) {
            throw new InvalidArgumentException("{$label}选项无效。");
        }

        return $value;
    }

    private static function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function date(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        $date  = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('政策生效日期格式不正确。');
        }

        return $value;
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
