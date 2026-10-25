<?php

namespace Plugin\PixelTracking;

use Beike\Services\DesignService;
use Beike\Services\StateMachineService;
use Plugin\PixelTracking\Services\PixelEventDispatcher;
use Plugin\PixelTracking\Services\ServerTrackingService;

class Bootstrap
{
    public function boot(): void
    {
        add_hook_filter('admin.design.index.data', function (array $data): array {
            $data['editor_app_embeds'][] = [
                'code'            => 'pixel_tracking',
                'name'            => '像素与转化追踪',
                'description'     => '启用浏览器侧的 Meta、TikTok、Snapchat 与归因追踪代码。',
                'default_enabled' => true,
            ];

            return $data;
        });

        add_hook_blade('layout.header.code', function ($callback, $output, $data) {
            if (! DesignService::isAppEmbedEnabled('pixel_tracking')
                || ! plugin_setting('pixel_tracking.browser_tracking', true)
                || ! app('router')->has('shop.pixel_tracking.events.store')) {
                return $output;
            }

            return $output . view('PixelTracking::shop.tracker')->render();
        });

        add_hook_blade('product.detail.name', function ($callback, $output, $data) {
            if (! DesignService::isAppEmbedEnabled('pixel_tracking') || ! plugin_setting('pixel_tracking.browser_tracking', true)) {
                return $output;
            }
            $product = $data['data']['product'] ?? [];

            return $output . view('PixelTracking::shop.product_event', compact('product'))->render();
        });

        add_hook_blade('checkout.success.footer', function ($callback, $output, $data) {
            if (! DesignService::isAppEmbedEnabled('pixel_tracking') || ! plugin_setting('pixel_tracking.browser_tracking', true)) {
                return $output;
            }
            $order = $data['data']['order'] ?? null;

            return $output . view('PixelTracking::shop.purchase_event', compact('order'))->render();
        });

        add_hook_filter('cart.store.data', function ($cart) {
            if (! DesignService::isAppEmbedEnabled('pixel_tracking') || ! plugin_setting('pixel_tracking.browser_tracking', true)) {
                return $cart;
            }

            $sku = $cart?->sku;
            if (! $sku) {
                return $cart;
            }

            $cart->setAttribute('tracking', [
                'content_id' => (string) $cart->product_id,
                'item_price' => (float) currency_format($sku->price, '', '', false),
                'currency'   => current_currency_code(),
            ]);

            return $cart;
        });

        add_hook_filter('service.state_machine.change_status.after', function (array $data) {
            $order  = $data['order']  ?? null;
            $status = $data['status'] ?? '';
            if (plugin_setting('pixel_tracking.server_tracking', true)
                && $order
                && $status === StateMachineService::PAID) {
                // Consent, browser identifiers and attribution live on the
                // active checkout request. Snapshot them before Laravel closes
                // the session, then send only scalar data after the response.
                $prepared = app(ServerTrackingService::class)->prepareOrderPurchase($order);
                if ($prepared === null) {
                    return $data;
                }

                PixelEventDispatcher::dispatch($prepared);
            }

            return $data;
        });

        add_hook_filter('repository.order.create.after', function (array $data): array {
            $order = $data['order'] ?? null;
            if ($order && plugin_setting('pixel_tracking.server_tracking', true)) {
                app(ServerTrackingService::class)->rememberOrderContext($order, request());
            }

            return $data;
        });

        add_hook_action('service.account.register.after', function ($customer): void {
            if (! plugin_setting('pixel_tracking.server_tracking', true)) {
                return;
            }

            $prepared = app(ServerTrackingService::class)->prepareCustomerRegistration($customer, request());
            if ($prepared === null) {
                return;
            }

            PixelEventDispatcher::dispatch($prepared);
        });
    }
}
