<?php

namespace Beike\Services;

/** Explicit, deterministic migrations for persisted Builder page data. */
class BuilderDataMigrator
{
    /** @return array{data: array, migrated_from: int, migrated: bool} */
    public static function migrate(mixed $data): array
    {
        $data     = is_array($data) ? $data : [];
        $from     = max(1, (int) ($data['schema_version'] ?? 1));
        $migrated = $data;
        if ($from < 2 && array_is_list($migrated)) {
            $migrated = ['modules' => $migrated];
        }
        if ($from < 3) {
            $migrated['global_style'] = is_array($migrated['global_style'] ?? null) ? $migrated['global_style'] : [];
            $migrated['app_embeds']   = is_array($migrated['app_embeds'] ?? null) ? $migrated['app_embeds'] : [];
        }
        if ($from < 4) {
            foreach ($migrated['modules'] ?? [] as $index => $module) {
                if (! is_array($module)) {
                    continue;
                }
                $migrated['modules'][$index] = BuilderPageSchema::normalizeModule($module);
            }
        }
        if ($from < 5) {
            $migrated['page_metadata'] = is_array($migrated['page_metadata'] ?? null) ? $migrated['page_metadata'] : [];
        }
        $migrated['schema_version'] = BuilderPageSchema::VERSION;

        return ['data' => $migrated, 'migrated_from' => $from, 'migrated' => $from < BuilderPageSchema::VERSION];
    }
}
