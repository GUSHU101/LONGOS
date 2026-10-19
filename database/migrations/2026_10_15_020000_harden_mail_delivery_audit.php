<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mail_logs')) {
            return;
        }

        $addDeliveryId  = ! Schema::hasColumn('mail_logs', 'delivery_id');
        $addAttempts    = ! Schema::hasColumn('mail_logs', 'attempts');
        $addLastAttempt = ! Schema::hasColumn('mail_logs', 'last_attempt_at');

        Schema::table('mail_logs', function (Blueprint $table) use ($addDeliveryId, $addAttempts, $addLastAttempt): void {
            if ($addDeliveryId) {
                $table->string('delivery_id', 128)->nullable()->after('id');
                $table->unique('delivery_id', 'mail_logs_delivery_id_unique');
            }
            if ($addAttempts) {
                $table->unsignedInteger('attempts')->default(1)->after('status');
            }
            if ($addLastAttempt) {
                $table->timestamp('last_attempt_at')->nullable()->after('sent_at');
            }
        });

        DB::table('mail_logs')->whereNull('last_attempt_at')->update([
            'last_attempt_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('mail_logs')) {
            return;
        }

        $dropDeliveryId  = Schema::hasColumn('mail_logs', 'delivery_id');
        $dropAttempts    = Schema::hasColumn('mail_logs', 'attempts');
        $dropLastAttempt = Schema::hasColumn('mail_logs', 'last_attempt_at');

        Schema::table('mail_logs', function (Blueprint $table) use ($dropDeliveryId, $dropAttempts, $dropLastAttempt): void {
            if ($dropDeliveryId) {
                $table->dropUnique('mail_logs_delivery_id_unique');
                $table->dropColumn('delivery_id');
            }
            if ($dropAttempts) {
                $table->dropColumn('attempts');
            }
            if ($dropLastAttempt) {
                $table->dropColumn('last_attempt_at');
            }
        });
    }
};
