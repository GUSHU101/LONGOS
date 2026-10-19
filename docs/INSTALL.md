# LONGOS 生产部署

LONGOS 的唯一生产部署路径现为 **Coolify + Docker Compose + Coolify 集成 Caddy**。不再支持宝塔插件、宿主机 PHP/Nginx 安装或项目内第二套 Caddy。

完整图文步骤、环境变量、DNS、更新、回滚和故障排查见 [Coolify 部署指南](COOLIFY.md)。

部署完成后的流量路径为：

```text
Internet :80/:443
        │
        ▼
Coolify integrated Caddy
  ├─ automatic HTTPS
  ├─ LONGOS On-Demand TLS ask
  └─ unknown domains fail closed
        │ private coolify network
        ▼
LONGOS app (Nginx + PHP-FPM :80)
        │ private longos network
        ├─ MySQL 8.4
        ├─ queue worker
        ├─ scheduler
        └─ Postfix/OpenDKIM mail relay
```

首次部署只需在 Coolify 设置三个业务值：`DOMAIN`、`SAAS_BASE_DOMAIN`、`ADMIN_EMAIL`。应用密钥、数据库密码、管理员初始密码、Caddy 代理令牌和邮件 HMAC 密钥由 Coolify 生成并在后续部署中保持不变。

容器启动入口会自动判断状态：

- 首次部署：等待数据库，安全执行 `longos:install`，创建管理员和控制站点。
- 已安装站点：串行执行 `saas:upgrade --if-needed`，不重建数据库、不轮换 `APP_KEY`。
- Web 健康后：自动启动队列和计划任务。
- GitHub 更新：由 Coolify 重新构建镜像并部署，数据卷保持不变。

不需要执行 `install.sh`、`composer install`、`npm install`、数据库迁移或 Caddy 命令。

