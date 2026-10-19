<?php

namespace Tests\Feature;

use App\Saas\IdempotencyService;
use App\Saas\Models\IdempotencyRecord;
use App\Saas\Support\IdempotencyConflict;
use App\Saas\Support\IdempotencyResult;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class SaasIdempotencyServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        config([
            'app.key'                   => config('app.key') ?: 'base64:' . base64_encode(str_repeat('L', 32)),
            'database.connections.saas' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
            'saas.idempotency.ttl_hours'                  => 24,
            'saas.idempotency.processing_timeout_minutes' => 120,
        ]);
        DB::purge('saas');

        Schema::connection('saas')->create('saas_idempotency_records', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 80);
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->string('status', 16);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_ciphertext')->nullable();
            $table->uuid('lock_token')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['scope', 'idempotency_key']);
        });
    }

    public function test_it_executes_once_replays_encrypted_response_and_rejects_payload_changes(): void
    {
        $service = new IdempotencyService;
        $runs    = 0;
        $key     = '9f77dfe2-1b34-4c04-9c8a-a8f9a9893e71';
        $payload = ['sites' => [['code' => 'store-a']]];

        $operation = function (callable $heartbeat) use (&$runs): IdempotencyResult {
            $runs++;
            // The first two calls deliberately occur in the same second.
            // MySQL may report zero changed rows for the second timestamp
            // refresh even though the ownership predicate still matches.
            $heartbeat();
            $heartbeat();

            return new IdempotencyResult(['password' => 'one-time-secret'], 201);
        };

        $first  = $service->execute('control.sites.batch', $key, $payload, $operation);
        $replay = $service->execute('control.sites.batch', $key, $payload, $operation);

        self::assertSame(1, $runs);
        self::assertFalse($first->replayed);
        self::assertTrue($replay->replayed);
        self::assertSame($first->body, $replay->body);
        self::assertStringNotContainsString(
            'one-time-secret',
            (string) IdempotencyRecord::query()->value('response_ciphertext'),
        );

        $this->expectException(IdempotencyConflict::class);
        $service->execute('control.sites.batch', $key, ['sites' => [['code' => 'store-b']]], $operation);
    }
}
