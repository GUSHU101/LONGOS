<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration')->index();
            });
        }

        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration')->index();
            });
        }

        $this->forgetPermissionCacheSafely();
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
    }

    private function forgetPermissionCacheSafely(): void
    {
        if (! app()->bound(PermissionRegistrar::class)) {
            return;
        }

        $registrar = app(PermissionRegistrar::class);

        try {
            $registrar->forgetCachedPermissions();
        } catch (\Throwable $exception) {
            // Cache invalidation must never roll back schema creation. The
            // process-local collection is always safe to discard, while the
            // persistent cache will be rebuilt on first successful access.
            $registrar->clearPermissionsCollection();
            report($exception);
        }
    }
};
