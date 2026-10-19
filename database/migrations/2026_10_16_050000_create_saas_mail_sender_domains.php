<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDefaultConnection() === 'tenant'
            || ! Schema::hasTable('saas_sites')
            || ! Schema::hasTable('saas_domains')) {
            return;
        }

        if (! Schema::hasTable('saas_mail_sender_domains')) {
            Schema::create('saas_mail_sender_domains', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained('saas_sites')->cascadeOnDelete();
                $table->string('domain', 253)->unique();
                $table->timestamps();
            });
        }

        // Preserve domains accepted by the previous routing-domain based
        // implementation. New assignments are managed explicitly so a shop
        // host and its mail From domain no longer need to be identical.
        $configured = config('mail.relay.allowed_sender_domains', []);
        $configured = is_string($configured) ? explode(',', $configured) : $configured;
        $platform   = strtolower(trim((string) config('mail.relay.platform_sender_domain'), '.'));
        foreach (array_unique(array_filter(array_map(
            static fn (mixed $domain): string => strtolower(trim((string) $domain, " .\t\n\r\0\x0B")),
            is_array($configured) ? $configured : [],
        ))) as $senderDomain) {
            if ($senderDomain === $platform) {
                continue;
            }

            $labels     = explode('.', $senderDomain);
            $candidates = [];
            for ($index = 0, $last = count($labels) - 1; $index < $last; $index++) {
                $candidates[] = implode('.', array_slice($labels, $index));
            }
            $owner = DB::table('saas_domains')
                ->where('status', 'active')
                ->whereIn('host', $candidates)
                ->get(['site_id', 'host'])
                ->sortByDesc(static fn (object $domain): int => strlen((string) $domain->host))
                ->first();
            if ($owner) {
                DB::table('saas_mail_sender_domains')->insertOrIgnore([
                    'site_id'    => $owner->site_id,
                    'domain'     => $senderDomain,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDefaultConnection() === 'tenant') {
            return;
        }

        Schema::dropIfExists('saas_mail_sender_domains');
    }
};
