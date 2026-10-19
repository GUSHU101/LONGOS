<?php

namespace Beike\Admin\Http\Controllers;

use Beike\Admin\Services\DesignEditorCatalog;
use Beike\Models\AdminUser;
use Beike\Models\Category;
use Beike\Models\Page;
use Beike\Models\Product;
use Beike\Models\Setting;
use Beike\Repositories\SettingRepo;
use Beike\Services\BuilderStateService;
use Beike\Services\DesignService;
use Beike\Services\PuckDataAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DesignController extends Controller
{
    /**
     * 展示所有模块编辑器
     *
     * @param Request $request
     * @return View
     */
    public function index(Request $request): View
    {
        $publishedState  = BuilderStateService::published();
        $draftState      = BuilderStateService::draft();
        $footerSettings  = $draftState['footer'];
        $headerSettings  = $draftState['header'];
        $menuSettings    = $draftState['menu'];
        $previewProduct  = Product::query()->where('active', true)->orderBy('id')->value('id');
        $previewCategory = Category::query()->where('active', true)->orderBy('id')->value('id');
        $previewPage     = Page::query()->where('active', true)->orderBy('id')->value('id');
        $designSettings  = $draftState['design'];
        // Migrated Shopify sections are deliberately not part of the Builder
        // inventory. Their source artifacts and storefront renderers remain
        // available solely to keep historical storefront data recoverable.
        // A saved old section therefore enters recovery mode instead of being
        // offered as a new, unsupported editing choice.
        $shopifyUnits    = [];
        $shopifyRegistry = [];
        $data            = [
            'design_settings' => $designSettings,
            // Puck-compatible data crosses a strict adapter boundary while
            // the persisted design schema remains editor-library neutral.
            'puck_data'        => PuckDataAdapter::toPuck($designSettings),
            'shopify_units'    => $shopifyUnits,
            'shopify_registry' => $shopifyRegistry,
            // Visual-editor catalog extension point. Plugins should add plain
            // serializable module definitions here instead of injecting a Vue
            // component or executable editor script.
            'editor_catalog'  => DesignEditorCatalog::core(),
            // App embeds are outside the section tree, matching Shopify's
            // theme editor model. Plugins may add data-only definitions here.
            'editor_app_embeds' => [],
            // Link types are data-only too. An extension can register a
            // storefront destination without injecting a legacy Vue selector.
            'editor_link_types'       => $this->defaultEditorLinkTypes(),
            'footer_settings'         => $footerSettings,
            'header_settings'         => $headerSettings,
            'menu_settings'           => $menuSettings,
            'draft_version'           => $draftState['version'],
            'has_unpublished_changes' => BuilderStateService::hasUnpublishedChanges($draftState, $publishedState),
            'product_preview_url'     => $previewProduct
                // Do not use the historical iframe=1 embed mode here: the
                // storefront layout intentionally omits header/footer in
                // that mode, while the Builder preview must show the entire
                // shopper-facing page chrome.
                ? shop_route('products.show', ['product' => $previewProduct]) . '?design=1'
                : url('/?design=1'),
            'category_preview_url' => $previewCategory
                ? shop_route('categories.show', ['category' => $previewCategory]) . '?design=1'
                : url('/?design=1'),
            'page_preview_url' => $previewPage
                ? shop_route('pages.show', ['page' => $previewPage]) . '?design=1'
                : url('/?design=1'),
            // The visual editor uses the existing, permission-aware media
            // manager rather than asking administrators to paste image URLs.
            'file_upload_url'  => admin_route('file.store'),
            'file_manager_url' => admin_route('file_manager.index') . '?mime=image&is_multiple=0',
            // Keep video selection on the same permission-aware manager, but
            // ask it to reject non-video records before the selection reaches
            // a Builder field.
            'video_file_manager_url' => admin_route('file_manager.index') . '?mime=video&is_multiple=0',
            'storefront_base_url'    => url('/'),
            'storefront_preview_url' => url('/?design=1'),
            'navigation_editor_url'  => admin_route('design.navigation.index'),
            // The preview iframe loads page chrome but dynamically inserted
            // module fragments cannot register their own asset directives.
            // Expose the already trusted storefront Swiper assets explicitly
            // so the visual editor can initialise only its known slider types.
            'swiper_style_url'  => asset('vendor/swiper/swiper-bundle.min.css'),
            'swiper_script_url' => asset('vendor/swiper/swiper-bundle.min.js'),
            // Reuse permission-protected admin autocomplete endpoints so the
            // visual editor can select actual catalog records instead of
            // asking merchants to memorize database IDs.
            'entity_autocomplete_urls' => [
                'product'      => admin_route('products.autocomplete'),
                'category'     => admin_route('categories.autocomplete'),
                'brand'        => admin_route('brands.autocomplete'),
                'page'         => admin_route('pages.autocomplete'),
                'pageCategory' => admin_route('page_categories.autocomplete'),
            ],
        ];

        $data                      = hook_filter('admin.design.index.data', $data);
        $data['editor_catalog']    = DesignEditorCatalog::normalize($data['editor_catalog'] ?? []);
        $data['editor_app_embeds'] = DesignEditorCatalog::normalizeAppEmbeds($data['editor_app_embeds'] ?? []);
        $data['editor_link_types'] = $this->normalizeEditorLinkTypes($data['editor_link_types'] ?? []);
        $embedState                = is_array($data['puck_data']['root']['props']['appEmbeds'] ?? null)
            ? $data['puck_data']['root']['props']['appEmbeds'] : [];
        foreach ($data['editor_app_embeds'] as $embed) {
            if (! isset($embedState[$embed['code']]) || ! is_array($embedState[$embed['code']])) {
                $embedState[$embed['code']] = ['enabled' => $embed['default_enabled']];
            }
        }
        $data['puck_data']['root']['props']['appEmbeds'] = $embedState;

        // The React + Puck builder is the sole editing surface.
        return view('admin::pages.design.builder.puck', $data);
    }

    /**
     * 更新所有数据
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $content = $this->decodeDesignPayload($request);
        if ($content === null) {
            return json_fail('Invalid design payload.');
        }
        [$moduleData, $footer, $header, $menu] = $this->normalizeDesignPayload($content);
        $publish                               = ($content['publish'] ?? false) === true;
        $expectedVersion                       = $this->expectedDraftVersion($request);
        $force                                 = $request->boolean('force') || $request->header('X-Builder-Force') === '1';
        $submittedState                        = array_filter([
            'design' => $moduleData,
            'footer' => $footer,
            'header' => $header,
            'menu'   => $menu,
        ], static fn (mixed $value): bool => $value !== null);

        try {
            $result = DB::transaction(function () use ($submittedState, $publish, $expectedVersion, $force): array {
                $currentDraft = $this->lockCurrentDraft();
                if (! $force && ($expectedVersion === null || $expectedVersion !== $currentDraft['version'])) {
                    return ['conflict' => true, 'draft' => $currentDraft];
                }

                $nextState = BuilderStateService::normalize($submittedState, $currentDraft);
                $draft     = BuilderStateService::makeDraft($nextState, $currentDraft['version'] + 1, $this->currentAdminId());
                SettingRepo::storeValue(BuilderStateService::DRAFT_SETTING, $draft, 'base', 'system', false);

                if ($publish) {
                    SettingRepo::storeValue('design_setting', $draft['design'], 'base', 'system', false);
                    SettingRepo::storeValue('footer_setting', $draft['footer'], 'base', 'system', false);
                    SettingRepo::storeValue('header_setting', $draft['header'], 'base', 'system', false);
                    SettingRepo::storeValue('menu_setting', $draft['menu'], 'base', 'system', false);
                    $persisted = $this->readPersistedPublishedState();
                    if (! hash_equals(
                        BuilderStateService::contentHash($draft),
                        BuilderStateService::contentHash($persisted)
                    )) {
                        throw new \RuntimeException('The published Builder state could not be verified after writing.');
                    }
                }

                return [
                    'conflict'                => false,
                    'draft'                   => $draft,
                    'has_unpublished_changes' => ! $publish
                        && BuilderStateService::hasUnpublishedChanges($draft, BuilderStateService::published()),
                ];
            });
        } catch (\Throwable $exception) {
            report($exception);
            logger()->error('Design editor settings could not be saved.', [
                'exception' => $exception->getMessage(),
            ]);

            return json_fail('装修设置保存失败，请刷新页面后重试。');
        }

        if ($result['conflict']) {
            return json_fail('检测到其他管理员保存了更新版本。为防止覆盖，其更改尚未被写入。', [
                'draft_version' => $result['draft']['version'],
            ], 409);
        }

        $revisionCreated = false;
        $cacheRefreshed  = true;
        if ($publish) {
            // A full history row is a recovery aid, not a prerequisite for
            // making the storefront live. Keeping it outside the publishing
            // transaction prevents an old TEXT column or an oversized history
            // from rolling back settings which were already verified above.
            try {
                $revisionCreated = DB::transaction(function () use ($result): bool {
                    return $this->recordRevision($result['draft']);
                });
            } catch (\Throwable $exception) {
                report($exception);
                logger()->warning('LONGOS Editor published successfully but could not create a revision.', [
                    'exception' => $exception->getMessage(),
                ]);
            }
            $cacheRefreshed = $this->clearPublishedCaches();
        }

        // Extension notifications are not allowed to turn a completed save
        // into an admin 500 page. The persisted settings remain authoritative.
        try {
            hook_action('admin.design.update.after', $result['draft']['design']);
        } catch (\Throwable $exception) {
            report($exception);
            logger()->warning('A design editor post-save hook failed.', [
                'exception' => $exception->getMessage(),
            ]);
        }

        return json_success(trans('common.updated_success'), [
            'draft_version'           => $result['draft']['version'],
            'has_unpublished_changes' => $result['has_unpublished_changes'],
            'published'               => $publish,
            'revision_created'        => $revisionCreated,
            'cache_refreshed'         => $cacheRefreshed,
        ]);
    }

    /**
     * Render one unsaved section for the visual editor without mutating the
     * draft. This is intentionally a bounded, permission-protected fragment
     * endpoint: typing in a field can update the real storefront preview while
     * the normal autosave and optimistic-locking pipeline remains independent.
     */
    public function previewModule(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        if (! is_string($raw) || strlen($raw) > 262_144) {
            return json_fail('模块预览数据不能超过 256KB。', [], 413);
        }

        $item  = $request->input('item');
        $type  = is_array($item) && is_string($item['type'] ?? null) ? trim($item['type']) : '';
        $props = is_array($item) && is_array($item['props'] ?? null) ? $item['props'] : null;
        if (! preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $type) || $props === null) {
            return json_fail('无效的模块预览数据。');
        }

        try {
            $extensionData = hook_filter('admin.design.index.data', [
                'editor_catalog'    => DesignEditorCatalog::core(),
                'editor_app_embeds' => [],
                'editor_link_types' => $this->defaultEditorLinkTypes(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return json_fail('扩展模块目录加载失败，草稿仍可正常保存。');
        }
        $catalog    = DesignEditorCatalog::normalize(is_array($extensionData['editor_catalog'] ?? null) ? $extensionData['editor_catalog'] : []);
        $definition = collect($catalog)->first(static fn (array $candidate): bool => ($candidate['code'] ?? null) === $type);
        if (! is_array($definition)) {
            return json_fail('该模块未在当前主题或已启用插件中注册。');
        }

        $moduleId = is_string($props['id'] ?? null) ? trim($props['id']) : '';
        if (! preg_match('/^[A-Za-z0-9_-]{1,96}$/', $moduleId)) {
            return json_fail('模块标识无效，请重新插入该模块。');
        }

        // Server-owned catalog metadata always wins over browser metadata.
        $submittedMeta     = is_array($props['_builder'] ?? null) ? $props['_builder'] : [];
        $props['_builder'] = [
            'module_id'         => $moduleId,
            'code'              => $type,
            'name'              => $definition['name'],
            'view_path'         => $definition['view_path'],
            'visible'           => ($submittedMeta['visible'] ?? true) !== false,
            'component_version' => $definition['component_version'],
        ];

        try {
            $pageType = $request->input('page', 'home');
            if (! is_string($pageType) || ! in_array($pageType, ['home', 'product', 'category', 'page'], true)) {
                return json_fail('模块预览页面类型无效。');
            }
            $page = PuckDataAdapter::fromPuck([
                'content' => [['type' => $type, 'props' => $props]],
                'root'    => ['props' => ['pageType' => $pageType]],
            ]);
            $module = $page['modules'][0] ?? null;
            if (! is_array($module) || ($module['code'] ?? null) !== $type) {
                return json_fail('模块数据未通过安全校验。');
            }
            // Do not allow advanced JSON or a crafted request to replace the
            // registered renderer selected above.
            $module['name']              = $definition['name'];
            $module['view_path']         = $definition['view_path'];
            $module['component_version'] = $definition['component_version'];
            $rendered                    = DesignService::renderBuilderModule($module);
            if (strlen($rendered['html']) > 524_288) {
                return json_fail('模块预览结果超过 512KB，请减少当前模块内容。', [], 413);
            }

            return json_success('模块预览已更新。', [
                'module_id' => $moduleId,
                'html'      => $rendered['html'],
            ]);
        } catch (\Throwable $exception) {
            report($exception);
            logger()->warning('A Builder live-preview fragment could not be rendered.', [
                'module_id' => $moduleId,
                'code'      => $type,
                'exception' => $exception->getMessage(),
            ]);

            return json_fail('当前模块无法实时渲染，草稿仍可正常保存。');
        }
    }

    /** List bounded, publish-time snapshots without exposing the full page payload. */
    public function revisions(): JsonResponse
    {
        $revisions = system_setting('base.design_revisions', []);
        $revisions = is_array($revisions) ? $revisions : [];
        $summary   = [];
        foreach ($revisions as $revision) {
            if (! is_array($revision) || ! is_int($revision['id'] ?? null)) {
                continue;
            }
            $summary[] = [
                'id'           => $revision['id'],
                'created_at'   => is_string($revision['created_at'] ?? null) ? $revision['created_at'] : '',
                'engine'       => is_string($revision['engine'] ?? null) ? $revision['engine'] : 'puck',
                'module_count' => is_array($revision['state']['design']['modules'] ?? $revision['design']['modules'] ?? null)
                    ? count($revision['state']['design']['modules'] ?? $revision['design']['modules']) : 0,
            ];
        }

        return json_success(trans('common.get_success'), $summary);
    }

    /** Restore a known publish snapshot through the same normalizer as an import. */
    public function restoreRevision(Request $request, int $revision): JsonResponse
    {
        $revisions       = system_setting('base.design_revisions', []);
        $revisions       = is_array($revisions) ? $revisions : [];
        $snapshot        = collect($revisions)->first(static fn (mixed $item): bool => is_array($item) && ($item['id'] ?? null) === $revision);
        $hasLegacyDesign = is_array($snapshot['design'] ?? null);
        $hasStateDesign  = is_array($snapshot['state']['design'] ?? null);
        if (! is_array($snapshot) || (! $hasLegacyDesign && ! $hasStateDesign)) {
            return json_fail('The requested page revision is no longer available.');
        }
        $snapshotState = is_array($snapshot['state'] ?? null)
            ? $snapshot['state']
            : ['design' => $snapshot['design']];
        $expectedVersion = $this->expectedDraftVersion($request);
        $force           = $request->header('X-Builder-Force') === '1';

        try {
            $result = DB::transaction(function () use ($snapshotState, $expectedVersion, $force): array {
                $currentDraft = $this->lockCurrentDraft();
                if (! $force && ($expectedVersion === null || $expectedVersion !== $currentDraft['version'])) {
                    return ['conflict' => true, 'draft' => $currentDraft];
                }
                $restored = BuilderStateService::normalize($snapshotState, BuilderStateService::published());
                $draft    = BuilderStateService::makeDraft($restored, $currentDraft['version'] + 1, $this->currentAdminId());
                SettingRepo::storeValue(BuilderStateService::DRAFT_SETTING, $draft, 'base', 'system', false);

                return [
                    'conflict'                => false,
                    'draft'                   => $draft,
                    'has_unpublished_changes' => BuilderStateService::hasUnpublishedChanges($draft, BuilderStateService::published()),
                ];
            });
        } catch (\Throwable $exception) {
            report($exception);

            return json_fail('Unable to restore this page revision.');
        }

        if ($result['conflict']) {
            return json_fail('草稿已被其他管理员更新，未执行版本恢复。', [
                'draft_version' => $result['draft']['version'],
            ], 409);
        }

        return json_success(trans('common.updated_success'), [
            'draft_version'           => $result['draft']['version'],
            'has_unpublished_changes' => $result['has_unpublished_changes'],
        ]);
    }

    /** Persist only publish-time, valid Builder-domain snapshots. */
    private function recordRevision(array $state): bool
    {
        $encoded = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || strlen($encoded) > 768 * 1024) {
            logger()->warning('Builder publish revision skipped because the normalized state exceeds its bounded snapshot size.');

            return false;
        }
        $revisionSetting = Setting::query()
            ->where('type', 'system')->where('space', 'base')->where('name', 'design_revisions')
            ->lockForUpdate()->first();
        $revisions   = $this->decodeSettingRecord($revisionSetting);
        $revisions   = is_array($revisions) ? $revisions : [];
        $max         = 25;
        $nextId      = max(array_map(static fn (mixed $item): int => is_array($item) ? (int) ($item['id'] ?? 0) : 0, $revisions) ?: [0]) + 1;
        $revisions[] = [
            'id'         => $nextId,
            'created_at' => now()->toIso8601String(),
            'engine'     => $state['design']['engine'] ?? 'puck',
            'state'      => $state,
        ];
        $revisions = array_slice($revisions, -$max);
        // Keep the history below MEDIUMTEXT's practical ceiling even when a
        // merchant builds unusually large pages.
        while (count($revisions) > 1) {
            $historyJson = json_encode($revisions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($historyJson) && strlen($historyJson) <= 12 * 1024 * 1024) {
                break;
            }
            array_shift($revisions);
        }
        SettingRepo::storeValue('design_revisions', $revisions, 'base', 'system', false);

        return true;
    }

    /**
     * Download a portable, versioned backup before a larger theme change.
     * The file includes only design settings—never customer, order, or system data.
     */
    public function export(): StreamedResponse
    {
        $draft   = BuilderStateService::draft();
        $payload = [
            'format'      => 'longos-store-builder',
            'version'     => 5,
            'exported_at' => now()->toIso8601String(),
            'source'      => 'draft',
            'design'      => $draft['design'],
            'header'      => $draft['header'],
            'footer'      => $draft['footer'],
            'menu'        => $draft['menu'],
        ];
        $fileName = 'longos-editor-' . now()->format('Ymd-His') . '.json';

        return response()->streamDownload(static function () use ($payload): void {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }, $fileName, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /** Import a backup generated by the rebuilt editor and return the normalized state. */
    public function import(Request $request): JsonResponse
    {
        $payload = $this->decodeDesignPayload($request);
        if ($payload === null) {
            return json_fail('备份文件无效，请选择由 LONGOS编辑器导出的 JSON 文件。');
        }
        if (($payload['format'] ?? 'longos-store-builder') !== 'longos-store-builder') {
            return json_fail('Unsupported design backup format.');
        }
        $backupVersion = $payload['version'] ?? null;
        if ($backupVersion !== null && (! is_int($backupVersion) || $backupVersion < 1 || $backupVersion > 5)) {
            return json_fail('该装修备份版本与当前 LONGOS 编辑器不兼容。');
        }
        $content = is_array($payload['design'] ?? null) ? $payload['design'] : $payload;
        if (is_array($payload['design'] ?? null)) {
            $content['header'] = $payload['header'] ?? null;
            $content['footer'] = $payload['footer'] ?? null;
            $content['menu']   = $payload['menu']   ?? null;
        }
        [$moduleData, $footer, $header, $menu] = $this->normalizeDesignPayload($content);
        $submittedState                        = array_filter([
            'design' => $moduleData,
            'footer' => $footer,
            'header' => $header,
            'menu'   => $menu,
        ], static fn (mixed $value): bool => $value !== null);
        $expectedVersion = $this->expectedDraftVersion($request);
        $force           = $request->header('X-Builder-Force') === '1';

        try {
            $result = DB::transaction(function () use ($submittedState, $expectedVersion, $force): array {
                $currentDraft = $this->lockCurrentDraft();
                if (! $force && ($expectedVersion === null || $expectedVersion !== $currentDraft['version'])) {
                    return ['conflict' => true, 'draft' => $currentDraft];
                }
                $imported = BuilderStateService::normalize($submittedState, $currentDraft);
                $draft    = BuilderStateService::makeDraft($imported, $currentDraft['version'] + 1, $this->currentAdminId());
                SettingRepo::storeValue(BuilderStateService::DRAFT_SETTING, $draft, 'base', 'system', false);

                return [
                    'conflict'                => false,
                    'draft'                   => $draft,
                    'has_unpublished_changes' => BuilderStateService::hasUnpublishedChanges($draft, BuilderStateService::published()),
                ];
            });
        } catch (\Throwable $exception) {
            report($exception);
            logger()->error('Design editor backup could not be imported.', [
                'exception' => $exception->getMessage(),
            ]);

            return json_fail('装修备份导入失败，请检查文件后重试。');
        }
        if ($result['conflict']) {
            return json_fail('草稿已被其他管理员更新，未导入备份。', [
                'draft_version' => $result['draft']['version'],
            ], 409);
        }

        try {
            hook_action('admin.design.import.after', $result['draft']['design']);
        } catch (\Throwable $exception) {
            report($exception);
            logger()->warning('A design editor post-import hook failed.', [
                'exception' => $exception->getMessage(),
            ]);
        }

        return json_success(trans('common.updated_success'), [
            'design'                  => $result['draft']['design'],
            'header'                  => $result['draft']['header'],
            'footer'                  => $result['draft']['footer'],
            'menu'                    => $result['draft']['menu'],
            'draft_version'           => $result['draft']['version'],
            'has_unpublished_changes' => $result['has_unpublished_changes'],
        ]);
    }

    /**
     * Keep request parsing bounded so a damaged upload cannot exhaust memory.
     */
    private function decodeDesignPayload(Request $request): ?array
    {
        $json = $request->getContent();
        if (! is_string($json) || $json === '' || strlen($json) > 2 * 1024 * 1024) {
            return null;
        }
        $payload = json_decode($json, true, 128);

        return is_array($payload) && ! array_is_list($payload) ? $payload : null;
    }

    /** @return array{0: array, 1: mixed, 2: mixed, 3: mixed} */
    private function normalizeDesignPayload(array $content): array
    {
        if (is_array($content['puck'] ?? null)) {
            $moduleData = PuckDataAdapter::fromPuck($content['puck']);

            return [$moduleData, $content['footer'] ?? null, $content['header'] ?? null, $content['menu'] ?? null];
        }
        $home = $content['home'] ?? $content;
        if (! is_array($home)) {
            $home = [];
        }
        $moduleData                 = DesignService::handleRequestModules($home);
        $moduleData['global_style'] = DesignService::normalizeGlobalStyle(
            $content['global_style'] ?? $home['global_style'] ?? []
        );

        return [$moduleData, $content['footer'] ?? null, $content['header'] ?? null, $content['menu'] ?? null];
    }

    /** Lock and decode the newest draft instead of trusting request-boot config. */
    private function lockCurrentDraft(): array
    {
        // Serialize every Builder writer even before the first draft row exists.
        // The base settings group is created during installation and acts as a
        // cross-database mutex; the draft row itself is then locked below.
        Setting::query()
            ->where('type', 'system')
            ->where('space', 'base')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();
        $setting = Setting::query()
            ->where('type', 'system')
            ->where('space', 'base')
            ->where('name', BuilderStateService::DRAFT_SETTING)
            ->lockForUpdate()
            ->first();
        $stored = $this->decodeSettingRecord($setting);

        return is_array($stored) && is_array($stored['design'] ?? null)
            ? BuilderStateService::normalize($stored, BuilderStateService::published())
            : BuilderStateService::published();
    }

    private function decodeSettingRecord(?Setting $setting): mixed
    {
        if (! $setting) {
            return null;
        }
        if (! $setting->json) {
            return $setting->value;
        }
        $decoded = json_decode((string) $setting->value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function expectedDraftVersion(Request $request): ?int
    {
        $value = $request->header('X-Builder-Draft-Version');
        if (! is_string($value) || ! preg_match('/^\d{1,10}$/', $value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Read the just-written public settings from the database, bypassing the
     * request-scoped configuration snapshot used by system_setting().
     */
    private function readPersistedPublishedState(): array
    {
        $settings = Setting::query()
            ->where('type', 'system')
            ->where('space', 'base')
            ->whereIn('name', ['design_setting', 'header_setting', 'footer_setting', 'menu_setting'])
            ->lockForUpdate()
            ->get()
            ->keyBy('name');

        $read = function (string $name) use ($settings): mixed {
            $setting = $settings->get($name);

            return $setting instanceof Setting ? $this->decodeSettingRecord($setting) : null;
        };

        return BuilderStateService::normalize([
            'design' => $read('design_setting'),
            'header' => $read('header_setting'),
            'footer' => $read('footer_setting'),
            'menu'   => $read('menu_setting'),
        ]);
    }

    private function currentAdminId(): ?int
    {
        $id = Auth::guard(AdminUser::AUTH_GUARD)->id();

        return is_numeric($id) ? (int) $id : null;
    }

    /** Publishing is infrequent; clear shared caches once, outside the write transaction. */
    private function clearPublishedCaches(): bool
    {
        try {
            SettingRepo::clearCache();

            return true;
        } catch (\Throwable $exception) {
            report($exception);
            logger()->warning('Published Builder state was saved but cache cleanup failed.', [
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /** @return array<int, array{value: string, label: string}> */
    private function defaultEditorLinkTypes(): array
    {
        return [
            ['value' => 'custom', 'label' => '自定义链接'],
            ['value' => 'product', 'label' => '商品'],
            ['value' => 'category', 'label' => '分类'],
            ['value' => 'page', 'label' => '文章页面'],
            ['value' => 'brand', 'label' => '品牌'],
            ['value' => 'static', 'label' => '站内页面'],
        ];
    }

    /** Keep plugin link metadata serializable and bounded before it reaches the browser. */
    private function normalizeEditorLinkTypes(mixed $types): array
    {
        $types      = is_array($types) ? $types : $this->defaultEditorLinkTypes();
        $normalized = [];
        $seen       = [];
        foreach (array_slice($types, 0, 24) as $type) {
            if (! is_array($type)) {
                continue;
            }
            $value = trim((string) ($type['value'] ?? ''));
            if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $value) || isset($seen[$value])) {
                continue;
            }
            $label        = trim(strip_tags((string) ($type['label'] ?? $value)));
            $normalized[] = ['value' => $value, 'label' => mb_substr($label ?: $value, 0, 80)];
            $seen[$value] = true;
        }

        return $normalized ?: $this->defaultEditorLinkTypes();
    }
}
