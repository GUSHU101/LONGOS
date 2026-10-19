<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $usd = DB::table('currencies')->where('code', 'USD');
        if ($usd->exists()) {
            $usd->update(['value' => 1, 'active' => true, 'updated_at' => now()]);
        } else {
            DB::table('currencies')->insert([
                'name'          => 'USD',
                'code'          => 'USD',
                'symbol_left'   => '$',
                'symbol_right'  => '',
                'decimal_place' => 2,
                'value'         => 1,
                'active'        => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        $query = DB::table('settings')
            ->where('type', 'system')
            ->where('space', 'base')
            ->where('name', 'currency');

        if ($query->exists()) {
            $query->update(['value' => 'USD', 'json' => false, 'updated_at' => now()]);

            return;
        }

        DB::table('settings')->insert([
            'type'       => 'system',
            'space'      => 'base',
            'name'       => 'currency',
            'value'      => 'USD',
            'json'       => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // USD is intentionally irreversible: all product prices use it as
        // the store's fixed accounting and checkout currency.
    }
};
