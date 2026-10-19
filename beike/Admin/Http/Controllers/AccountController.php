<?php

/**
 * BrandController.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-27 21:17:04
 * @modified   2022-07-27 21:17:04
 */

namespace Beike\Admin\Http\Controllers;

use App\Saas\Models\Site;
use App\Saas\TenantManager;
use Beike\Models\AdminUserToken;
use Beike\Repositories\AdminUserTokenRepo;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        $user                 = current_user();
        $integrationAvailable = $this->integrationAvailable($request);
        $data                 = [
            'current_user' => $user,
            'tokens'       => AdminUserTokenRepo::getTokenByAdminUser($user)->map(static fn (AdminUserToken $token): array => [
                'id'               => $token->id,
                'name'             => $token->name ?: trans('admin/account.legacy_token'),
                'token_hint'       => $token->token_hint,
                'can_reveal'       => filled($token->token_ciphertext),
                'abilities'        => $token->abilities ?: [],
                'created_at'       => optional($token->created_at)->toIso8601String(),
                'last_used_at'     => optional($token->last_used_at)->toIso8601String(),
                'last_revealed_at' => optional($token->last_revealed_at)->toIso8601String(),
                'reveal_count'     => (int) $token->reveal_count,
                'expires_at'       => optional($token->expires_at)->toIso8601String(),
                'revoked_at'       => optional($token->revoked_at)->toIso8601String(),
            ])->values(),
            'relay_url'             => $this->relayUrl($request),
            'integration_available' => $integrationAvailable,
        ];

        return view('admin::pages.account.index', $data);
    }

    public function update(Request $request)
    {
        // The old endpoint accepted caller-provided plaintext tokens and
        // replaced the complete token set. Keep the route during rolling
        // upgrades, but never let an old cached UI downgrade scoped secrets.
        return json_fail(trans('admin/account.legacy_token_endpoint_disabled'), [], 410);
    }

    public function storeToken(Request $request)
    {
        if (! $this->integrationAvailable($request)) {
            return json_fail(trans('admin/account.integration_control_only'), [], 403);
        }

        $data = $request->validate([
            'name'         => ['required', 'string', 'max:80', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'expires_days' => ['required', 'integer', 'min:1', 'max:730'],
        ]);
        $issued = AdminUserTokenRepo::issue(
            current_user(),
            trim($data['name']),
            [
                AdminUserTokenRepo::ABILITY_CLOUD_MAIL_SEND,
                AdminUserTokenRepo::ABILITY_CLOUD_MAIL_HEALTH,
            ],
            now()->addDays((int) $data['expires_days']),
        );

        return json_success(trans('admin/account.token_created'), [
            'id'                   => $issued['model']->id,
            'plain_text_token'     => $issued['plain_text_token'],
            'token_hint'           => $issued['model']->token_hint,
            'expires_at'           => optional($issued['model']->expires_at)->toIso8601String(),
            'relay_url'            => $this->relayUrl($request),
            'worker_secret_name'   => 'LONGOS_INTEGRATION_TOKEN',
            'worker_variable_name' => 'LONGOS_RELAY_URL',
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma'        => 'no-cache',
        ]);
    }

    public function destroyToken(Request $request, AdminUserToken $token)
    {
        if (! $this->integrationAvailable($request)) {
            return json_fail(trans('admin/account.integration_control_only'), [], 403);
        }

        if (! AdminUserTokenRepo::revoke(current_user(), $token)) {
            return json_fail(trans('admin/account.token_revoke_failed'), [], 404);
        }

        return json_success(trans('admin/account.token_revoked'));
    }

    public function revealToken(Request $request, AdminUserToken $token)
    {
        if (! $this->integrationAvailable($request)) {
            return json_fail(trans('admin/account.integration_control_only'), [], 403);
        }

        $plain = AdminUserTokenRepo::reveal(current_user(), $token);
        if ($plain === null) {
            return json_fail(trans('admin/account.token_reveal_failed'), [], 404);
        }
        $token->refresh();

        return json_success(trans('admin/account.token_revealed'), [
            'plain_text_token' => $plain,
            'last_revealed_at' => optional($token->last_revealed_at)->toIso8601String(),
            'reveal_count'     => (int) $token->reveal_count,
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma'        => 'no-cache',
        ]);
    }

    private function integrationAvailable(Request $request): bool
    {
        if (! (bool) config('saas.enabled')) {
            return true;
        }

        $site = Site::current();
        if (! $site || ! (bool) data_get($site->meta, 'control_site', false)) {
            return false;
        }

        return app(TenantManager::class)->isControlHost($request->getHost());
    }

    private function relayUrl(Request $request): string
    {
        if ((bool) config('saas.enabled') && $this->integrationAvailable($request)) {
            return rtrim($request->getSchemeAndHttpHost(), '/') . '/_longos/mail-relay';
        }

        return (string) config('mail.relay.public_url');
    }
}
