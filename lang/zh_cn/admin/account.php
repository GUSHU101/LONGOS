<?php

/**
 * header.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-08-02 19:03:19
 * @modified   2022-08-02 19:03:19
 */

return [
    'create_token'                   => '系统集成令牌',
    'password_text'                  => '密码留空则不修改',
    'account_index'                  => '查看',
    'account_update'                 => '更新',
    'integration_intro'              => '只需在主站创建一枚 Cloud Mail 凭据，即可为全部有效租户发信。令牌使用 APP_KEY 加密保存并可随时显示；鉴权仍使用 SHA-256 哈希。',
    'integration_control_only'       => '系统集成令牌只在 LONGOS 主站控制域名中管理。租户子站会自动共享主站绑定，无需重复创建令牌。',
    'token_name'                     => '令牌名称',
    'token_name_default'             => 'LONGOS Cloud Mail',
    'token_expires'                  => '有效期',
    'token_days'                     => ':days 天',
    'create_cloud_mail_token'        => '创建 Cloud Mail 令牌',
    'token_created'                  => '系统集成令牌已创建。',
    'token_once_notice'              => '令牌可在本页随时重新显示。请把它配置为 Cloudflare Worker Secret：LONGOS_INTEGRATION_TOKEN。',
    'relay_url'                      => '中继地址',
    'worker_secret'                  => 'Worker Secret',
    'token_hint'                     => '系统令牌',
    'abilities'                      => '权限',
    'created_at'                     => '创建时间',
    'last_used_at'                   => '最后使用',
    'last_revealed_at'               => '最后显示',
    'reveal_count'                   => '显示次数',
    'expires_at'                     => '过期时间',
    'token_status'                   => '状态',
    'active'                         => '有效',
    'expired'                        => '已过期',
    'revoked'                        => '已撤销',
    'never_used'                     => '尚未使用',
    'revoke_token'                   => '撤销',
    'revoke_confirm'                 => '撤销后 Cloud Mail 将立即无法使用该令牌发信，是否继续？',
    'token_revoked'                  => '系统集成令牌已撤销。',
    'token_revoke_failed'            => '令牌不存在、已撤销或不属于当前管理员。',
    'legacy_token'                   => '旧版系统集成令牌',
    'show_token'                     => '显示令牌',
    'hide_token'                     => '隐藏令牌',
    'copy_token'                     => '复制令牌',
    'token_revealed'                 => '系统集成令牌已显示。',
    'token_reveal_failed'            => '该令牌无法显示。旧版令牌仅保存哈希，请重新创建；若为新版令牌，请检查 APP_KEY 是否已变更。',
    'copy_success'                   => '已复制',
    'legacy_token_endpoint_disabled' => '旧版明文令牌接口已停用，请刷新页面后创建新的有限权令牌。',
];
