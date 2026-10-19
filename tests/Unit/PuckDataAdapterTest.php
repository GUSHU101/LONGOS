<?php

namespace Tests\Unit;

use Beike\Services\PuckDataAdapter;
use PHPUnit\Framework\TestCase;

class PuckDataAdapterTest extends TestCase
{
    public function test_it_preserves_domain_identifiers_when_deserializing_puck_data(): void
    {
        $page = PuckDataAdapter::fromPuck([
            'content' => [[
                'type'  => 'promo_bar',
                'props' => ['id' => 'puck-module-1', 'title' => 'A launch', '_builder' => ['component_version' => 2, 'visible' => false]],
            ]],
            'root' => ['props' => ['schemaVersion' => 5, 'pageType' => 'home', 'globalStyle' => [], 'appEmbeds' => [], 'pageMetadata' => ['title' => 'Launch', 'slug' => 'launch-page']]],
        ]);

        self::assertSame('puck', $page['engine']);
        self::assertSame(5, $page['schema_version']);
        self::assertSame('puck-module-1', $page['modules'][0]['module_id']);
        self::assertSame(2, $page['modules'][0]['component_version']);
        self::assertFalse($page['modules'][0]['visible']);
        self::assertSame('launch-page', $page['page_metadata']['slug']);
    }
}
