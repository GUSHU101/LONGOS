<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUSES = "'pending','sent','delivered','deferred','bounced','blocked','failed'";

    public function up(): void
    {
        if (! Schema::hasTable('mail_logs') || ! Schema::hasColumn('mail_logs', 'status')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE mail_logs MODIFY status ENUM(' . self::STATUSES . ") NOT NULL DEFAULT 'pending' COMMENT '发送状态'");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE mail_logs DROP CONSTRAINT IF EXISTS mail_logs_status_check');
            DB::statement('ALTER TABLE mail_logs ADD CONSTRAINT mail_logs_status_check CHECK (status IN (' . self::STATUSES . '))');
        }
        // SQLite stores Laravel enums as unconstrained VARCHAR columns.
    }

    public function down(): void
    {
        if (! Schema::hasTable('mail_logs') || ! Schema::hasColumn('mail_logs', 'status')) {
            return;
        }

        DB::table('mail_logs')->where('status', 'delivered')->update(['status' => 'sent']);
        DB::table('mail_logs')->whereIn('status', ['deferred', 'bounced', 'blocked'])->update(['status' => 'failed']);

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE mail_logs MODIFY status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending' COMMENT '发送状态'");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE mail_logs DROP CONSTRAINT IF EXISTS mail_logs_status_check');
            DB::statement("ALTER TABLE mail_logs ADD CONSTRAINT mail_logs_status_check CHECK (status IN ('pending','sent','failed'))");
        }
    }
};
