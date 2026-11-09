<?php

namespace Plugin\LongosGlobalTranslate\Services;

use Illuminate\Support\Facades\DB;

/**
 * One database-row mutex per tenant/locale. Call only inside a transaction,
 * before checking active jobs, creating new jobs or changing publications.
 * Sorting the locale keys avoids lock-order inversions in bulk operations.
 */
final class TranslationMaintenanceGuard
{
    /** @param list<string> $locales */
    public static function lockLocales(array $locales): void
    {
        $locales = array_values(array_unique($locales));
        sort($locales, SORT_STRING);
        foreach ($locales as $locale) {
            DB::table('lgt_locale_maintenance')->insertOrIgnore([
                'locale' => $locale, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('lgt_locale_maintenance')->where('locale', $locale)->lockForUpdate()->first(['id']);
        }
    }
}
