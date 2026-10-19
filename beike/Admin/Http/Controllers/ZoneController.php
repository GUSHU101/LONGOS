<?php

/**
 * ZoneController.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-06-30 16:17:04
 * @modified   2022-06-30 16:17:04
 */

namespace Beike\Admin\Http\Controllers;

use Beike\Admin\Services\LocationManagementService;
use Beike\Repositories\CountryRepo;
use Beike\Repositories\ZoneRepo;
use Beike\Services\LocationCatalogExclusionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class ZoneController extends Controller
{
    public function index(Request $request)
    {
        $zones = ZoneRepo::list($request->all());

        $data = [
            'zones'     => $zones,
            'countries' => CountryRepo::all(),
        ];

        $data = hook_filter('admin.zone.index.data', $data);

        if ($request->expectsJson()) {
            return json_success(trans('common.success'), $data);
        }

        return view('admin::pages.zones.index', $data);
    }

    public function store(Request $request)
    {
        $zone = ZoneRepo::create($this->validatedPayload($request));
        $zone->loadMissing('country:id,code');
        app(LocationCatalogExclusionService::class)->restoreZone((string) ($zone->country?->code ?? ''), (string) $zone->code);

        return json_success(trans('common.created_success'), $zone);
    }

    public function update(Request $request, int $id)
    {
        $zone = ZoneRepo::update($id, $this->validatedPayload($request, $id));
        $zone->load('country');

        return json_success(trans('common.updated_success'), $zone);
    }

    public function destroy(int $id)
    {
        ZoneRepo::delete($id);

        return json_success(trans('common.deleted_success'));
    }

    public function batch(Request $request, LocationManagementService $locations): JsonResponse
    {
        $validated = $request->validate([
            'ids'                => ['sometimes', 'array', 'min:1', 'max:5000'],
            'ids.*'              => ['required', 'integer', 'distinct', 'min:1'],
            'action'             => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'all_filtered'       => ['sometimes', 'boolean'],
            'filters'            => ['sometimes', 'array'],
            'filters.name'       => ['nullable', 'string', 'max:64'],
            'filters.code'       => ['nullable', 'string', 'max:16'],
            'filters.active'     => ['nullable', Rule::in(['', '0', '1', 0, 1])],
            'filters.country_id' => ['nullable', 'integer', 'min:1', 'exists:countries,id'],
        ]);
        if (empty($validated['all_filtered']) && empty($validated['ids'])) {
            throw ValidationException::withMessages(['ids' => trans('admin/zone.select_required')]);
        }
        Gate::authorize($validated['action'] === 'delete' ? 'zones_delete' : 'zones_update');

        $ids = ! empty($validated['all_filtered'])
            ? ZoneRepo::getBuilder(array_filter($validated['filters'] ?? [], static fn ($value) => $value !== '' && $value !== null))
                ->limit(5000)->pluck('zones.id')->all()
            : $validated['ids'];
        if ($ids === []) {
            throw ValidationException::withMessages(['ids' => trans('admin/zone.no_matching_records')]);
        }
        $result = $locations->batchZones($ids, $validated['action']);

        return json_success(trans('admin/zone.batch_result', [
            'processed' => $result['processed'],
            'skipped'   => $result['skipped'],
        ]), $result);
    }

    public function sync(LocationManagementService $locations): JsonResponse
    {
        try {
            // Ensure newly shipped countries exist before resolving province
            // ownership. Existing status/sort preferences remain untouched.
            $locations->syncCountries();
            $result = $locations->syncZones();
        } catch (Throwable $exception) {
            report($exception);

            return json_fail(trans('admin/zone.sync_failed'));
        }

        return json_success(trans('admin/zone.sync_result', $result), $result);
    }

    public function listByCountry(Request $request, int $countryId)
    {
        $active = $request->get('active', false);

        $data = [
            'zones' => ZoneRepo::listByCountry($countryId, $active), // 管理后台显示所有zones
        ];

        return json_success(trans('common.success'), $data);
    }

    private function validatedPayload(Request $request, ?int $id = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        return $request->validate([
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'name'       => ['required', 'string', 'max:64'],
            'code'       => [
                'required', 'string', 'max:16', 'regex:/^[A-Z0-9-]+$/',
                Rule::unique('zones', 'code')->where(fn ($query) => $query->where('country_id', (int) $request->input('country_id')))->ignore($id),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active'     => ['required', 'boolean'],
        ]);
    }
}
