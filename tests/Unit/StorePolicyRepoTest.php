<?php

namespace Tests\Unit;

use Beike\Repositories\StorePolicyRepo;
use PHPUnit\Framework\TestCase;

class StorePolicyRepoTest extends TestCase
{
    public function test_it_normalizes_and_sanitizes_policy_settings(): void
    {
        $settings = StorePolicyRepo::normalizeSettings([
            'refund' => [
                'enabled' => '1',
                'title'   => '<b>Returns</b>',
                'content' => '<h2>30 days</h2><script>alert(1)</script><a href="javascript:alert(2)">Help</a>',
            ],
        ]);

        self::assertTrue($settings['refund']['enabled']);
        self::assertSame('Refund Policy', $settings['refund']['title']);
        self::assertStringContainsString('<h2>30 days</h2>', $settings['refund']['content']);
        self::assertStringNotContainsString('script', $settings['refund']['content']);
        self::assertStringNotContainsString('javascript:', $settings['refund']['content']);
        self::assertSame('Shipping Policy', $settings['shipping']['title']);
        self::assertFalse($settings['shipping']['enabled']);
        self::assertSame('Contact Us', $settings['contact']['title']);
        self::assertSame('Cookie Policy', $settings['cookies']['title']);
    }

    public function test_content_must_contain_visible_text(): void
    {
        self::assertFalse(StorePolicyRepo::hasMeaningfulContent('<p><br></p>'));
        self::assertFalse(StorePolicyRepo::hasMeaningfulContent('<p>&nbsp;&#8203;</p>'));
        self::assertTrue(StorePolicyRepo::hasMeaningfulContent('<p>Policy text</p>'));
    }

    public function test_it_accepts_plain_text_and_common_document_html(): void
    {
        $settings = StorePolicyRepo::normalizeSettings([
            'privacy' => ['content' => "We protect your personal information.\nContact us with questions."],
            'terms'   => ['content' => '<p><img src="/image/policy.png" alt="Policy"></p><pre><code>Term 1</code></pre>'],
        ]);

        self::assertSame("<p>We protect your personal information.<br />\nContact us with questions.</p>", $settings['privacy']['content']);
        self::assertStringContainsString('<img src="/image/policy.png" alt="Policy" />', $settings['terms']['content']);
        self::assertStringContainsString('<pre><code>Term 1</code></pre>', $settings['terms']['content']);
    }
}
