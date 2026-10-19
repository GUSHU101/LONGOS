<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDefaultConnection() === 'tenant' || ! Schema::hasTable('saas_domains')) {
            return;
        }

        $columns = [
            'origin'                  => ! Schema::hasColumn('saas_domains', 'origin'),
            'verification_method'     => ! Schema::hasColumn('saas_domains', 'verification_method'),
            'verification_token'      => ! Schema::hasColumn('saas_domains', 'verification_token'),
            'verification_expires_at' => ! Schema::hasColumn('saas_domains', 'verification_expires_at'),
            'verification_attempts'   => ! Schema::hasColumn('saas_domains', 'verification_attempts'),
        ];

        Schema::table('saas_domains', function (Blueprint $table) use ($columns): void {
            if ($columns['origin']) {
                $table->string('origin', 24)->default('platform')->after('status')->index();
            }
            if ($columns['verification_method']) {
                $table->string('verification_method', 16)->nullable()->after('origin');
            }
            if ($columns['verification_token']) {
                $table->string('verification_token', 128)->nullable()->after('verification_method');
            }
            if ($columns['verification_expires_at']) {
                $table->timestamp('verification_expires_at')->nullable()->after('verification_token')->index();
            }
            if ($columns['verification_attempts']) {
                $table->unsignedInteger('verification_attempts')->default(0)->after('verification_expires_at');
            }
        });
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() === 'tenant' || ! Schema::hasTable('saas_domains')) {
            return;
        }

        $columns = array_values(array_filter([
            'origin',
            'verification_method',
            'verification_token',
            'verification_expires_at',
            'verification_attempts',
        ], static fn (string $column): bool => Schema::hasColumn('saas_domains', $column)));

        Schema::table('saas_domains', function (Blueprint $table) use ($columns): void {
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
