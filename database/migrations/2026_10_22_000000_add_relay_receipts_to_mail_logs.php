<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mail_logs')) {
            return;
        }

        $addSubmissionId = ! Schema::hasColumn('mail_logs', 'relay_submission_id');
        $addMessageId    = ! Schema::hasColumn('mail_logs', 'transport_message_id');

        Schema::table('mail_logs', function (Blueprint $table) use ($addSubmissionId, $addMessageId): void {
            if ($addSubmissionId) {
                $table->string('relay_submission_id', 128)->nullable()->after('delivery_id');
                $table->index('relay_submission_id', 'mail_logs_relay_submission_id_index');
            }
            if ($addMessageId) {
                $table->string('transport_message_id', 255)->nullable()->after('relay_submission_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mail_logs')) {
            return;
        }

        $dropSubmissionId = Schema::hasColumn('mail_logs', 'relay_submission_id');
        $dropMessageId    = Schema::hasColumn('mail_logs', 'transport_message_id');

        Schema::table('mail_logs', function (Blueprint $table) use ($dropSubmissionId, $dropMessageId): void {
            if ($dropSubmissionId) {
                $table->dropIndex('mail_logs_relay_submission_id_index');
                $table->dropColumn('relay_submission_id');
            }
            if ($dropMessageId) {
                $table->dropColumn('transport_message_id');
            }
        });
    }
};
