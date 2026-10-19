# LONGOS 正式发布指南

LONGOS 生产版只通过 **Git + Coolify 不可变 Docker 镜像**发布。不要在正在运行的容器中覆盖文件、更新 Composer/npm 依赖或手工运行数据库迁移。

## 发布前

1. 在功能分支完成审核、回归测试和数据迁移兼容检查。
2. 确保 `composer.lock` 与 `composer.json`、`package-lock.json` 与 `package.json` 一致。
3. 通过 GitHub Actions 的 PHP、前端、邮件引擎、依赖审计和 `docker compose config` 检查。
4. 在 Coolify 完成 MySQL 和 LONGOS 所有持久卷的异机备份。

## 发布

1. 将已审核的提交合并到 Coolify 绑定分支。
2. 由 Coolify Auto Deploy 自动部署，或在 **Deployments** 中点击 Deploy。
3. 构建过程从锁文件安装依赖并生成浏览器资源。
4. `app` 入口取得共享部署锁，新站自动安装，旧站只执行未应用的幂等升级。
5. 健康检查通过后，worker 和 scheduler 才启动并由 Coolify Caddy 接收流量。

`LONGOS_UPDATER_ENABLED=false` 是生产强制值。后台“系统更新”页只展示 Coolify 发布流程，不会修改容器代码。

## 标记版本

在与生产已验证提交相同的 Git commit 上创建不可变 `v*` 标签。GitHub Release workflow 会重新执行完整检查并创建发布说明；已存在的标签发布不会被覆盖。标签用于审计和回滚定位，不会触发容器内覆盖式更新。

## 发布后验证

- Coolify 中 `app`、`mysql`、`mail-relay` 为 healthy，worker 和 scheduler 正常运行。
- 主站、平台子域和一个已验证自定义域名均能通过 HTTPS 访问。
- 后台、结账、支付回调、队列、计划任务和邮件投递完成烟雾测试。
- LONGOS 生产预检不存在 FAIL，日志中没有反复迁移、证书申请或邮件重放。

## 回滚

1. 在 Coolify Deployments 选择上一个成功部署并 Redeploy。
2. 如果本版包含不可逆数据库迁移，同时恢复发布前与该镜像匹配的数据库备份。
3. 不要删除命名卷、`storage/installed`、APP_KEY 或 Caddy 证书数据来“强制重装”。

部署界面、DNS、旧站迁移和邮件引擎细节见 [Coolify + Caddy 部署指南](COOLIFY.md)。
