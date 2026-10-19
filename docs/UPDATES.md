# LONGOS 更新与回滚

Coolify 是生产环境唯一的整站更新入口。不要删除旧项目、覆盖容器内文件，也不要在服务器中执行 Composer、npm 或迁移命令。

## 自动更新

1. 在 Coolify 的 LONGOS 资源中连接 GitHub 仓库和正式分支。
2. 开启 Auto Deploy 或 GitHub webhook。
3. 推送经过 CI 验证的提交。
4. Coolify 构建新的不可变镜像；容器入口取得共享部署锁后自动运行 `saas:upgrade --if-needed`。
5. `app` 健康检查通过后 worker 和 scheduler 才启动。

`composer.lock` 与 `package-lock.json` 是生产依赖唯一来源。镜像构建失败或应用健康检查失败时，当前持久数据卷不会被删除。

## 始终保留的数据

- MySQL 控制库和租户数据；
- `APP_KEY` 与 Coolify 生成的 `SERVICE_*` 凭据；
- Laravel storage、上传、商品图片和插件卷；
- Caddy 证书数据（由 Coolify 全局代理保存）；
- 邮件 DKIM、Postfix 队列和中继幂等记录。

## 回滚

在 Coolify Deployments 中选择上一个成功构建并 Redeploy。代码会回到对应镜像，数据卷不会改变。

如果新版本包含不可逆数据库迁移，必须先恢复与旧镜像匹配的数据库备份。不要通过删除 `storage/installed` 或重新执行安装器来回滚。

## 备份

在每次正式发布前通过 Coolify 配置 MySQL 备份，并将备份复制到服务器外部对象存储。另行备份 `longos-storage`、上传、插件和邮件 DKIM 卷。Docker 持久卷本身不是备份。

## 应用内更新页

旧式文件覆盖更新器在 Coolify 部署中通过 `LONGOS_UPDATER_ENABLED=false` 关闭。它不能安全替换正在运行的镜像，也不应获得 Docker、Caddy 或宿主机权限。生产发布和回滚全部在 GitHub/Coolify 完成。

完整部署说明见 [Coolify + Caddy 部署指南](COOLIFY.md)。

