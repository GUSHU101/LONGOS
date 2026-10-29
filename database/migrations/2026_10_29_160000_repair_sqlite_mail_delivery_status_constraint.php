<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite'
            || ! Schema::hasTable('mail_logs')
            || ! Schema::hasColumn('mail_logs', 'status')) {
            return;
        }

        // Laravel implements SQLite enums as VARCHAR plus a CHECK constraint.
        // Rebuild the column as a bounded string so the expanded provider
        // states introduced by the previous migration can actually be stored.
        Schema::table('mail_logs', function (Blueprint $table): void {
            $table->string('status', 24)->default('pending')->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite'
            || ! Schema::hasTable('mail_logs')
            || ! Schema::hasColumn('mail_logs', 'status')) {
            return;
        }

        DB::table('mail_logs')->where('status', 'delivered')->update(['status' => 'sent']);
        DB::table('mail_logs')->whereIn('status', ['deferred', 'bounced', 'blocked'])->update(['status' => 'failed']);
        Schema::table('mail_logs', function (Blueprint $table): void {
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending')->change();
        });
    }
};
