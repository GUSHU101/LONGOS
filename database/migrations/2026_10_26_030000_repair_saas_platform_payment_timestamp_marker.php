<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repair deployments where the original UTC-normalization migration was
     * recorded before its schema mutation took effect. This migration is
     * deliberately idempotent so it also finishes a partially converted table.
     */
    public function up(): void
    {
        if (DB::getDefaultConnection() === 'tenant'
            || ! Schema::hasTable('saas_platform_payment_sessions')) {
            return;
        }

        if (! Schema::hasColumn('saas_platform_payment_sessions', 'timestamps_utc')) {
            Schema::table('saas_platform_payment_sessions', function (Blueprint $table): void {
                $table->boolean('timestamps_utc')->default(false)->after('locale');
            });
        }

        $timezones = DB::table('saas_sites')->pluck('timezone', 'id');
        DB::table('saas_platform_payment_sessions')
            ->where('timestamps_utc', false)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($timezones): void {
                $migrationTime = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');

                foreach ($rows as $row) {
                    $timezone = $this->validTimezone($timezones[$row->site_id] ?? 'UTC');
                    $active   = in_array((string) $row->status, ['pending', 'processing'], true)
                        || ((string) $row->status === 'expired' && ! empty($row->active_key));

                    DB::table('saas_platform_payment_sessions')->where('id', $row->id)->update([
                        'created_at'     => $this->convert($row->created_at, $timezone, 'UTC'),
                        'updated_at'     => $active ? $migrationTime : $this->convert($row->updated_at, $timezone, 'UTC'),
                        'expires_at'     => $this->convert($row->expires_at, $timezone, 'UTC'),
                        'completed_at'   => $this->convert($row->completed_at, $timezone, 'UTC'),
                        'timestamps_utc' => true,
                    ]);
                }
            }, 'id');
    }

    public function down(): void
    {
        // The original 2026_10_26_020000 migration owns this column. Rolling
        // back only the repair must not remove a schema element it did not
        // necessarily create or reverse timestamps that may already be UTC.
    }

    private function convert(mixed $value, string $from, string $to): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', (string) $value, $from)
            ->setTimezone($to)
            ->format('Y-m-d H:i:s');
    }

    private function validTimezone(mixed $timezone): string
    {
        $timezone = is_string($timezone) ? trim($timezone) : '';
        if ($timezone === '') {
            return 'UTC';
        }

        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return 'UTC';
        }
    }
};
