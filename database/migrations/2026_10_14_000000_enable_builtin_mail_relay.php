<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! (bool) config('mail.relay.enabled', false) || ! Schema::hasTable('settings')) {
            return;
        }

        $now = now();
        $defaults = [
            'mail_engine' => ['value' => 'self_hosted', 'json' => 0],
            'mail_from'   => [
                'value' => json_encode([
                    'address' => trim((string) config('mail.from.address', '')),
                    'name'    => trim((string) config('mail.from.name', config('app.name', 'LONGOS'))),
                ], JSON_UNESCAPED_UNICODE),
                'json' => 1,
            ],
        ];

        foreach ($defaults as $name => $default) {
            $exists = DB::table('settings')
                ->where('type', 'system')
                ->where('space', 'base')
                ->where('name', $name)
                ->exists();

            if (! $exists) {
                DB::table('settings')->insert([
                    'type'       => 'system',
                    'space'      => 'base',
                    'name'       => $name,
                    'value'      => $default['value'],
                    'json'       => $default['json'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Keep administrator mail choices intact on rollback.
    }
};
