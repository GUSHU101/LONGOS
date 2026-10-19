<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('addresses', 'calling_code')) {
            Schema::table('addresses', function (Blueprint $table) {
                $table->string('calling_code', 12)->default('')->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('addresses', 'calling_code')) {
            Schema::table('addresses', function (Blueprint $table) {
                $table->dropColumn('calling_code');
            });
        }
    }
};
