<?php

namespace Beike\Shop\Http\Controllers;

use Beike\Services\BuilderStateService;
use Beike\Services\DesignService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

class HomeController extends Controller
{
    /**
     * 通过page builder 显示首页
     *
     * @return View
     * @throws \Exception
     */
    public function index(): mixed
    {
        $designSettings = BuilderStateService::storefrontState()['design'];
        $modules        = $designSettings['modules'];
        $designMode     = BuilderStateService::isPreviewRequest();

        $moduleItems = [];
        foreach ($modules as $module) {
            $code       = $module['code'] ?? null;
            if (! $code) {
                continue;
            }
            $modulePlacement = $module['content']['placement'] ?? 'home';
            if (! DesignService::matchesPlacement($modulePlacement, 'home')) {
                continue;
            }
            if (! $designMode && ($module['visible'] ?? true) === false) {
                continue;
            }
            $moduleId   = $module['module_id'] ?? '';
            $content    = $module['content']   ?? [];
            $viewPath   = $module['view_path'] ?? '';

            if ($viewPath) {
                $plugin = plugin(Str::before($viewPath, '::'));

                if ($plugin && $plugin->type == 'theme' && $plugin->code != system_setting('base.theme')) {
                    continue;
                }
            }

            if (empty($viewPath)) {
                $viewPath = str_starts_with((string) $code, 'shopify-') ? 'design.shopify_migrated' : "design.{$code}";
            }

            $validViewPath = is_string($viewPath)
                && ! str_contains($viewPath, '..')
                && preg_match('/^(?:[A-Za-z][A-Za-z0-9_]*::)?[A-Za-z0-9_.\/-]+$/', $viewPath);
            if (! $validViewPath) {
                if ($designMode) {
                    $moduleItems[] = DesignService::previewErrorModule($moduleId, (string) $code, count($moduleItems));
                }

                continue;
            }

            $paths = explode('::', $viewPath);
            if (count($paths) == 2) {
                $pluginCode = $paths[0];
                if (! app('plugin')->checkActive($pluginCode)) {
                    continue;
                }
            }

            if (! view()->exists($viewPath) || ! $moduleId) {
                if ($designMode) {
                    $moduleItems[] = DesignService::previewErrorModule($moduleId, (string) $code, count($moduleItems));
                }

                continue;
            }

            try {
                $moduleContent = DesignService::handleModuleContent($code, $content);
                $moduleData    = [
                    'code'      => $code,
                    'module_id' => $moduleId,
                    'view_path' => $viewPath,
                    'content'   => $moduleContent,
                    'visible'   => $module['visible'] ?? true,
                    'design'    => $designMode,
                ];

                // Render each user-configurable module in isolation. An old
                // plugin, deleted product, or malformed design record should
                // remove only that block instead of making the homepage (and
                // the builder iframe) return a 500 response.
                $moduleData['html'] = DesignService::wrapResponsiveModuleHtml(
                    view($viewPath, $moduleData)->render(),
                    $content,
                    $moduleId
                );
                $moduleItems[] = $moduleData;
            } catch (\Throwable $exception) {
                logger()->warning('Skipping an invalid home design module.', [
                    'module_id' => $moduleId,
                    'code'      => $code,
                    'view_path' => $viewPath,
                    'exception' => $exception->getMessage(),
                ]);

                // In the visual editor a failed module must be visible and
                // removable. Silently skipping it leaves the preview blank
                // and makes the broken record impossible to identify.
                if ($designMode) {
                    $moduleItems[] = DesignService::previewErrorModule($moduleId, (string) $code, count($moduleItems));
                }
            }
        }

        $data = [
            'modules' => $moduleItems,
            // The view uses this flag for builder-only helpers. Always pass
            // it explicitly so a normal storefront request can never depend
            // on an undefined Blade variable.
            'design'  => $designMode,
        ];

        $data = hook_filter('home.index.data', $data);

        return view('home', $data);
    }
}
