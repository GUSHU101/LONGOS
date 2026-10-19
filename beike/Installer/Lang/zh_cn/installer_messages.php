<?php

return [

    /*
     *
     * Shared translations.
     *
     */
    'title'        => 'LONGOS 安装程序',
    'next'         => '下一步',
    'finish'       => '安装',
    'status'       => '状态',

    /*
     *
     * Home page translations.
     *
     */
    'welcome'      => [
        'template_title'   => '欢迎',
        'title'            => '欢迎来到安装引导程序',
        'describe'         => '欢迎安装 LONGOS Commerce SaaS。向导将检测运行环境并完成平台初始化。',
        'message'          => '欢迎来到安装向导.',
        'next'             => '检测系统环境',
        'copyright_title'  => '版权说明',
        'copyright_btn'    => '已阅读协议并同意',
        'copyright_list_1' => '1、LONGOS 是本产品的统一名称与平台品牌；LONGOS 原创代码及修改部分的权利归 LONGOS 所有。',
        'copyright_list_2' => '2、本项目基于 BeikeShop 演进，上游代码、第三方组件及其版权仍归各权利人所有。',
        'copyright_list_3' => '3、分发与对外部署遵循 OSL-3.0，请保留源码中的许可证、版权与归属声明。',
        'statement_1'      => '免责声明：',
        'statement_2'      => '风险声明：您自行决定安装和运营 LONGOS；在 OSL-3.0 与适用法律允许的范围内，本软件不提供明示或默示担保。',
        'statement_3'      => '合法合规： 在使用本建站系统时，您同意不得从事任何非法、侵权或违反当地法律法规的经营活动。我们不对您的经营行为承担任何责任',
        'statement_4'      => '数据丢失和黑客攻击： 尽管我们采取了合理的安全措施来保护系统的数据和安全性，但信息传输丢失和黑客攻击风险依然存在。系统发生数据丢失、黑客攻击或其他安全事件与我们无关，您应自行负责采取措施，保护数据和系统安全',
    ],

    /*
     *
     * Requirements page translations.
     *
     */
    'requirements' => [
        'template_title' => '第一步 | 服务器环境',
        'title'          => '系统环境要求检测',
        'environment'    => '环境',
        'next'           => '检测权限',
    ],

    /*
     *
     * Permissions page translations.
     *
     */
    'permissions'  => [
        'template_title' => '第二步 | 目录权限',
        'title'          => '目录权限检测',
        'table'          => '目录',
        'next'           => '配置环境参数',
    ],

    /*
    *
    * Environment page translations.
    *
    */
    'environment'  => [
        'template_title'                       => '第三步 | 系统参数',
        'title'                                => 'SaaS 平台参数配置',
        'app_url_label'                        => '您的应用URL',
        'database_link'                        => '数据库链接',
        'admin_info'                           => '设置后台账号密码',
        'app_url_placeholder'                  => '输入您的应用URL',
        'db_connection_failed'                 => '无法连接到数据库！',
        'env_write_failed'                     => '无法写入 .env 配置文件，请检查项目根目录的写入权限。',
        'installation_session_expired'         => '安装会话已失效，请返回系统参数配置页后重新提交。',
        'installation_failed'                  => '安装进程意外中断，请返回配置页重试；安装器会从已完成的阶段继续。',
        'insufficient_memory_limit'            => '当前 PHP memory_limit 为 :current，网页安装至少需要 :required。请提高容器内存限制后重新部署。',
        'operation_lock_failed'                => '无法创建安装互斥锁，请检查 storage/app 目录的写入权限。',
        'operation_in_progress'                => '另一个安装请求仍在执行，请稍后刷新；请不要重复点击安装。',
        'finalization_failed'                  => '安装数据已完成，但最终页生成失败。请重试，安装器会安全继续。',
        'db_connection_label'                  => '数据库类型',
        'db_connection_failed_host_port'       => '数据库主机或端口错误！',
        'db_connection_failed_user_password'   => '数据库账号或密码错误！',
        'db_connection_failed_database_name'   => '数据库名不存在或者错误！',
        'db_connection_failed_invalid_version' => '数据库版本必须大于5.7！',
        'db_connection_label_mysql'            => 'MySQL',
        'db_connection_label_sqlite'           => 'SQLite',
        'db_connection_label_pgsql'            => 'PostgreSQL',
        'db_connection_label_sqlsrv'           => 'SQL Server',
        'db_host_label'                        => '数据库主机',
        'db_host_placeholder'                  => '输入数据库主机ip或url',
        'db_port_label'                        => '数据库端口',
        'db_port_placeholder'                  => '输入数据库端口',
        'db_name_label'                        => '数据库名',
        'db_name_placeholder'                  => '输入数据库名',
        'db_username_label'                    => '数据库账号',
        'db_username_placeholder'              => '输入数据库账号',
        'db_password_label'                    => '数据库账号密码',
        'db_password_placeholder'              => '输入数据库账号密码',
        'saas_info'                            => 'SaaS 租户配置',
        'saas_info_help'                       => '安装完成后默认启用 SaaS，可在后台创建租户站点并自动分配子域名。',
        'saas_database_mode'                   => '租户数据隔离方式',
        'saas_database_mode_prefix'            => '简易模式（推荐）— 共用当前数据库，无需 root',
        'saas_database_mode_prefix_help'       => '每个租户使用独立表前缀，直接复用上方数据库账号。',
        'saas_database_mode_database'          => '高级模式 — 每个租户独立数据库',
        'saas_database_mode_database_help'     => '隔离级别更高，但必须提供具有建库和授权权限的数据库管理账号。',
        'saas_prefix_mode_help'                => '推荐模式：只需一个数据库和运行账号，后续租户由 LONGOS 自动创建隔离表命名空间。',
        'https_recommended'                    => '当前通过 HTTP 访问。生产环境请使用 Coolify + Caddy 部署并通过 HTTPS 完成初始化。',
        'web_installer_tls_notice'             => '网页向导无法配置服务器级代理。生产环境请使用 Coolify Compose；容器会自动初始化数据库，Coolify Caddy 会管理主站和租户 HTTPS。',
        'saas_base_domain'                     => '租户根域名',
        'saas_base_domain_help'                => '例如 example.com，租户 brand1 将自动使用 brand1.example.com。',
        'saas_database_prefix'                 => '独立租户数据库名前缀',
        'saas_dns_target'                      => '子域名 DNS 指向',
        'saas_dns_target_help'                 => '填写服务器公网 IP 或主机名；安装后需将 *.:domain 的 A/CNAME 记录指向该值。',
        'saas_tenant_admin_username'           => '建站数据库管理账号',
        'saas_tenant_admin_password'           => '建站数据库管理密码',
        'saas_tenant_admin_help'               => '用于自动创建租户数据库并授权，需具备 CREATE DATABASE 和 GRANT 权限。可使用 MySQL root 或专用管理账号；若与上方数据库账号相同，密码留空时自动沿用。',
        'saas_admin_connection_failed'         => '无法使用建站数据库管理账号连接数据库。',
        'saas_admin_permission_failed'         => '建站数据库管理账号缺少创建数据库或授权所需的权限。',
        'install_state_write_failed'           => '无法保存安装恢复状态，请检查 storage/app 目录写权限。',
        'admin_email'                          => '后台账号',
        'admin_password'                       => '后台密码',
        'admin_password_confirmation'          => '确认后台密码',
        'admin_password_confirmation_mismatch' => '两次输入的后台密码不一致。',
        'install'                              => '安装',
        'ajax_database_parameters'             => '检测数据库参数...',
        'ajax_database_success'                => '数据库连接成功',
        'error_email'                          => '请填写正确的邮箱地址',
        'table_already_exists'                 => '发现数据库中存在数据表，请先清空或者备份再安装!',
        'php_extension'                        => '请安装相应的PHP扩展',
        'down_phpversion'                      => '请安装或切换至 PHP 8.2 及以上版本',
    ],

    'installing'   => [
        'template_title'  => '正在安装 LONGOS',
        'title'           => '正在安装 LONGOS Commerce SaaS',
        'progress'        => '安装进度',
        'schema'          => '正在创建并升级数据库结构…',
        'schema_complete' => '数据库结构迁移已完成。',
        'seed'            => '正在写入商城、国家和省州基础数据…',
        'administrator'   => '正在创建平台管理员…',
        'saas'            => '正在注册 SaaS 控制站与主域名…',
        'finalize'        => '正在启用租户路由并完成配置…',
        'keep_open'       => '请保持本页打开。每个阶段都会独立保存，即使请求中断也可以安全续装，不会重复已完成的工作。',
        'continue'        => '继续安装',
    ],

    /*
     *
     * Final page translations.
     *
     */
    'final'        => [
        'template_title'    => '安装完成',
        'title'             => '获取安装结果',
        'finished'          => '恭喜您，系统安装成功，赶快体验吧',
        'database_binding'  => '已绑定数据库：:connection · :host / :database',
        'saas_ready'        => 'SaaS 控制站已启用，现在可以在后台的“租户站点”中直接创建子站。',
        'wildcard_dns'      => '请在 DNS 服务商添加：*.:domain → :target',
        'wildcard_server'   => '请将 *.:domain 泛解析指向 Coolify 服务器；Caddy 会按需签发精确域名证书。',
        'server_public_ip'  => '服务器公网 IP',
        'to_front'          => '访问前台',
        'to_admin'          => '访问后台',
        'nginx_alert'       => 'Nginx 服务器需要配置伪静态规则，点击 <a href="https://docs.beikeshop.com/install/source.html#nginx" target="_blank">查看上游兼容配置</a>',
    ],
];
