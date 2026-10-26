<?php

/**
 * Bootstrap.php
 *
 * @copyright  2023 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2023-08-17 15:15:27
 * @modified   2023-08-17 15:15:27
 */

namespace Plugin\Stripe;

class Bootstrap
{
    public function boot(): void
    {
        add_hook_blade('checkout.bottom', function ($callback, $output, $data) {
            return $output . view('Stripe::checkout.inline')->render();
        });
    }
}
