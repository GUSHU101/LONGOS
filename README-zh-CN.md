# LONGOS Commerce SaaS

![LONGOS](public/image/longos-logo.svg)

[![LONGOS CI](https://github.com/GUSHU101/LONGOS/actions/workflows/ci.yml/badge.svg)](https://github.com/GUSHU101/LONGOS/actions/workflows/ci.yml)

LONGOS 是面向品牌方、跨境团队与多站点运营商的多租户电商 SaaS 平台。平台在一个控制面中批量创建和管理相互隔离的商城，自动分配平台域名，并允许租户通过 DNS 所有权验证绑定自己的域名。

> 一个平台，运营每一个独立站。

## 核心能力

- 批量站点：幂等创建、生命周期编排、失败回滚、审计日志与健康检查。
- 租户隔离：独立数据库上下文、缓存、会话、文件、邮件、主题和队列上下文。
- 域名自动化：平台子域名自动生成；自有域名通过 TXT 挑战验证后启用。
- 安全路由：未知域名失败关闭，未验证域名不会进入租户应用。
- 品牌分层：控制台和平台身份固定为 LONGOS；每个租户可自定义前台名称、Logo、配色和 SEO。
- 可扩展架构：Laravel 12、Spatie Laravel Multitenancy v4，以及兼容既有插件和主题的扩展层。

## 架构边界

LONGOS 是项目与平台的统一产品身份。`Beike\\` 命名空间、部分数据库结构和兼容命令属于上游兼容边界，不代表产品品牌。运行时只使用一套多租户切换机制，避免多个 tenancy 包同时接管连接、队列和请求生命周期。

## 快速开始

生产环境统一使用 **Coolify + Docker Compose + Coolify 集成 Caddy**。在 Coolify 中从 GitHub 创建 Docker Compose 应用，设置 `DOMAIN`、`SAAS_BASE_DOMAIN` 和 `ADMIN_EMAIL` 后点击 Deploy；镜像构建、数据库、首次安装、迁移、队列、计划任务、邮件引擎和 HTTPS 都会自动完成，不需要服务器命令或宝塔插件。

第一次部署请按[生产配置教程](docs/CONFIGURATION.md)逐项填写，再参阅 [Coolify + Caddy 部署指南](docs/COOLIFY.md)处理迁移、回滚和故障排查。

部署前可一键执行完整回归、生产资源构建和前端生产依赖安全审计：

```bash
npm run check
```

`longos:install` 和旧的 `beikeshop:install` 别名仍保留给开发测试；生产容器入口会自动、安全地调用它，且不会覆盖非空数据库或重新生成现有 `APP_KEY`。

## SaaS 运维入口

内置 Postfix/OpenDKIM 会随 Coolify Compose 自动部署，Cloud Mail 无需另行安装服务器组件。详见[内置邮件服务器](docs/MAIL.md)。

```bash
php artisan saas:health --active-only
php artisan saas:domains:verify-pending
php artisan saas:run process:order --continue-on-error
```

详细的租户创建、数据库隔离、域名验证与控制面 API 见 [SaaS 运维文档](docs/SAAS.md)。品牌用法、租户白标边界与视觉规范见 [LONGOS 品牌规范](docs/BRAND.md)。

## 自有域名流程

1. 租户提交自己的域名。
2. LONGOS 返回唯一的 `_longos-challenge` TXT 记录和值。
3. 租户在 DNS 服务商处添加该记录。
4. LONGOS 同时校验流量 DNS，并暂时授权 Coolify Caddy 为该精确域名签发证书。
5. 只有签名 HTTPS 探测真正返回当前 LONGOS 实例后才标记启用；无需重载代理或执行服务器命令。

## 文档入口

- [生产配置教程：从 GitHub 到 Coolify 首次上线](docs/CONFIGURATION.md)
- [Coolify + Caddy 部署、迁移、备份与回滚](docs/COOLIFY.md)
- [内置邮件引擎与 Cloud Mail 对接](docs/MAIL.md)
- [SaaS 租户和域名运维](docs/SAAS.md)

## 项目归属与许可证

LONGOS 是本发行版的产品名称、控制面身份和新增能力的统一归属标识。LONGOS 原创代码及修改部分的版权归 LONGOS 所有。

本项目基于 [BeikeShop](https://github.com/beikeshop/beikeshop) 演进。上游代码与第三方组件的版权、商标和归属仍归各自权利人所有；源码中的上游声明为法律合规信息，不属于产品对外品牌。项目依照 [OSL-3.0](LICENSE) 分发，对外部署和再分发时请遵守该许可证，保留必要声明并提供相应源码。
