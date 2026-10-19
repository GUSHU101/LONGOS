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

namespace Plugin\Paypal;

class Bootstrap
{
    public function boot(): void
    {
        $this->adminSettingPage();
    }

    private function adminSettingPage(): void
    {
        add_hook_blade('admin.plugin.form', function ($callback, $output, $data) {
            $code = $data['plugin']->code;
            if ($code == 'paypal') {
                return view('Paypal::admin.config_form', $data)->render();
            }

            return $output;
        }, 1);
    }
}
