<?php

/**
 * PaymentService.php
 *
 * @copyright  2023 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2023-11-10 17:37:52
 * @modified   2023-11-10 17:37:52
 */

namespace Beike\Services;

class PaymentMethodService
{
    public static function getCurrentMethod($payments, $methodCode): ?array
    {
        if (empty($payments) || empty($methodCode)) {
            return null;
        }
        foreach ($payments as $payment) {
            if (is_array($payment) && ($payment['code'] ?? null) === $methodCode) {
                return $payment;
            }
        }

        return null;
    }
}
