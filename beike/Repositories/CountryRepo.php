<?php

/**
 * CountryRepo.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-06-30 15:22:05
 * @modified   2022-06-30 15:22:05
 */

namespace Beike\Repositories;

use Beike\Models\Address;
use Beike\Models\Country;
use Beike\Models\Order;
use Beike\Models\RegionZone;
use Beike\Models\Zone;
use Beike\Services\LocationCatalogExclusionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CountryRepo
{
    /**
     * @param $data
     * @return array
     */
    private static function handleParams($data)
    {
        return [
            'name'       => $data['name']             ?? '',
            'code'       => strtoupper(trim($data['code'] ?? '')),
            'continent'  => $data['continent'] ?? 'null',
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'active'     => (bool) ($data['active']    ?? true),
        ];
    }

    /**
     * 创建一个country记录
     * @param $data
     * @return mixed
     */
    public static function create($data)
    {
        $data = self::handleParams($data);

        return Country::query()->create($data);
    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     * @throws \Exception
     */
    public static function update($id, $data)
    {
        $data    = self::handleParams($data);
        $country = Country::query()->find($id);
        if (! $country) {
            throw new \Exception("国家id {$id} 不存在");
        }
        if (! $data['active'] && (int) system_setting('base.country_id') === (int) $country->id) {
            throw ValidationException::withMessages(['country' => trans('admin/country.default_cannot_disable')]);
        }
        $country->update($data);

        return $country;
    }

    /**
     * @param $id
     * @return mixed
     */
    public static function find($id)
    {
        return Country::query()->find($id);
    }

    /**
     * @param $id
     * @return void
     */
    public static function delete($id)
    {
        DB::transaction(function () use ($id): void {
            $country = Country::query()->lockForUpdate()->find($id);
            if (! $country) {
                return;
            }
            if ((int) system_setting('base.country_id') === (int) $country->id) {
                throw ValidationException::withMessages(['country' => trans('admin/country.default_cannot_delete')]);
            }

            $zoneIds = Zone::query()->where('country_id', $country->id)->lockForUpdate()->pluck('id');
            if ($zoneIds->contains((int) system_setting('base.zone_id'))) {
                throw ValidationException::withMessages(['country' => trans('admin/country.contains_default_zone')]);
            }

            $inUse = Address::query()->where('country_id', $country->id)
                ->when($zoneIds->isNotEmpty(), fn ($query) => $query->orWhereIn('zone_id', $zoneIds))
                ->exists()
                || Order::query()->where(function ($query) use ($country, $zoneIds): void {
                    $query->where('shipping_country_id', $country->id)
                        ->orWhere('payment_country_id', $country->id);
                    if ($zoneIds->isNotEmpty()) {
                        $query->orWhereIn('shipping_zone_id', $zoneIds)
                            ->orWhereIn('payment_zone_id', $zoneIds);
                    }
                })->exists()
                || RegionZone::query()->where('country_id', $country->id)
                    ->when($zoneIds->isNotEmpty(), fn ($query) => $query->orWhereIn('zone_id', $zoneIds))
                    ->exists();

            if ($inUse) {
                throw ValidationException::withMessages(['country' => trans('admin/country.in_use_cannot_delete')]);
            }

            // Deleting a country is an explicit destructive action. Its
            // unreferenced child provinces can be removed atomically so an
            // administrator does not have to delete hundreds one by one.
            Zone::query()->where('country_id', $country->id)->delete();
            app(LocationCatalogExclusionService::class)->excludeCountry((string) $country->code);
            $country->delete();
        });
    }

    public static function setActive(int $id, bool $active): Country
    {
        $country = Country::query()->findOrFail($id);
        if (! $active && (int) system_setting('base.country_id') === (int) $country->id) {
            throw ValidationException::withMessages(['country' => trans('admin/country.default_cannot_disable')]);
        }
        $country->update(['active' => $active]);

        return $country;
    }

    /**
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public static function list(array $filters = []): LengthAwarePaginator
    {
        $builder = self::getBuilder($filters);

        return $builder->paginate(perPage())->withQueryString();
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public static function getBuilder(array $filters = []): Builder
    {
        $builder = Country::query();

        if (isset($filters['name'])) {
            $builder->where('countries.name', 'like', "%{$filters['name']}%");
        }
        if (isset($filters['code'])) {
            $builder->where('countries.code', 'like', "%{$filters['code']}%");
        }
        if (isset($filters['active'])) {
            $builder->where('countries.active', $filters['active']);
        }
        if (isset($filters['continent'])) {
            $builder->where('countries.continent', $filters['continent']);
        }

        return $builder;
    }

    /**
     * 获取已启用国家列表
     * @return Builder[]|Collection
     */
    public static function listEnabled(): Collection|array
    {
        $countries = Country::query()->where('active', true)->select('id', 'name', 'code')->orderBy('sort_order')->orderBy('name')->get();

        return hook_filter('repo.country.list.enabled', $countries);
    }

    /**
     * 获取所有国家列表
     * @return Builder[]|Collection
     */
    public static function all()
    {
        return Country::query()->select('id', 'name', 'code')->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * @return array
     */
    public static function getContinents(): array
    {
        $result = [];
        foreach (Country::CONTINENTS as $continent) {
            $result[] = [
                'code'  => $continent,
                'label' => trans("country.{$continent}"),
            ];
        }

        return $result;
    }
}
