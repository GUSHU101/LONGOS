<?php

namespace Tests\Unit;

use App\Saas\Support\IdempotencyPayload;
use PHPUnit\Framework\TestCase;

class SaasIdempotencyPayloadTest extends TestCase
{
    public function test_object_key_order_does_not_change_the_request_hash(): void
    {
        $first = [
            'sites' => [['code' => 'a', 'name' => 'A']],
            'seed'  => true,
        ];
        $second = [
            'seed'  => true,
            'sites' => [['name' => 'A', 'code' => 'a']],
        ];

        self::assertSame(IdempotencyPayload::hash($first), IdempotencyPayload::hash($second));
    }

    public function test_list_order_remains_part_of_the_request_identity(): void
    {
        self::assertNotSame(
            IdempotencyPayload::hash(['sites' => [['code' => 'a'], ['code' => 'b']]]),
            IdempotencyPayload::hash(['sites' => [['code' => 'b'], ['code' => 'a']]]),
        );
    }
}
