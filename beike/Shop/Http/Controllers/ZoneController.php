<?php

/**
 * ZoneController.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-04 16:21:14
 * @modified   2022-07-04 16:21:14
 */

namespace Beike\Shop\Http\Controllers;

use Beike\Models\Country;
use Beike\Repositories\ZoneRepo;
use Illuminate\Http\Request;

class ZoneController extends Controller
{
    public function index(Request $request, int $countryId)
    {
        $country      = Country::query()->whereKey($countryId)->where('active', true)->first();
        $zones        = $country
            ? ZoneRepo::listByCountry($countryId)->map->only(['id', 'name', 'code'])->values()
            : collect();
        $callingCodes = GeoIpController::callingCodes();

        $data = [
            'zones'   => $zones,
            'country' => [
                'id'           => $country?->id,
                'name'         => $country?->name                                             ?? '',
                'code'         => $country?->code                                             ?? '',
                'calling_code' => $callingCodes[strtoupper((string) ($country?->code ?? ''))] ?? '',
            ],
        ];

        $data = hook_filter('zone.index.data', $data);

        return json_success(trans('common.success'), $data);
    }
}
