<?php

namespace App\Console\Commands;

use App\Saas\SiteDefinitionFactory;
use App\Saas\SiteProvisioner;
use App\Saas\TenantManager;
use Illuminate\Console\Command;
use Throwable;

class SaasSitesCreate extends Command
{
    protected $signature = 'saas:sites:create
        {--file= : CSV 或 JSON 批量文件}
        {--site=* : 单个站点 JSON，可重复传入}
        {--count=0 : 按规则批量生成站点数}
        {--prefix=site : 批量生成的 code 前缀}
        {--name-prefix=站点 : 批量生成的名称前缀}
        {--base-domain= : 子域名根域，例 example.com}
        {--db-prefix= : 数据库名前缀}
        {--admin-email= : 全局管理员邮箱}
        {--admin-password= : 全局管理员密码，留空则逐站生成}
        {--locale=zh_cn : 默认语言}
        {--timezone=Asia/Shanghai : 默认时区}
        {--no-migrate : 仅建库，不执行迁移}
        {--no-seed : 不写入初始数据}';

    protected $description = '批量创建独立数据库站点并自动绑定域名';

    public function handle(
        SiteDefinitionFactory $definitions,
        SiteProvisioner $provisioner,
        TenantManager $tenants,
    ): int {
        try {
            if (! $tenants->controlTablesReady()) {
                throw new \RuntimeException('SaaS 控制表未就绪。请在 Coolify 重新部署当前 Git 版本，容器入口会自动完成初始化和迁移。');
            }

            $defaults = array_filter([
                'prefix'          => (string) $this->option('prefix'),
                'name_prefix'     => (string) $this->option('name-prefix'),
                'base_domain'     => (string) ($this->option('base-domain') ?: config('saas.base_domain')),
                'database_prefix' => (string) ($this->option('db-prefix') ?: config('saas.database_prefix')),
                'admin_email'     => $this->option('admin-email'),
                'admin_password'  => $this->option('admin-password'),
                'locale'          => (string) $this->option('locale'),
                'timezone'        => (string) $this->option('timezone'),
            ], static fn ($value): bool => $value !== null && $value !== '');

            $items = [];
            if ($file = trim((string) $this->option('file'))) {
                $items = array_merge($items, $definitions->fromFile($file, $defaults));
            }
            if (($count = (int) $this->option('count')) > 0) {
                $items = array_merge($items, $definitions->fromCount($count, $defaults));
            }
            foreach ((array) $this->option('site') as $json) {
                $item = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($item)) {
                    throw new \InvalidArgumentException('--site 必须是 JSON 对象。');
                }
                $items[] = $definitions->normalize($item, $defaults);
            }

            if ($items === []) {
                throw new \InvalidArgumentException('请通过 --file、--site 或 --count 指定要创建的站点。');
            }

            $this->components->info('开始创建 ' . count($items) . ' 个站点。每个站点使用独立数据库。');
            $results = $provisioner->provisionMany(
                $items,
                ! (bool) $this->option('no-migrate'),
                ! (bool) $this->option('no-seed'),
            );

            $this->table(
                ['结果', 'Code', '域名', '数据库', '管理员', '初始密码 / 错误'],
                array_map(static fn (array $result): array => [
                    $result['ok'] ? '成功' : '失败',
                    $result['code'],
                    $result['url'] ?? '-',
                    $result['database'],
                    $result['admin_email'] ?? '-',
                    $result['ok']
                        ? ($result['admin_password'] ?? '-')
                        : (($result['error'] ?? '-') . (isset($result['admin_password']) ? ' | 初始密码: ' . $result['admin_password'] : '')),
                ], $results)
            );

            $failed = count(array_filter($results, static fn (array $result): bool => ! $result['ok']));
            if ($failed > 0) {
                $this->components->warn("有 {$failed} 个站点创建或域名同步失败，已在 saas_sites.meta.last_error 保留原因。");

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
