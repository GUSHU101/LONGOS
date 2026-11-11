<?php

namespace Plugin\LongosInstantTranslate\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Plugin\LongosInstantTranslate\Jobs\ProcessTranslationLibrary;
use Plugin\LongosInstantTranslate\Services\InstantTranslateSettings;
use Plugin\LongosInstantTranslate\Services\LibrarySchema;

final class EnsureTranslationLibrary extends Command
{
    protected $signature = 'longos:translation-library:ensure';

    protected $description = 'Resume the tenant slow translation-library builder when work and a verified provider are available';

    public function handle(InstantTranslateSettings $settings): int
    {
        if (current_site() === null || ! LibrarySchema::ready() || ! $settings->libraryBuildEnabled()) {
            return self::SUCCESS;
        }

        $hasPending  = DB::table('lit_translation_entries')->where('status', 'pending')->exists();
        $hasProvider = DB::table('lit_provider_accounts')
            ->where('enabled', true)
            ->where('dispatch_enabled', true)
            ->whereNotNull('verified_at')
            ->where(function ($query): void {
                $query->whereNull('cooldown_until')->orWhere('cooldown_until', '<=', now());
            })->exists();

        if ($hasPending && $hasProvider) {
            ProcessTranslationLibrary::dispatch($settings->storageScope());
        }

        return self::SUCCESS;
    }
}
