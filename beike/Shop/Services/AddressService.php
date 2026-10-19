<?php

/**
 * AddressService.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-04 18:52:57
 * @modified   2022-07-04 18:52:57
 */

namespace Beike\Shop\Services;

use Beike\Models\Country;
use Beike\Models\Zone;
use Beike\Repositories\AddressRepo;
use Illuminate\Validation\ValidationException;

class AddressService
{
    public static function create($data)
    {
        $customer            = current_customer();
        $data['customer_id'] = $customer->id;
        self::normalizeLocation($data);
        $address             = AddressRepo::create($data);

        if ((bool) ($data['default'] ?? false)) {
            $customer->address_id = $address->id;
            $customer->save();
        }

        return $address;
    }

    public static function update($id, $data)
    {
        $customer = current_customer();
        $address  = AddressRepo::find($id);
        if (! $address || $address->customer_id != $customer->id) {
            return null;
        }
        self::normalizeLocation($data);
        $address      = AddressRepo::update($address, $data);
        if ((bool) ($data['default'] ?? false) && $customer->address_id != $address->id) {
            $customer->address_id = $address->id;
            $customer->save();
        }

        return $address;
    }

    /**
     * Keep persisted address relations consistent even when another caller or
     * a plugin bypasses the storefront form request.
     */
    private static function normalizeLocation(array &$data): void
    {
        $countryId = (int) ($data['country_id'] ?? 0);
        $country   = Country::query()->whereKey($countryId)->where('active', true)->first();
        if (! $country) {
            throw ValidationException::withMessages(['country_id' => trans('address.country_id')]);
        }

        $zoneId = (int) ($data['zone_id'] ?? 0);
        $zone   = $zoneId > 0
            ? Zone::query()->whereKey($zoneId)->where('country_id', $countryId)->where('active', true)->first()
            : null;
        $countryHasZones = Zone::query()->where('country_id', $countryId)->where('active', true)->exists();
        if ($countryHasZones && ! $zone) {
            throw ValidationException::withMessages(['zone_id' => trans('address.zone_id')]);
        }

        $data['zone_id'] = $zone?->id   ?? 0;
        $data['zone']    = $zone?->name ?? '';
    }
}
