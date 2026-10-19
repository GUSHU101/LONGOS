<?php

use Beike\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Translate only the stock LONGOS storefront copy. Merchant-authored
     * content is intentionally untouched: it needs editorial review rather
     * than an irreversible machine substitution during deployment.
     */
    public function up(): void
    {
        foreach (['design_setting', 'footer_setting'] as $name) {
            $setting = Setting::query()
                ->where('type', 'system')
                ->where('space', 'base')
                ->where('name', $name)
                ->first();

            if (! $setting || ! $setting->json) {
                continue;
            }

            $value = json_decode((string) $setting->value, true);
            if (! is_array($value)) {
                continue;
            }

            $translated = $this->translateValue($value);
            if ($translated !== $value) {
                DB::table('settings')->where('id', $setting->id)->update([
                    'value'      => json_encode($translated, JSON_UNESCAPED_UNICODE),
                    'json'       => 1,
                    'updated_at' => now(),
                ]);
            }
        }

        DB::table('settings')
            ->where('type', 'plugin')
            ->where('space', 'longos_live_chat')
            ->where('name', 'welcome_message')
            ->where('value', '您好！请留下您的问题，我们会尽快回复。')
            ->update([
                'value'      => 'Hello! Leave us a message and we will get back to you shortly.',
                'updated_at' => now(),
            ]);
    }

    private function translateValue(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->translateValue($item);
            }

            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        return self::DEFAULT_COPY[$value] ?? $value;
    }

    private const DEFAULT_COPY = [
        '放心购买' => 'Shop with confidence',
        '安全结账与支付保障' => 'Secure checkout and payment protection',
        '安全支付' => 'Secure payments',
        '支持的支付方式' => 'Accepted payment methods',
        '可在结账时选择' => 'Select at checkout',
        '安全结账' => 'Secure checkout',
        '支付信息加密保护' => 'Encrypted payment protection',
        '可追踪配送' => 'Trackable delivery',
        '订单物流状态清晰可查' => 'Follow your order at every step',
        '30 天退换' => '30-day returns',
        '符合条件可申请退换' => 'Eligible items can be returned',
        '组合购买，立享优惠' => 'Bundle more, save more',
        '选择更适合你的优惠方案' => 'Choose the offer that works best for you',
        '复制优惠码，在结账时使用' => 'Copy the code and use it at checkout',
        '优惠不可与其他活动叠加，具体以结账页为准。' => 'Offers cannot be combined. Final eligibility is shown at checkout.',
        '购买 2 件或以上' => 'Buy 2 or more',
        '购买 3 件或以上' => 'Buy 3 or more',
        '立减 15%' => 'Save 15%',
        '输入优惠码即可享受折扣' => 'Enter the code at checkout',
        '结账时使用优惠码' => 'Use the discount code at checkout',
        '15% OFF + 免运费' => '15% off + free shipping',
        '更高价值的组合方案' => 'Our best-value bundle',
        '推荐' => 'Recommended',
        '热门' => 'Popular',
        '安心购买' => 'Shop with confidence',
        '清晰展示最能打动客户的服务承诺' => 'Show customers the promises that matter most',
        '精选品质' => 'Selected quality',
        '为每一件商品提供清晰的信息与可靠的质量标准。' => 'Clear product information and dependable quality standards for every item.',
        '快速响应' => 'Responsive service',
        '下单、配送与售后进度一目了然。' => 'Keep orders, delivery, and after-sales progress easy to follow.',
        '贴心服务' => 'Here to help',
        '需要帮助时，可以快速联系店铺客服。' => 'Contact the store team quickly whenever you need assistance.',
        '客户怎么说' => 'What customers say',
        '用真实体验建立购买信心' => 'Build confidence through genuine customer experiences',
        '商品信息清晰，配送过程也能随时查看，整体体验很放心。' => 'The product details were clear and I could follow delivery at every step.',
        '客服回复很快，收到的商品和页面描述一致。' => 'Support replied quickly and the item matched the description.',
        '结账过程很顺畅，下次还会继续购买。' => 'Checkout was smooth. I will definitely shop here again.',
        '陈女士' => 'Emily R.',
        '李先生' => 'Daniel K.',
        '王女士' => 'Olivia M.',
        '已验证购买' => 'Verified purchase',
        '回购客户' => 'Returning customer',
        '获取新品与专属优惠' => 'New arrivals and exclusive offers',
        '订阅后第一时间获取上新、补货和会员活动信息。' => 'Subscribe for first access to launches, restocks, and member offers.',
        '订阅更新' => 'Subscribe',
        '常见问题' => 'Frequently asked questions',
        '回答客户在购买前最关心的问题' => 'Answer the questions customers ask before they buy',
        '订单多久可以发出？' => 'When will my order ship?',
        '订单确认后会尽快处理，并通过邮件发送物流更新。' => 'We process confirmed orders as quickly as possible and send delivery updates by email.',
        '是否支持退换货？' => 'Can I return an item?',
        '请查看店铺的退换货政策，或联系在线客服获取帮助。' => 'Please review the store return policy or contact our support team for help.',
        '限时优惠' => 'Limited-time offer',
        '为新客户提供更好的第一单体验' => 'Make every first order feel more rewarding',
        '立即查看' => 'Shop now',
        '<p><strong>LONGOS</strong></p><p>打造可靠、灵活且面向全球客户的品牌体验。</p>' => '<p><strong>LONGOS</strong></p><p>Building reliable, flexible commerce experiences for global customers.</p>',
        '安全支付方式' => 'Accepted payment methods',
    ];

    public function down(): void
    {
        // English storefront copy remains the intended LONGOS default.
    }
};
