<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DesignNavigationEditorTest extends TestCase
{
    public function test_dedicated_navigation_editor_is_routed_and_uses_builder_state(): void
    {
        $root       = dirname(__DIR__, 2);
        $routes     = (string) file_get_contents($root . '/beike/Admin/Routes/admin.php');
        $controller = (string) file_get_contents($root . '/beike/Admin/Http/Controllers/DesignNavigationController.php');
        $sidebar    = (string) file_get_contents($root . '/beike/Admin/View/Components/Sidebar.php');

        self::assertStringContainsString("get('design/navigation'", $routes);
        self::assertStringContainsString("put('design/navigation'", $routes);
        self::assertStringContainsString("name('design.navigation.index')", $routes);
        self::assertStringContainsString("get('design/navigation/resources'", $routes);
        self::assertStringContainsString("name('design.navigation.resources')", $routes);
        self::assertStringContainsString("name('design.navigation.update')", $routes);
        self::assertStringContainsString("'route' => 'design.navigation.index'", $sidebar);
        self::assertStringContainsString('BuilderStateService::draft()', $controller);
        self::assertStringContainsString("'resource_search_url'", $controller);
        self::assertStringContainsString('public function resources(Request $request): JsonResponse', $controller);
        self::assertStringContainsString("'type'  => 'required|string|in:product,category,page,brand'", $controller);
        self::assertStringContainsString('ProductRepo::autocomplete($query, 20)', $controller);
        self::assertStringContainsString('CategoryRepo::autocomplete($query)', $controller);
        self::assertStringContainsString('PageRepo::autocomplete($query)', $controller);
        self::assertStringContainsString('BrandRepo::autocomplete($query, 1)', $controller);
        self::assertStringContainsString('MenuRepo::normalizeMenuSetting', $controller);
        self::assertStringContainsString('lockForUpdate()', $controller);
        self::assertStringContainsString("header('X-Builder-Draft-Version')", $controller);
        self::assertStringContainsString("storeValue('menu_setting'", $controller);
    }

    public function test_navigation_editor_exposes_full_hierarchy_media_and_publish_controls(): void
    {
        $root = dirname(__DIR__, 2);
        $view = (string) file_get_contents($root . '/resources/beike/admin/views/pages/design/navigation.blade.php');

        self::assertStringContainsString('v-for="(item, index) in menus"', $view);
        self::assertStringContainsString('v-model="item.badge.isShow"', $view);
        self::assertStringContainsString('v-model="item.isChildren"', $view);
        self::assertStringContainsString('v-model="item.isFull"', $view);
        self::assertStringContainsString('v-for="(group, groupIndex) in item.childrenGroup"', $view);
        self::assertStringContainsString('v-for="(child, childIndex) in group.children"', $view);
        self::assertStringContainsString('fileManagerIframe', $view);
        self::assertStringContainsString('normalizeMediaFiles', $view);
        self::assertStringContainsString('@click="save(false)"', $view);
        self::assertStringContainsString('@click="save(true)"', $view);
        self::assertStringContainsString("'X-Builder-Draft-Version'", $view);
        self::assertStringContainsString('submittedRevision', $view);
        self::assertStringContainsString('localDirty', $view);
        self::assertStringContainsString("window.addEventListener('beforeunload'", $view);
        self::assertStringContainsString('Number.isSafeInteger(nextVersion)', $view);
        self::assertStringContainsString('AbortController', $view);
    }

    public function test_navigation_destinations_use_searchable_resource_picker_instead_of_raw_ids(): void
    {
        $root        = dirname(__DIR__, 2);
        $view        = (string) file_get_contents($root . '/resources/beike/admin/views/pages/design/navigation.blade.php');
        $chinese     = (string) file_get_contents($root . '/lang/zh_cn/admin/design_navigation.php');
        $english     = (string) file_get_contents($root . '/lang/en/admin/design_navigation.php');
        $occurrences = substr_count($view, '<navigation-link-picker');

        self::assertSame(3, $occurrences, 'Primary, image-card and child menu links must share the modern picker.');
        self::assertStringContainsString('<template id="navigation-link-picker-template">', $view);
        self::assertStringContainsString('requestResources: async function', $view);
        self::assertStringContainsString('resolveSelection: async function', $view);
        self::assertStringContainsString('typeof AbortController', $view);
        self::assertStringContainsString('loadingSequence', $view);
        self::assertStringContainsString('role="listbox"', $view);
        self::assertStringContainsString('selectedResource.name', $view);
        self::assertStringContainsString('resource.status ? activeLabel : inactiveLabel', $view);
        self::assertStringNotContainsString('linkPlaceholder', $view);
        self::assertStringNotContainsString('填写对应 ID', $chinese);
        self::assertStringNotContainsString("'link_product' => 'Product ID'", $english);
        self::assertStringNotContainsString("'link_category' => 'Category ID'", $english);
    }
}
