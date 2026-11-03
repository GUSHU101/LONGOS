<?php

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Plugin\LongosGlobalTranslate\Services\TextContract;

class GlobalTranslationTextTest extends TestCase
{
    public function test_utf16_and_bytes_are_measured_independently(): void
    {
        self::assertSame(['bytes' => 8, 'codepoints' => 3, 'utf16' => 4], TextContract::units('A中😀'));
        $source   = str_repeat('中😀é', 2000);
        $segments = TextContract::segments($source);
        self::assertSame($source, implode('', $segments));
        foreach ($segments as $segment) {
            self::assertLessThanOrEqual(4500, TextContract::units($segment)['bytes']);
            self::assertLessThanOrEqual(4500, TextContract::units($segment)['utf16']);
        }
    }

    public function test_protected_tokens_roundtrip_and_are_unpredictable(): void
    {
        $source = 'Hello :name, $39.99, 30%, https://example.com and shop@example.com';
        $first  = TextContract::protect($source);
        self::assertSame($source, TextContract::restore($first['text'], $first['tokens']));
        self::assertNotSame($first['text'], TextContract::protect($source)['text']);
        $this->expectException(InvalidArgumentException::class);
        TextContract::restore('Broken translation', $first['tokens']);
    }

    public function test_external_html_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TextContract::restore('<img src=x onerror=alert(1)>', []);
    }
}
