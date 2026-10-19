<?php

namespace Tests\Unit;

use Beike\Services\BuilderPageSchema;
use PHPUnit\Framework\TestCase;

class BuilderPageSchemaTest extends TestCase
{
    public function test_it_normalizes_component_versions_and_responsive_visibility(): void
    {
        $module = BuilderPageSchema::normalizeModule([
            'component_version' => 400,
            'content'           => ['responsive' => ['visibility' => ['desktop' => false, 'tablet' => '1', 'mobile' => 'off']]],
        ]);

        self::assertSame(99, $module['component_version']);
        self::assertSame(['desktop' => false, 'tablet' => true, 'mobile' => false], $module['content']['responsive']['visibility']);
    }

    public function test_editor_boundaries_reject_oversized_previews_and_future_backups(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/beike/Admin/Http/Controllers/DesignController.php');
        $api        = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/beike/admin/js/builder/api.ts');

        self::assertStringContainsString('strlen($raw) > 262_144', $controller);
        self::assertStringContainsString("['home', 'product', 'category', 'page']", $controller);
        self::assertStringContainsString("strlen(\$rendered['html']) > 524_288", $controller);
        self::assertStringContainsString('$backupVersion > 5', $controller);
        self::assertStringContainsString('! array_is_list($payload)', $controller);
        self::assertStringContainsString('Number.isSafeInteger(serverVersion)', $api);
    }
}
