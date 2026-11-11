<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugin\LongosInstantTranslate\Services\InstantTranslateSettings;

class InstantTranslatePluginContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2) . '/plugins/LongosInstantTranslate';
    }

    public function test_manifest_and_vendor_are_locked_and_independent(): void
    {
        $manifest = json_decode((string) file_get_contents($this->root . '/config.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('longos_instant_translate', $manifest['code']);
        self::assertSame('v2.0.0', $manifest['version']);
        self::assertSame(InstantTranslateSettings::XNX3_SHA256, hash_file('sha256', $this->root . '/Static/public/vendor/translate.min.js'));
        self::assertFileExists($this->root . '/Static/public/vendor/LICENSE.translate.txt');
    }

    public function test_runtime_has_no_legacy_plugin_class_dependency(): void
    {
        $source   = '';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root));
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['php', 'js', 'json'], true)
                                && ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'Migrations' . DIRECTORY_SEPARATOR)
                                && ! str_contains($file->getPathname(), 'vendor' . DIRECTORY_SEPARATOR . 'translate.min.js')) {
                $source .= file_get_contents($file->getPathname());
            }
        }

        self::assertStringNotContainsString('TranslationSchema', $source);
        self::assertStringNotContainsString('DictionaryService', $source);
        self::assertStringNotContainsString('PublicationService', $source);
        self::assertStringNotContainsString('Plugin\\LongosGlobalTranslate', $source);
        self::assertStringNotContainsString('lgt_', $source);
    }

    public function test_browser_wrapper_enforces_safe_roots_and_endpoint(): void
    {
        $script = (string) file_get_contents($this->root . '/Static/public/translate-widget.js');
        $policy = (string) file_get_contents($this->root . '/Services/StorefrontPolicy.php');

        self::assertStringContainsString("'main#main-content'", $policy);
        self::assertStringContainsString('edge.microsoft.com', $script);
        self::assertStringContainsString('networkPolicy.allowed', $script);
        self::assertStringContainsString('engine.element.tagAttribute = {}', $script);
        self::assertStringContainsString('MutationObserver', $script);
        self::assertStringContainsString('preloadLibrary', $script);
        self::assertStringContainsString('X-CSRF-TOKEN', $script);
        self::assertStringNotContainsString('listener.start(', $script);
        self::assertStringNotContainsString('document.body.innerHTML', $script);
    }
}
