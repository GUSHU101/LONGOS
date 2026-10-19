<?php

use Database\Seeders\PagesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace only unmistakable upstream/demo copy. A page edited by a merchant
     * is left untouched, so an application upgrade cannot overwrite tenant
     * legal policies, brand stories or editorial content.
     */
    public function up(): void
    {
        if (! Schema::hasTable('page_descriptions')) {
            return;
        }

        $defaults = collect((new PagesSeeder)->getPageDescriptions())->keyBy('id');

        DB::table('page_descriptions')
            ->whereIn('id', $defaults->keys()->all())
            ->orderBy('id')
            ->get()
            ->each(function (object $description) use ($defaults): void {
                $combined = implode("\n", array_map(
                    static fn (string $column): string => (string) ($description->{$column} ?? ''),
                    ['title', 'summary', 'content'],
                ));

                if (! $this->isLegacyDemoContent($combined)) {
                    return;
                }

                $replacement = $defaults->get((int) $description->id);
                if (! is_array($replacement)) {
                    return;
                }

                DB::table('page_descriptions')
                    ->where('id', $description->id)
                    ->update([
                        'title'            => $replacement['title'],
                        'summary'          => $replacement['summary'],
                        'content'          => $replacement['content'],
                        'meta_title'       => $replacement['meta_title'],
                        'meta_description' => $replacement['meta_description'],
                        'meta_keywords'    => $replacement['meta_keywords'],
                        'updated_at'       => now(),
                    ]);
            });
    }

    public function down(): void
    {
        // Removed demo identities and placeholder copy are intentionally not
        // restored because that would re-publish false contact information.
    }

    private function isLegacyDemoContent(string $content): bool
    {
        foreach ([
            'Delivery Information',
            'Everbright mall',
            '光大商城',
            'guangdawangluo.com',
            'support@example.com',
            '新闻博客做为重要的文字营销工具',
            'As an important text marketing tool',
        ] as $marker) {
            if (stripos($content, $marker) !== false) {
                return true;
            }
        }

        return false;
    }
};
