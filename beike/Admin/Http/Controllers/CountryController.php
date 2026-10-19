<?php

/**
 * CountryController.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-08-29 16:17:04
 * @modified   2022-08-29 16:17:04
 */

namespace Beike\Admin\Http\Controllers;

use Beike\Admin\Http\Resources\CountryResource;
use Beike\Admin\Services\LocationManagementService;
use Beike\Models\Country;
use Beike\Repositories\CountryRepo;
use Beike\Services\LocationCatalogExclusionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CountryController extends Controller
{
    public function index(Request $request)
    {
        $countries = CountryRepo::list($request->all());

        $data = [
            'countries'         => $countries,
            'countries_format'  => CountryResource::collection($countries)->jsonSerialize(),
            'continents'        => CountryRepo::getContinents(),
        ];

        $data = hook_filter('admin.country.index.data', $data);
        if ($request->expectsJson()) {
            return json_success(trans('common.success'), $data);
        }

        return view('admin::pages.country.index', $data);
    }

    public function store(Request $request)
    {
        $country = CountryRepo::create($this->validatedPayload($request));
        app(LocationCatalogExclusionService::class)->restoreCountry((string) $country->code);

        hook_action('admin.country.store.after', $country);

        return json_success(trans('common.created_success'), $country);
    }

    public function update(Request $request, int $id)
    {
        $country = CountryRepo::update($id, $this->validatedPayload($request, $id));

        hook_action('admin.country.update.after', $country);

        return json_success(trans('common.updated_success'), $country);
    }

    public function destroy(int $id)
    {
        CountryRepo::delete($id);

        hook_action('admin.country.destroy.after', $id);

        return json_success(trans('common.deleted_success'));
    }

    public function batch(Request $request, LocationManagementService $locations): JsonResponse
    {
        $validated = $request->validate([
            'ids'               => ['sometimes', 'array', 'min:1', 'max:500'],
            'ids.*'             => ['required', 'integer', 'distinct', 'min:1'],
            'action'            => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'all_filtered'      => ['sometimes', 'boolean'],
            'filters'           => ['sometimes', 'array'],
            'filters.name'      => ['nullable', 'string', 'max:64'],
            'filters.code'      => ['nullable', 'string', 'max:16'],
            'filters.active'    => ['nullable', Rule::in(['', '0', '1', 0, 1])],
            'filters.continent' => ['nullable', Rule::in([...Country::CONTINENTS, ''])],
        ]);
        if (empty($validated['all_filtered']) && empty($validated['ids'])) {
            throw ValidationException::withMessages(['ids' => trans('admin/country.select_required')]);
        }
        Gate::authorize($validated['action'] === 'delete' ? 'countries_delete' : 'countries_update');

        $ids = ! empty($validated['all_filtered'])
            ? CountryRepo::getBuilder(array_filter($validated['filters'] ?? [], static fn ($value) => $value !== '' && $value !== null))
                ->limit(500)->pluck('id')->all()
            : $validated['ids'];
        if ($ids === []) {
            throw ValidationException::withMessages(['ids' => trans('admin/country.no_matching_records')]);
        }
        $result = $locations->batchCountries($ids, $validated['action']);

        return json_success(trans('admin/country.batch_result', [
            'processed' => $result['processed'],
            'skipped'   => $result['skipped'],
        ]), $result);
    }

    public function sync(LocationManagementService $locations): JsonResponse
    {
        try {
            $result = $locations->syncCountries();
        } catch (Throwable $exception) {
            report($exception);

            return json_fail(trans('admin/country.sync_failed'));
        }

        return json_success(trans('admin/country.sync_result', $result), $result);
    }

    private function validatedPayload(Request $request, ?int $id = null): array
    {
        $request->merge([
            'code'      => strtoupper(trim((string) $request->input('code'))),
            'continent' => $request->input('continent', 'null') ?: 'null',
        ]);

        return $request->validate([
            'name'       => ['required', 'string', 'max:64'],
            'code'       => ['required', 'string', 'regex:/^[A-Z]{2}$/', Rule::unique('countries', 'code')->ignore($id)],
            'continent'  => ['required', 'string', Rule::in(Country::CONTINENTS)],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'active'     => ['required', 'boolean'],
        ]);
    }
}
