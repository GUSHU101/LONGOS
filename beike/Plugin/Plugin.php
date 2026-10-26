<?php

/**
 * Plugin.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-06-29 20:27:21
 * @modified   2022-06-29 20:27:21
 */

namespace Beike\Plugin;

use Beike\Admin\Services\MarketingService;
use Beike\Repositories\PluginRepo;
use Beike\Repositories\SettingRepo;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class Plugin implements \ArrayAccess, Arrayable
{
    public const TYPES = [
        'payment',   // 支付方式
        'marketing', // 营销推广
        'shipping',  // 配送方式
        'theme',     // 主题模板
        'analysis',  // 数据分析
        'service',   // 客户服务
        'language',  // 语言翻译
        'feature',   // 其他功能
    ];

    protected $type;

    protected $path;

    protected $name;

    protected $description;

    protected $packageInfo;

    protected $dirName;

    protected $installed;

    protected $enabled;

    protected $version;

    protected $columns;

    protected $canUpdate;

    protected $lastModified;

    public function __construct(string $path, array $packageInfo)
    {
        $this->path        = $path;
        $this->packageInfo = $packageInfo;
    }

    public function __get($name)
    {
        return $this->packageInfoAttribute(Str::snake($name, '-'));
    }

    public function __isset($name)
    {
        return isset($this->{$name}) || $this->packageInfoAttribute(Str::snake($name, '-'));
    }

    public function packageInfoAttribute($name)
    {
        return Arr::get($this->packageInfo, $name);
    }

    /**
     * Set plugin Type
     *
     * @throws \Exception
     */
    public function setType(string $type): self
    {
        // if (! in_array($type, self::TYPES)) {
        //     throw new \Exception('Invalid plugin type, must be one of ' . implode(',', self::TYPES));
        // }
        $this->type = $type;

        return $this;
    }

    public function setDirname(string $dirName): self
    {
        $this->dirName = $dirName;

        return $this;
    }

    public function setName(string|array $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function setDescription(string|array $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function setInstalled(bool $installed): self
    {
        $this->installed = $installed;

        return $this;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function setVersion(string $version): self
    {
        $this->version = $version;

        return $this;
    }

    public function setLastModified(string $lastModified): self
    {
        $this->lastModified = $lastModified;

        return $this;
    }

    public function getLastModified(): string
    {
        return $this->lastModified;
    }

    public function setColumns(): self
    {
        $columnsPath = $this->path . DIRECTORY_SEPARATOR . 'columns.php';
        if (! file_exists($columnsPath)) {
            return $this;
        }
        // Column definitions are runtime data: some bundled plugins expose a
        // different panel on the control site and on child stores. `require`
        // is intentional here. `require_once` returns `true` after the first
        // tenant in a long-lived worker and would corrupt every later
        // plugin's column collection.
        $columns = require $columnsPath;
        if (! is_array($columns)) {
            throw new \RuntimeException("Plugin columns must return an array: {$this->dirName}");
        }
        $this->columns = $columns;

        return $this;
    }

    /**
     * 处理插件后台设置字段多语言 优先级: label > label_key
     * 有label字段则直接返回, label_key 则翻译
     */
    public function handleLabel(): void
    {
        $this->columns = collect($this->columns)->map(function ($item) {
            $item = $this->transLabel($item);
            if (isset($item['options'])) {
                $item['options'] = collect($item['options'])->map(function ($option) {
                    return $this->transLabel($option);
                })->toArray();
            }

            return $item;
        })->toArray();
    }

    /**
     * 翻译 label
     * @param $item
     * @return mixed
     */
    private function transLabel($item): mixed
    {
        foreach (['label', 'description', 'section', 'action_label'] as $field) {
            $languageKey = $item[$field . '_key'] ?? '';
            $value       = $item[$field]          ?? '';
            if (! empty($value) || ! is_string($languageKey) || $languageKey === '') {
                continue;
            }

            $replace = $item[$field . '_params'] ?? [];
            if (! is_array($replace)) {
                $replace = [];
            }
            $item[$field] = trans("{$this->dirName}::{$languageKey}", $replace);
        }

        return $item;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getLocaleName(?string $locale = null): string
    {
        return $this->localizedValue($this->name, $locale ?: admin_locale());
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function getLocaleDescription(?string $locale = null): string
    {
        return $this->localizedValue($this->description, $locale ?: admin_locale());
    }

    private function localizedValue(string|array|null $value, string $locale): string
    {
        if (! is_array($value)) {
            return (string) $value;
        }

        $normalized = str_replace('-', '_', strtolower(trim($locale)));
        $language   = explode('_', $normalized, 2)[0];
        foreach (array_unique([$locale, $normalized, $language, 'en']) as $candidate) {
            $translated = $value[$candidate] ?? '';
            if (is_scalar($translated) && trim((string) $translated) !== '') {
                return (string) $translated;
            }
        }

        foreach ($value as $translated) {
            if (is_scalar($translated) && trim((string) $translated) !== '') {
                return (string) $translated;
            }
        }

        return '';
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getDirname(): string
    {
        return $this->dirName;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getEditUrl(): string
    {
        return admin_route('plugins.edit', array_merge(request()->query(), ['code' => $this->code]));
    }

    public function getStatus(): bool
    {
        return SettingRepo::getPluginStatus($this->code);
    }

    public function getInstalled(): bool
    {
        return PluginRepo::installed($this->code);
    }

    public function getEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSetting($name = '')
    {
        if ($name) {
            return plugin_setting("{$this->code}.{$name}");
        }

        return plugin_setting($this->code);
    }

    /**
     * 获取插件对应的设置字段, 并获取已存储在DB的字段值
     *
     * @return array
     */
    public function getColumns(): array
    {
        $this->columns[] = SettingRepo::getPluginStatusColumn();
        $existValues     = SettingRepo::getPluginColumns($this->code);
        foreach ($this->columns as $index => $column) {
            $dbColumn = $existValues[$column['name']] ?? null;
            $value    = $dbColumn ? $dbColumn->value : null;
            if ($column['name'] == 'status') {
                $value = (int) $value;
            }
            $this->columns[$index]['value'] = $value;
        }

        return $this->columns;
    }

    /**
     * 字段验证
     * @param $requestData
     * @return \Illuminate\Contracts\Validation\Validator
     */
    public function validate($requestData): \Illuminate\Contracts\Validation\Validator
    {
        $rules      = array_column($this->columns, 'rules', 'name');
        $attributes = array_column($this->columns, 'label', 'name');

        return Validator::make($requestData, $rules, [], $attributes);
    }

    /**
     * 获取插件自定义编辑模板
     * @return string
     */
    public function getColumnView(): string
    {
        $viewFile = $this->getPath() . '/Views/admin/config.blade.php';
        if (file_exists($viewFile)) {
            return "{$this->dirName}::admin.config";
        }

        return '';
    }

    /**
     * 获取插件启动文件路径
     *
     * @return string
     */
    public function getBootFile(): string
    {
        return $this->getPath() . '/Bootstrap.php';
    }

    /**
     * Check plugin has license.
     *
     * @return bool
     * @throws \Exception
     */
    public function checkLicenseValid(): bool
    {
        $appDomain = request()->getHost();

        try {
            $domain         = new \Utopia\Domains\Domain($appDomain);
            $registerDomain = $domain->getRegisterable();
        } catch (\Exception $e) {
            $registerDomain = '';
        }

        if (empty($registerDomain)) {
            return true;
        }

        $license = MarketingService::getInstance()->checkLicense($this->code, $registerDomain);
        $status  = $license['status'] ?? 'fail';
        if ($status == 'fail') {
            SettingRepo::update('plugin', $this->code, ['status' => false]);

            if (str_contains($license['message'] ?? false, 'not authorized')) {
                throw new \Exception('此插件未经授权。请在插件市场购买。');
            }

            throw new \Exception($license['message'] ?? '插件授权未知错误, 请联系 beikeshop.com');
        }

        return $license['data']['has_license'] ?? false;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return array_merge([
            'name'    => $this->name,
            'version' => $this->getVersion(),
            'path'    => $this->path,
        ], $this->packageInfo);
    }

    /**
     * @param $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return Arr::has($this->packageInfo, $offset);
    }

    /**
     * @param $offset
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->packageInfoAttribute($offset);
    }

    /**
     * @param $offset
     * @param $value
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->packageInfo[] = $value;

            return;
        }

        $this->packageInfo = Arr::set($this->packageInfo, $offset, $value);
    }

    /**
     * @param $offset
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->packageInfo[$offset]);
    }

    public function setCanUpdate($flag): self
    {
        $this->canUpdate = $flag;

        return $this;
    }

    public function getCanUpdate(): bool
    {
        return $this->canUpdate;
    }
}
