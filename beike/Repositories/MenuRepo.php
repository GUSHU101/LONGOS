<?php

/**
 * FooterRepo.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-08-11 18:16:06
 * @modified   2022-08-11 18:16:06
 */

namespace Beike\Repositories;

use Beike\Services\BuilderStateService;

class MenuRepo
{
    /** A safe, serializable baseline for stores without menu settings yet. */
    public static function defaultMenuSetting(): array
    {
        return ['menus' => []];
    }

    /**
     * Menu settings are editable JSON and may also come from an old export.
     * Normalize the whole tree before either the native editor or storefront
     * sees it, so one partial menu record cannot take down the header.
     */
    public static function normalizeMenuSetting(mixed $menuSetting): array
    {
        if (! is_array($menuSetting)) {
            return self::defaultMenuSetting();
        }

        $menus      = is_array($menuSetting['menus'] ?? null) ? $menuSetting['menus'] : [];
        $normalized = [];
        foreach (array_slice($menus, 0, 20) as $menu) {
            if (! is_array($menu)) {
                continue;
            }
            $badge        = is_array($menu['badge'] ?? null) ? $menu['badge'] : [];
            $groups       = is_array($menu['childrenGroup'] ?? null) ? $menu['childrenGroup'] : [];
            $normalized[] = [
                'isFull'        => self::normalizeBoolean($menu['isFull'] ?? false, false),
                'badge'         => [
                    'isShow'     => self::normalizeBoolean($badge['isShow'] ?? false, false),
                    'name'       => self::normalizeLocalized($badge['name'] ?? []),
                    'bg_color'   => self::normalizeColor($badge['bg_color'] ?? '', '#FD560F'),
                    'text_color' => self::normalizeColor($badge['text_color'] ?? '', '#FFFFFF'),
                ],
                'link'          => self::normalizeLink($menu['link'] ?? []),
                'name'          => self::normalizeLocalized($menu['name'] ?? []),
                'isChildren'    => self::normalizeBoolean($menu['isChildren'] ?? false, false),
                'childrenGroup' => self::normalizeChildrenGroups($groups),
            ];
        }

        return ['menus' => $normalized];
    }

    /**
     * 处理页头编辑器数据
     *
     * @return array|mixed
     * @throws \Exception
     */
    public static function handleMenuData($menuSetting = [])
    {
        if (empty($menuSetting)) {
            $menuSetting = BuilderStateService::storefrontState()['menu'];
        }

        $menuSetting = self::normalizeMenuSetting($menuSetting);
        $locale      = locale();
        $menus       = $menuSetting['menus'];

        foreach ($menus as $index => $menu) {
            $menu['new_window']    = $menu['link']['new_window']        ?? false;

            try {
                $menu['link'] = handle_link($menu['link'])['link'] ?? '';
            } catch (\Throwable $exception) {
                logger()->notice('Ignoring a stale header menu link.', ['exception' => $exception->getMessage()]);
                $menu['link'] = '';
            }
            $menu['name']          = self::localizedText($menu['name'], $locale);
            $menu['badge']['name'] = self::localizedText($menu['badge']['name'], $locale);

            // `isChildren` is the editor's explicit dropdown switch. Older
            // code rendered a submenu whenever stale group data existed,
            // making the switch look functional while doing nothing.
            if ($menu['isChildren'] && $menu['childrenGroup']) {
                $menu['children_group'] = self::handleChildrenGroup($menu['childrenGroup']);
            }
            $menus[$index] = $menu;
        }

        return $menus;
    }

