<?php

namespace Plugin\LongosInstantTranslate\Services;

use Illuminate\Support\Facades\Schema;
use Throwable;

final class LibrarySchema
{
    public const TABLE_NAMES = [
        'lit_translation_entries',
        'lit_provider_accounts',
        'lit_provider_usage',
        'lit_glossary_terms',
    ];

    public static function ready(): bool
    {
        try {
            foreach (self::TABLE_NAMES as $table) {
                if (! Schema::hasTable($table)) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
