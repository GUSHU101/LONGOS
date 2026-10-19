<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class StorefrontOutputSafetyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_product_video_uses_json_encoding_and_safe_dom_construction(): void
    {
        $template = $this->read('themes/default/product/product-video.blade.php');

        self::assertStringContainsString("Js::from((string) \$product['video'])", $template);
        self::assertStringContainsString("hostname === 'youtu.be'", $template);
        self::assertStringContainsString('document.createElement(\'iframe\')', $template);
        self::assertStringContainsString('replaceChildren(iframe)', $template);
        self::assertStringNotContainsString("{!! \$product['video'] !!}", $template);
        self::assertStringNotContainsString("$('#product-video').html(`", $template);
    }

    public function test_user_messages_are_escaped_at_admin_and_mail_boundaries(): void
    {
        self::assertStringContainsString('{{ $msg }}', $this->read('resources/beike/admin/views/components/alert.blade.php'));
        self::assertStringContainsString('{{ $error }}', $this->read('resources/beike/admin/views/pages/plugins/error.blade.php'));
        self::assertStringContainsString("{{ \\Session::get('success') }}", $this->read('themes/default/account/account.blade.php'));

        foreach (['themes/default/mails/rma_new.blade.php', 'themes/default/mails/admin_rma_new.blade.php'] as $path) {
            $template = $this->read($path);
            self::assertStringContainsString("nl2br(e((string) (\$rma['comment'] ?? '')))", $template);
            self::assertStringNotContainsString("{!! \$rma['comment'] !!}", $template);
        }

        foreach ([
            'resources/beike/admin/views/pages/admin_roles/edit.blade.php',
            'resources/beike/admin/views/pages/attributes/form.blade.php',
            'resources/beike/admin/views/pages/customers/form.blade.php',
        ] as $path) {
            $template = $this->read($path);
            self::assertStringContainsString('textContent = String(', $template);
            self::assertStringNotContainsString('innerHTML =', $template);
        }
    }

    public function test_plugin_readmes_cannot_execute_raw_html_in_the_admin_panel(): void
    {
        $controller = $this->read('beike/Admin/Http/Controllers/PluginController.php');

        self::assertStringContainsString("'html_input'         => 'strip'", $controller);
        self::assertStringContainsString("'allow_unsafe_links' => false", $controller);
    }

    public function test_editor_uploads_build_media_nodes_without_html_interpolation(): void
    {
        $script = $this->read('resources/beike/admin/js/app.js');

        self::assertStringContainsString("document.createElement('a')", $script);
        self::assertStringContainsString("document.createElement('img')", $script);
        self::assertStringContainsString('fileLink.textContent = fileName', $script);
        self::assertStringNotContainsString("ed.insertContent(`<img src='\${res.data.url}'", $script);
    }

    public function test_storefront_ajax_writes_explicitly_send_the_csrf_token(): void
    {
        $script = $this->read('resources/beike/shop/default/js/app.js');

        self::assertStringContainsString("http.axiosApi.defaults.headers.common['X-CSRF-TOKEN'] = token", $script);
        self::assertStringContainsString("http.axiosApi.defaults.headers.common['Accept'] = 'application/json'", $script);
    }

    public function test_new_window_links_do_not_expose_window_opener(): void
    {
        foreach (['resources', 'themes', 'plugins'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root . '/' . $directory));

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! preg_match('/\.(?:blade\.php|php|js|json)$/', $file->getFilename())) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());
                self::assertDoesNotMatchRegularExpression(
                    '/<a\b(?=[^>]*\btarget=["\']_blank["\'])(?![^>]*\brel=)[^>]*>/is',
                    $contents,
                    $file->getPathname(),
                );
            }
        }
    }

    private function read(string $path): string
    {
        return (string) file_get_contents($this->root . '/' . $path);
    }
}