    /**
     * 处理头部 menu 子菜单数据
     *
     * @param $childrenGroups
     * @return mixed
     * @throws \Exception
     */
    private static function handleChildrenGroup(array $childrenGroups): array
    {
        $locale = locale();
        foreach ($childrenGroups as $groupIndex => $childrenGroup) {
            $childrenGroup['name'] = self::localizedText($childrenGroup['name'] ?? [], $locale);
            if ($childrenGroup['type'] == 'image') {
                $childrenGroup['image']['image'] = image_origin(self::localizedText($childrenGroup['image']['image'] ?? [], $locale));

                try {
                    $childrenGroup['image']['link'] = type_route($childrenGroup['image']['link']['type'], $childrenGroup['image']['link']['value']);
                } catch (\Throwable $exception) {
                    logger()->notice('Ignoring a stale header menu image link.', ['exception' => $exception->getMessage()]);
                    $childrenGroup['image']['link'] = '';
                }
            } elseif (! empty($childrenGroup['children'])) {
                foreach ($childrenGroup['children'] as $childrenIndex => $children) {
                    $fallbackText = self::localizedText($children['name'] ?? [], $locale);
                    $link         = is_array($children['link'] ?? null) ? $children['link'] : [];
                    $texts        = is_array($link['text'] ?? null) ? $link['text'] : [];
                    // The visual editor exposes a friendly “子链接名称”
                    // field. Legacy templates render link.text, so retain a
                    // name as the localized fallback instead of silently
                    // saving a submenu entry that cannot be seen.
                    if ($fallbackText !== '' && self::localizedText($texts, $locale) === '') {
                        $texts[$locale] = $fallbackText;
                    }
                    $link['text'] = $texts;
                    $destination  = is_scalar($link['value'] ?? null) ? trim((string) $link['value']) : '';
                    if ($destination === '') {
                        $link['link']                              = '';
                        $link['text']                              = $fallbackText;
                        $children['link']                          = $link;
                        $childrenGroup['children'][$childrenIndex] = $children;

                        continue;
                    }

                    try {
                        $children['link'] = handle_link($link);
                    } catch (\Throwable $exception) {
                        logger()->notice('Ignoring a stale header submenu link.', ['exception' => $exception->getMessage()]);
                        $children['link'] = ['link' => '', 'text' => $fallbackText, 'new_window' => self::normalizeBoolean($link['new_window'] ?? false, false)];
                    }
                    $childrenGroup['children'][$childrenIndex] = $children;
                }
            }
            $childrenGroups[$groupIndex] = $childrenGroup;
        }

        return $childrenGroups;
    }

    private static function normalizeChildrenGroups(array $groups): array
    {
        $normalized = [];
        foreach (array_slice($groups, 0, 12) as $index => $group) {
            if (! is_array($group)) {
                continue;
            }
            $type               = ($group['type'] ?? 'link') === 'image' ? 'image' : 'link';
            $image              = is_array($group['image'] ?? null) ? $group['image'] : [];
            $children           = is_array($group['children'] ?? null) ? $group['children'] : [];
            $normalizedChildren = [];
            foreach (array_slice($children, 0, 20) as $child) {
                if (! is_array($child)) {
                    continue;
                }
                $normalizedChildren[] = [
                    'name' => self::normalizeLocalized($child['name'] ?? []),
                    'link' => self::normalizeLink($child['link'] ?? []),
                ];
            }
            $id           = is_scalar($group['id'] ?? null) ? (string) $group['id'] : '';
            $normalized[] = [
                'id'       => preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) ? $id : 'group_' . $index,
                'name'     => self::normalizeLocalized($group['name'] ?? []),
                'type'     => $type,
                'image'    => [
                    'image' => self::normalizeLocalized($image['image'] ?? []),
                    'link'  => self::normalizeLink($image['link'] ?? []),
                ],
                'children' => $normalizedChildren,
            ];
        }

        return $normalized;
    }

    private static function normalizeLink(mixed $link): array
    {
        $link  = is_array($link) ? $link : [];
        $type  = is_scalar($link['type'] ?? null) ? (string) $link['type'] : 'custom';
        $value = is_scalar($link['value'] ?? null) ? (string) $link['value'] : '';

        return [
            'type'       => preg_match('/^[a-z][a-z0-9_]{0,63}$/', $type) ? $type : 'custom',
            'value'      => mb_substr($value, 0, 2048),
            'text'       => self::normalizeLocalized($link['text'] ?? []),
            'new_window' => self::normalizeBoolean($link['new_window'] ?? false, false),
        ];
    }

    private static function normalizeLocalized(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $normalized = [];
        foreach (array_slice($value, 0, 32, true) as $locale => $text) {
            if (is_string($locale) && is_scalar($text)) {
                $normalized[$locale] = mb_substr((string) $text, 0, 5000);
            }
        }

        return $normalized;
    }

    private static function normalizeColor(mixed $value, string $fallback): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtoupper($value) : $fallback;
    }

    private static function normalizeBoolean(mixed $value, bool $fallback): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off', '' => false,
                default => $fallback,
            };
        }

        return $fallback;
    }

    private static function localizedText(mixed $value, string $locale): string
    {
        if (! is_array($value)) {
            return is_scalar($value) ? (string) $value : '';
        }
        $localized = $value[$locale] ?? $value['en'] ?? reset($value) ?: '';

        return is_scalar($localized) ? (string) $localized : '';
    }
}
