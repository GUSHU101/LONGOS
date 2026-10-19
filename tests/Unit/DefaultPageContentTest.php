<?php

namespace Tests\Unit;

use Database\Seeders\PagesSeeder;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\TestCase;

class DefaultPageContentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    public function test_default_pages_do_not_publish_legacy_or_placeholder_identity(): void
    {
        $descriptions = (new PagesSeeder)->getPageDescriptions();
        $content      = json_encode($descriptions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        foreach ([
            'Everbright mall',
            '光大商城',
            'guangdawangluo.com',
            'support@example.com',
            '新闻博客做为重要的文字营销工具',
            'As an important text marketing tool',
        ] as $legacy) {
            self::assertStringNotContainsString($legacy, $content);
        }

        foreach ($descriptions as $description) {
            self::assertNotSame('', trim((string) $description['summary']));
            self::assertNotSame('', trim((string) $description['meta_title']));
            self::assertNotSame('', trim((string) $description['meta_description']));
        }
    }

    public function test_upgrade_only_targets_unmistakable_demo_copy(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 2) . '/database/migrations/2026_10_17_000100_replace_legacy_demo_pages.php',
        );

        self::assertStringContainsString('if (! $this->isLegacyDemoContent($combined))', $migration);
        self::assertStringNotContainsString("DB::table('page_descriptions')->truncate", $migration);
        self::assertStringNotContainsString("whereIn('page_id'", $migration);
    }
}
