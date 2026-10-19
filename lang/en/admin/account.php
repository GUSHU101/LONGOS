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
    'create_token'                   => 'Integration Token',
    'password_text'                  => 'If the password is left blank, it will not be modified',
    'account_index'                  => 'View Personal Center',
    'account_update'                 => 'Update Personal Center',
    'integration_intro'              => 'Create one Cloud Mail credential on the control site for every active tenant. Tokens are encrypted with APP_KEY and can be revealed at any time; authentication still uses their SHA-256 hashes.',
    'integration_control_only'       => 'Integration tokens are managed only on the LONGOS control domain. Tenant sites automatically share the control-site binding and do not need their own tokens.',
    'token_name'                     => 'Token name',
    'token_name_default'             => 'LONGOS Cloud Mail',
    'token_expires'                  => 'Expires',
    'token_days'                     => ':days days',
    'create_cloud_mail_token'        => 'Create Cloud Mail token',
    'token_created'                  => 'The integration token was created.',
    'token_once_notice'              => 'This token can be revealed again on this page. Configure it as the Cloudflare Worker secret LONGOS_INTEGRATION_TOKEN.',
    'relay_url'                      => 'Relay URL',
    'worker_secret'                  => 'Worker secret',
    'token_hint'                     => 'Integration token',
    'abilities'                      => 'Abilities',
    'created_at'                     => 'Created',
    'last_used_at'                   => 'Last used',
    'last_revealed_at'               => 'Last revealed',
    'reveal_count'                   => 'Reveal count',
    'expires_at'                     => 'Expires',
    'token_status'                   => 'Status',
    'active'                         => 'Active',
    'expired'                        => 'Expired',
    'revoked'                        => 'Revoked',
    'never_used'                     => 'Never used',
    'revoke_token'                   => 'Revoke',
    'revoke_confirm'                 => 'Cloud Mail will immediately lose access through this token. Continue?',
    'token_revoked'                  => 'The integration token was revoked.',
    'token_revoke_failed'            => 'The token does not exist, is already revoked, or belongs to another administrator.',
    'legacy_token'                   => 'Legacy integration token',
    'show_token'                     => 'Show token',
    'hide_token'                     => 'Hide token',
    'copy_token'                     => 'Copy token',
    'token_revealed'                 => 'The integration token is now visible.',
    'token_reveal_failed'            => 'This token cannot be revealed. Legacy tokens only have a hash and must be recreated; for a new token, verify that APP_KEY has not changed.',
    'copy_success'                   => 'Copied',
    'legacy_token_endpoint_disabled' => 'The legacy plaintext-token endpoint is disabled. Refresh this page and create a new scoped token.',
];
