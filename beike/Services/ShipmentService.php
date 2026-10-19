<?php

/**
 * ShipmentService.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-12-20 17:23:51
 * @modified   2022-12-20 17:23:51
 */

namespace Beike\Services;

use Beike\Models\OrderShipment;

class ShipmentService
{
    /**
     * 处理订单运单数据
     *
     * @param $expressCode
     * @param $expressNumber
     * @return array
     */
    public static function handleShipment($expressCode, $expressNumber): array
    {
        if (empty($expressCode) || empty($expressNumber)) {
            return [];
        }

        $expressCompany = self::handleExpressCompany($expressCode);
        if (empty($expressCompany)) {
            return [];
        }

        return [
            'express_code'    => $expressCode,
            'express_company' => $expressCompany,
            'express_number'  => $expressNumber,
        ];
    }

    /**
     * 根据快递公司编号获取快递公司名称
     *
     * @param $expressCode
     * @return mixed
     */
    public static function handleExpressCompany($expressCode): mixed
    {
        $expressCompanies = system_setting('base.express_company', []);
        if (! is_array($expressCompanies) || empty($expressCompanies)) {
            return '';
        }
        $company = collect($expressCompanies)->where('code', $expressCode)->first();

        return $company ? $company['name'] ?? '' : '';
    }

    /**
     * Build a carrier-hosted live tracking URL without trusting arbitrary
     * schemes or HTML from settings. Plugins may override the candidate URL
     * through the standard shipment tracking hook.
     */
    public static function trackingUrl(OrderShipment $shipment): ?string
    {
        $companies = system_setting('base.express_company', []);
        $companies = is_array($companies) ? $companies : [];
        $company   = collect($companies)->firstWhere('code', $shipment->express_code);
        $template  = is_array($company) ? trim((string) ($company['tracking_url'] ?? '')) : '';
        $candidate = str_contains($template, '{tracking_number}')
            ? str_replace('{tracking_number}', rawurlencode((string) $shipment->express_number), $template)
            : '';

        $filtered = hook_filter('service.shipment.tracking_url', [
            'url'      => $candidate,
            'shipment' => $shipment,
        ]);

        if (is_string($filtered)) {
            return self::safeTrackingUrl($filtered);
        }

        return self::safeTrackingUrl(is_array($filtered) ? (string) ($filtered['url'] ?? '') : '');
    }

    private static function safeTrackingUrl(string $url): ?string
    {
        if ($url === '' || strlen($url) > 1000 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || (int) ($parts['port'] ?? 443) !== 443
        ) {
            return null;
        }

        return $url;
    }

    public static function addShipment($orderId, $shipment)
    {
        $expressCode    = $shipment['express_code']    ?? '';
        $expressCompany = $shipment['express_company'] ?? '';
        $expressNumber  = $shipment['express_number']  ?? '';
        if ($expressCode && $expressCompany && $expressNumber) {
            $orderShipment = new OrderShipment([
                'order_id'        => $orderId,
                'express_code'    => $expressCode,
                'express_company' => $expressCompany,
                'express_number'  => $expressNumber,
            ]);
            $orderShipment->saveOrFail();
        }
    }

    /**
     * @param             $orderShipment
     * @param             $data
     * @throws \Throwable
     */
    public static function updateShipment($orderShipment, $data)
    {
        $shipmentData = [
            'express_code'      => $data['express_code']    ?? '',
            'express_company'   => $data['express_name']    ?? '',
            'express_number'    => $data['express_number']  ?? '',
        ];
        $orderShipment->updateOrFail($shipmentData);
    }
}
