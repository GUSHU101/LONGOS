<?php

/**
 * SettingsSeeder.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-09-05 19:42:42
 * @modified   2022-09-05 19:42:42
 */

namespace Database\Seeders;

use App\Mail\MailSenderProfileService;
use Beike\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $items = $this->getItems();

        foreach ($items as $item) {
            Setting::query()->firstOrCreate([
                'type'  => $item['type'],
                'space' => $item['space'],
                'name'  => $item['name'],
            ], $item);
        }
    }

    public function getItems()
    {
        $relayEnabled    = (bool) config('mail.relay.enabled', false);
        $senderService   = app(MailSenderProfileService::class);
        $mailFromAddress = $relayEnabled
            ? ($senderService->suggestedAddress(MailSenderProfileService::DEFAULT) ?: trim((string) config('mail.from.address', '')))
            : '';
        $mailFromName = $relayEnabled ? trim((string) config('mail.from.name', config('app.name', 'LONGOS'))) : '';
        $mailSenders  = [];
        foreach (array_keys(MailSenderProfileService::categories()) as $category) {
            $mailSenders[$category] = [
                'address'  => $relayEnabled ? $senderService->suggestedAddress($category) : '',
                'name'     => $mailFromName,
                'reply_to' => '',
            ];
        }

        return [
            ['type' => 'system', 'space' => 'base', 'name' => 'country_id', 'value' => '38', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'locale', 'value' => 'en', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'theme', 'value' => 'default', 'json' => 0],
            ['type' => 'plugin', 'space' => 'service_charge', 'name' => 'status', 'value' => '1', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'status', 'value' => '', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'admin_name', 'value' => 'admin', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'tax', 'value' => '1', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'tax_address', 'value' => 'payment', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'currency', 'value' => 'USD', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'zone_id', 'value' => '602', 'json' => 0],
            ['type' => 'plugin', 'space' => 'header_menu', 'name' => 'status', 'value' => '1', 'json' => 0],
            ['type' => 'plugin', 'space' => 'stripe', 'name' => 'publishable_key', 'value' => '', 'json' => 0],
            ['type' => 'plugin', 'space' => 'stripe', 'name' => 'secret_key', 'value' => '', 'json' => 0],
            ['type' => 'plugin', 'space' => 'stripe', 'name' => 'test_mode', 'value' => '0', 'json' => 0],
            ['type' => 'plugin', 'space' => 'paypal', 'name' => 'sandbox_client_id', 'value' => '', 'json' => 0],
            ['type' => 'plugin', 'space' => 'paypal', 'name' => 'sandbox_secret', 'value' => '', 'json' => 0],
            ['type' => 'plugin', 'space' => 'paypal', 'name' => 'live_client_id', 'value' => '', 'json' => 0],
            ['type' => 'plugin', 'space' => 'paypal', 'name' => 'live_secret', 'value' => '', 'json' => 0],
            ['type' => 'plugin', 'space' => 'paypal', 'name' => 'sandbox_mode', 'value' => '0', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'logo', 'value' => 'image/longos-logo.svg', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'placeholder', 'value' => 'image/placeholder.png', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'favicon', 'value' => 'image/longos-favicon.svg', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'storefront_preset', 'value' => 'minimal', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'brand_primary_color', 'value' => '#155EEF', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'brand_accent_color', 'value' => '#6D28D9', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'brand_border_radius', 'value' => '12', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'meta_title', 'value' => 'LONGOS', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'meta_description', 'value' => 'LONGOS — a modern commerce experience for global brands.', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'meta_keywords', 'value' => 'LONGOS,ecommerce,online store', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'telephone', 'value' => '', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'email', 'value' => '', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'mail_engine', 'value' => $relayEnabled ? 'self_hosted' : '', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'mail_from', 'value' => json_encode([
                'address' => $mailFromAddress,
                'name'    => $mailFromName,
            ], JSON_UNESCAPED_UNICODE), 'json' => 1],
            ['type' => 'system', 'space' => 'base', 'name' => 'mail_senders', 'value' => json_encode($mailSenders, JSON_UNESCAPED_UNICODE), 'json' => 1],
            ['type' => 'plugin', 'space' => 'flat_shipping', 'name' => 'type', 'value' => 'fixed', 'json' => 0],
            ['type' => 'plugin', 'space' => 'flat_shipping', 'name' => 'value', 'value' => '0', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'default_customer_group_id', 'value' => '1', 'json' => 0],
            // External payment methods stay disabled until the merchant saves
            // a complete, validated credential set in the administration UI.
            ['type' => 'plugin', 'space' => 'stripe', 'name' => 'status', 'value' => '0', 'json' => 0],
            ['type' => 'plugin', 'space' => 'longos_product_reviews', 'name' => 'status', 'value' => '1', 'json' => 0],
            ['type' => 'plugin', 'space' => 'longos_product_reviews', 'name' => 'display_limit', 'value' => '6', 'json' => 0],
            ['type' => 'plugin', 'space' => 'longos_live_chat', 'name' => 'status', 'value' => '1', 'json' => 0],
            ['type' => 'plugin', 'space' => 'longos_live_chat', 'name' => 'retention_days', 'value' => '1', 'json' => 0],
            ['type' => 'plugin', 'space' => 'pixel_tracking', 'name' => 'status', 'value' => '0', 'json' => 0],
            ['type' => 'plugin', 'space' => 'shopify_product_transfer', 'name' => 'status', 'value' => '1', 'json' => 0],
            ['type' => 'plugin', 'space' => 'flat_shipping', 'name' => 'status', 'value' => '1', 'json' => 0],
            ['type' => 'plugin', 'space' => 'paypal', 'name' => 'status', 'value' => '0', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'rate_api_key', 'value' => '', 'json' => 0],
            ['type' => 'system', 'space' => 'base', 'name' => 'hot_keywords', 'value' => json_encode([
                'zh_cn' => '连衣裙,运动鞋,T恤,牛仔裤,时尚,潮流,可爱童装,瑜伽健身服',
                'en'    => 'Dress,Sneakers,T-shirt,Jeans,Fashion,Vintage Style,Kidswear,Yoga Activewear',
            ], JSON_UNESCAPED_UNICODE), 'json' => 1],
        ];
    }
}
