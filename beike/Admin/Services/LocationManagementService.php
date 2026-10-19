<?php

namespace Beike\Admin\Services;

use Beike\Models\Country;
use Beike\Models\Zone;
use Beike\Repositories\CountryRepo;
use Beike\Repositories\ZoneRepo;
use Beike\Services\LocationCatalogExclusionService;
use Beike\Services\LocationIntegrityService;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\ZonesSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class LocationManagementService
{
    /**
     * @param array<int, int> $ids
     * @return array{requested:int,processed:int,skipped:int,failures:array<int,array{id:int,name:string,reason:string}>}
     */
    public function batchCountries(array $ids, string $action): array
    {
        return $this->batch(
            Country::query()->whereKey($ids)->pluck('name', 'id')->all(),
            $ids,
            $action,
            static function (int $id, string $action): void {
                match ($action) {
                    'enable'  => CountryRepo::setActive($id, true),
                    'disable' => CountryRepo::setActive($id, false),
                    'delete'  => CountryRepo::delete($id),
                };
            },
        );
    }

    /**
     * @param array<int, int> $ids
     * @return array{requested:int,processed:int,skipped:int,failures:array<int,array{id:int,name:string,reason:string}>}
     */
    public function batchZones(array $ids, string $action): array
    {
        return $this->batch(
            Zone::query()->whereKey($ids)->pluck('name', 'id')->all(),
            $ids,
            $action,
            static function (int $id, string $action): void {
                match ($action) {
                    'enable'  => ZoneRepo::setActive($id, true),
                    'disable' => ZoneRepo::setActive($id, false),
                    'delete'  => ZoneRepo::delete($id),
                };
            },
        );
    }

    /**
     * Synchronize the release-bundled ISO catalog without changing an
     * existing administrator's active state or sort order.
     *
     * @return array{created:int,updated:int,unchanged:int}
     */
    public function syncCountries(): array
    {
        $items    = app(CountriesSeeder::class)->getItems();
        $stats    = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        $excluded = app(LocationCatalogExclusionService::class)->countryCodes();

        DB::transaction(function () use ($items, $excluded, &$stats): void {
            foreach ($items as $item) {
                $code     = strtoupper(trim((string) ($item['code'] ?? '')));
                if (in_array($code, $excluded, true)) {
                    $stats['unchanged']++;

                    continue;
                }
                $existing = Country::query()->where('code', $code)->first();
                $payload  = [
                    'name'       => trim((string) ($item['name'] ?? '')),
                    'code'       => $code,
                    'continent'  => ($item['continent'] ?? 'null') === 'none' ? 'null' : ($item['continent'] ?? 'null'),
                    'sort_order' => (int) ($item['sort_order'] ?? 0),
                    'active'     => (bool) ($item['active'] ?? true),
                ];

                if (! $existing) {
                    $sourceId = (int) ($item['id'] ?? 0);
                    if ($sourceId > 0 && ! Country::query()->whereKey($sourceId)->exists()) {
                        $payload['id'] = $sourceId;
                    }
                    $country = new Country;
                    $country->forceFill($payload)->saveOrFail();
                    $stats['created']++;

                    continue;
                }

                // IDs identify records originally shipped by LONGOS. Custom
                // records that happen to reuse an ISO code keep their name.
                $canonical = (int) $existing->id === (int) ($item['id'] ?? 0)
                    ? ['name' => $payload['name'], 'continent' => $payload['continent']]
                    : [];
                if ($canonical !== [] && $existing->only(array_keys($canonical)) !== $canonical) {
                    $existing->update($canonical);
                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }
            }
        }, 3);

        app(LocationIntegrityService::class)->repair();

        return $stats;
    }

    /** @return array{created:int,updated:int,unchanged:int,skipped:int} */
    public function syncZones(): array
    {
        $items           = app(ZonesSeeder::class)->getItems();
        $countries       = Country::query()->pluck('id', 'code');
        $stats           = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        $sourceCountries = collect(app(CountriesSeeder::class)->getItems())->keyBy('id');
        $exclusions      = app(LocationCatalogExclusionService::class);
        $excludedZones   = $exclusions->zoneKeys();

        DB::transaction(function () use ($items, $countries, $sourceCountries, $exclusions, $excludedZones, &$stats): void {
            foreach ($items as $item) {
                $sourceCountry = $sourceCountries->get((int) ($item['country_id'] ?? 0));
                $countryCode   = strtoupper(trim((string) ($sourceCountry['code'] ?? '')));
                $countryId     = (int) ($countries[$countryCode] ?? 0);
                $code          = strtoupper(trim((string) ($item['code'] ?? '')));
                if (in_array($exclusions->zoneKey($countryCode, $code), $excludedZones, true)) {
                    $stats['skipped']++;

                    continue;
                }
                if ($countryId < 1 || $code === '') {
                    $stats['skipped']++;

                    continue;
                }

                $existing = Zone::query()->where('country_id', $countryId)->where('code', $code)->first();
                $name     = html_entity_decode((string) ($item['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $payload  = [
                    'country_id' => $countryId,
                    'name'       => $name,
                    'code'       => $code,
                    'sort_order' => (int) ($item['sort_order'] ?? 0),
                    'active'     => (bool) ($item['active'] ?? true),
                ];

                if (! $existing) {
                    $sourceId = (int) ($item['id'] ?? 0);
                    if ($sourceId > 0 && ! Zone::query()->whereKey($sourceId)->exists()) {
                        $payload['id'] = $sourceId;
                    }
                    $zone = new Zone;
                    $zone->forceFill($payload)->saveOrFail();
                    $stats['created']++;

                    continue;
                }

                if ((int) $existing->id === (int) ($item['id'] ?? 0) && $existing->name !== $name) {
                    $existing->update(['name' => $name]);
                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }
            }
        }, 3);

        app(LocationIntegrityService::class)->repair();

        return $stats;
    }

    /**
     * @param array<int|string, string> $names
     * @param array<int, int>           $ids
     * @return array{requested:int,processed:int,skipped:int,failures:array<int,array{id:int,name:string,reason:string}>}
     */
    private function batch(array $names, array $ids, string $action, callable $operation): array
    {
        $ids    = array_values(array_unique(array_map('intval', $ids)));
        $result = ['requested' => count($ids), 'processed' => 0, 'skipped' => 0, 'failures' => []];

        foreach ($ids as $id) {
            if (! isset($names[$id])) {
                $result['skipped']++;
                $result['failures'][] = ['id' => $id, 'name' => "#{$id}", 'reason' => trans('admin/country.record_not_found')];

                continue;
            }

            try {
                DB::transaction(fn () => $operation($id, $action), 3);
                $result['processed']++;
            } catch (ValidationException $e) {
                $result['skipped']++;
                $result['failures'][] = ['id' => $id, 'name' => (string) $names[$id], 'reason' => collect($e->errors())->flatten()->first() ?: $e->getMessage()];
            } catch (ModelNotFoundException) {
                $result['skipped']++;
                $result['failures'][] = ['id' => $id, 'name' => (string) $names[$id], 'reason' => trans('admin/country.record_not_found')];
            } catch (Throwable $e) {
                Log::error('Location batch operation failed.', [
                    'type'      => array_is_list($names) ? 'unknown' : 'location',
                    'id'        => $id,
                    'action'    => $action,
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
                $result['skipped']++;
                $result['failures'][] = ['id' => $id, 'name' => (string) $names[$id], 'reason' => trans('admin/country.operation_failed')];
            }
        }

        $result['failures'] = array_slice($result['failures'], 0, 50);

        return $result;
    }
}
