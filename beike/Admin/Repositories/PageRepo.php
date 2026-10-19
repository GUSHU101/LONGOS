<?php

/**
 * PageRepo.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-26 21:08:07
 * @modified   2022-07-26 21:08:07
 */

namespace Beike\Admin\Repositories;

use Beike\Models\Page;
use Beike\Services\RichTextSanitizer;
use Beike\Shop\Http\Resources\PageSimple;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PageRepo
{
    /**
     * 获取列表页数据
     *
     * @return LengthAwarePaginator
     */
    public static function getList(array $filters = []): LengthAwarePaginator
    {
        $builder = Page::query()->with([
            'description:id,page_id,title,summary',
        ])->orderByDesc('updated_at');

        // 分类筛选
        if (! empty($filters['category_id'])) {
            $builder->where('page_category_id', $filters['category_id']);
        }

        // 标题筛选
        if (! empty($filters['title'])) {
            $builder->whereHas('description', function ($query) use ($filters) {
                $query->where('title', 'like', '%' . trim($filters['title']) . '%');
            });
        }

        return $builder->paginate(perPage());
    }

    public static function findByPageId($pageId)
    {
        $page = Page::query()->findOrFail($pageId);
        $page->load(['descriptions']);

        return $page;
    }

    public static function getDescriptionsByLocale($pageId)
    {
        $page = self::findByPageId($pageId);

        return $page->descriptions->keyBy('locale')->toArray();
    }

    public static function createOrUpdate($data)
    {
        return DB::transaction(fn () => self::pushPage($data));
    }

    public static function pushPage($data)
    {
        $id = $data['id'] ?? 0;
        if ($id) {
            $page = Page::query()->findOrFail($id);
        } else {
            $page = new Page;
        }
        $page->fill([
            'page_category_id' => (int) ($data['page_category_id'] ?? 0),
            'image'            => $data['image'] ?? '',
            'position'         => (int) ($data['position'] ?? 0),
            'active'           => (bool) ($data['active'] ?? true),
            'author'           => $data['author'] ?? '',
            'views'            => (int) ($data['views'] ?? 0),
        ]);

        $page->saveOrFail();

        $descriptions = array_map(static function (array $description): array {
            $description['content'] = RichTextSanitizer::sanitize($description['content'] ?? '');

            return $description;
        }, is_array($data['descriptions'] ?? null) ? $data['descriptions'] : []);

        $page->descriptions()->delete();
        $page->descriptions()->createMany($descriptions);

        $products = $data['products'] ?? [];
        $page->pageProducts()->delete();

        if ($products) {
            $items = [];
            foreach ($products as $item) {
                $items[] = [
                    'product_id' => $item,
                ];
            }
            $page->pageProducts()->createMany($items);
        }

        $page->load(['descriptions', 'pageProducts']);

        return $page;
    }

    public static function deleteById($id)
    {
        $page = Page::query()->findOrFail($id);
        $page->descriptions()->delete();
        $page->delete();
    }

    public static function getNames($pageIds)
    {
        $pages = Page::query()->with('description')
            ->whereIn('id', $pageIds)
            ->get();

        $names = [];
        foreach ($pages as $page) {
            $names[] = [
                'id'   => $page->id,
                'name' => $page->description->title,
            ];
        }

        return $names;
    }

    /**
     * 根据文章 IDs 获取文章
     * @param $pageIds
     * @return AnonymousResourceCollection
     */
    public static function getPagesByIds($pageIds): AnonymousResourceCollection
    {
        $pageIds = array_values(array_unique(array_filter(array_map('intval', (array) $pageIds), static fn (int $id): bool => $id > 0)));
        if ($pageIds === []) {
            return PageSimple::collection(collect());
        }

        $query = Page::query()
            ->with('description')
            ->whereIn('id', $pageIds)
            ->where('active', true);
        $grammar = DB::connection()->getQueryGrammar();
        $column  = $grammar->wrapTable('pages') . '.' . $grammar->wrap('id');
        if (getDBDriver() === 'mysql') {
            $query->orderByRaw("FIELD({$column}, " . implode(',', $pageIds) . ')');
        } else {
            $caseWhen = collect($pageIds)->map(static fn (int $id, int $index): string => "WHEN {$column} = {$id} THEN {$index}")->implode(' ');
            $query->orderByRaw("CASE {$caseWhen} ELSE 99999999 END");
        }

        return PageSimple::collection($query->get());
    }

    /**
     * 页面内容自动完成
     *
     * @param $name
     * @return array
     */
    public static function autocomplete($name): array
    {
        $pages = Page::query()->with('description')
            ->whereHas('description', function ($query) use ($name) {
                $query->where('title', 'like', "%{$name}%");
            })->orderBy('updated_at', 'desc')->limit(50)->get();
        $results = [];
        foreach ($pages as $page) {
            $results[] = [
                'id'     => $page->id,
                'name'   => $page->description->title,
                'status' => $page->active,
            ];
        }

        return $results;
    }
}
