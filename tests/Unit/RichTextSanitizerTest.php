<?php

namespace Tests\Unit;

use Beike\Services\RichTextSanitizer;
use PHPUnit\Framework\TestCase;

class RichTextSanitizerTest extends TestCase
{
    public function test_it_removes_executable_markup_and_unsafe_urls(): void
    {
        $clean = RichTextSanitizer::sanitize(
            '<p onclick="alert(1)">Hello<script>alert(2)</script>'
            . '<a href="javascript:alert(3)">bad</a><img src="x" onerror="alert(4)"></p>',
        );

        self::assertStringContainsString('<p>Hello', $clean);
        self::assertStringNotContainsString('onclick', $clean);
        self::assertStringNotContainsString('<script', $clean);
        self::assertStringNotContainsString('javascript:', $clean);
        self::assertStringNotContainsString('onerror', $clean);
    }

    public function test_it_preserves_commerce_content_images_tables_and_native_video(): void
    {
        $clean = RichTextSanitizer::sanitize(
            '<h2>Details</h2><figure><img src="https://cdn.example.test/item.jpg" alt="Item">'
            . '<figcaption>Front view</figcaption></figure><table><tbody><tr><th>Size</th><td>M</td></tr></tbody></table>'
            . '<video src="/catalog/demo.mp4" controls playsinline><source src="/catalog/demo.webm" type="video/webm"></video>',
        );

        self::assertStringContainsString('<h2>Details</h2>', $clean);
        self::assertStringContainsString('<figure>', $clean);
        self::assertStringContainsString('<img src="https://cdn.example.test/item.jpg" alt="Item"', $clean);
        self::assertStringContainsString('<table>', $clean);
        self::assertStringContainsString('<video', $clean);
        self::assertStringContainsString('controls=', $clean);
        self::assertStringContainsString('playsinline=', $clean);
        self::assertStringContainsString('<source src="/catalog/demo.webm" type="video/webm"', $clean);
    }

    public function test_new_window_links_are_isolated_from_the_storefront_window(): void
    {
        $clean = RichTextSanitizer::sanitize('<a href="https://example.test" target="_blank" rel="ugc">Review</a>');

        self::assertStringContainsString('target="_blank"', $clean);
        self::assertStringContainsString('rel="ugc noopener noreferrer"', $clean);
    }
}
