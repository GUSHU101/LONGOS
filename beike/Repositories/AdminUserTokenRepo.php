<?php

/**
 * AdminUserTokenRepo.php
 *
 * @copyright  2023 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2023-04-20 10:21:25
 * @modified   2023-04-20 10:21:25
 */

namespace Beike\Repositories;

use Beike\Models\AdminUser;
use Beike\Models\AdminUserToken;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AdminUserTokenRepo
{
    public const TOKEN_PREFIX = 'longos_it_';

    public const ABILITY_CLOUD_MAIL_SEND = 'cloud-mail:send';

    public const ABILITY_CLOUD_MAIL_HEALTH = 'cloud-mail:health';

    public const ALLOWED_ABILITIES = [
        self::ABILITY_CLOUD_MAIL_SEND,
        self::ABILITY_CLOUD_MAIL_HEALTH,
    ];

    /** @return Collection<int, AdminUserToken> */
    public static function getTokenByAdminUser(AdminUser|int|null $adminUser): Collection
    {
        $adminUserId = self::getAdminUserId($adminUser);
        if ($adminUserId < 1) {
            return collect();
        }

        return AdminUserToken::query()
            ->where('admin_user_id', $adminUserId)
            ->latest('id')
            ->get();
    }

    /**
     * @param $token
     * @return mixed
     */
    public static function getAdminUserTokenByToken($token, ?string $ability = null): ?AdminUserToken
    {
        $plain = is_string($token) ? trim($token) : '';
        if (! preg_match('/^' . preg_quote(self::TOKEN_PREFIX, '/') . '[A-Za-z0-9]{64}$/D', $plain)) {
            return null;
        }

        $hash   = hash('sha256', $plain);
        $tokens = AdminUserToken::query()
            ->with('adminUser')
            ->where('token_hash', $hash)
            ->whereNull('revoked_at')
            ->where(static function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->whereHas('adminUser', static fn ($query) => $query->where('active', true))
            ->get();
        $token = $tokens->first(static fn (AdminUserToken $candidate): bool => $candidate->isUsable()
            && ($ability === null || $candidate->can($ability)));

        if (! $token) {
            return null;
        }

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinutes(5))) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $token;
    }

    /** @return array{model: AdminUserToken, plain_text_token: string} */
    public static function issue(
        AdminUser|int $adminUser,
        string $name,
        array $abilities,
        ?Carbon $expiresAt = null,
    ): array {
        $adminUserId = self::getAdminUserId($adminUser);
        $name        = trim($name);
        $abilities   = array_values(array_unique(array_filter($abilities, static fn ($ability): bool => is_string($ability))));

        if ($adminUserId < 1 || $name === '' || mb_strlen($name) > 80) {
            throw new InvalidArgumentException('Invalid integration token owner or name.');
        }
        if (! AdminUser::query()->whereKey($adminUserId)->where('active', true)->exists()) {
            throw new InvalidArgumentException('Integration token owner does not exist or is inactive.');
        }
        if ($abilities === [] || array_diff($abilities, self::ALLOWED_ABILITIES) !== []) {
            throw new InvalidArgumentException('Invalid integration token abilities.');
        }
        if ($expiresAt !== null && ($expiresAt->isPast() || $expiresAt->gt(now()->addDays(730)))) {
            throw new InvalidArgumentException('Invalid integration token expiry.');
        }

        $plain = self::TOKEN_PREFIX . Str::random(64);
        $hash  = hash('sha256', $plain);
        $model = AdminUserToken::query()->create([
            'admin_user_id' => $adminUserId,
            'name'          => $name,
            // Keep a hash in the compatibility column as well, so older code
            // cannot accidentally disclose a usable credential.
            'token'            => $hash,
            'token_hash'       => $hash,
            'token_ciphertext' => Crypt::encryptString($plain),
            'token_hint'       => substr($plain, -8),
            'abilities'        => $abilities,
            'expires_at'       => $expiresAt,
        ]);

        return ['model' => $model, 'plain_text_token' => $plain];
    }

    public static function reveal(AdminUser|int $adminUser, AdminUserToken|int $token): ?string
    {
        $adminUserId = self::getAdminUserId($adminUser);
        $tokenId     = $token instanceof AdminUserToken ? $token->getKey() : $token;
        $model       = AdminUserToken::query()
            ->whereKey($tokenId)
            ->where('admin_user_id', $adminUserId)
            ->first();

        if (! $model || ! filled($model->token_ciphertext)) {
            return null;
        }

        try {
            $plain = Crypt::decryptString($model->token_ciphertext);
        } catch (DecryptException) {
            return null;
        }

        if (! preg_match('/^' . preg_quote(self::TOKEN_PREFIX, '/') . '[A-Za-z0-9]{64}$/D', $plain)) {
            return null;
        }

        if (! hash_equals((string) $model->token_hash, hash('sha256', $plain))) {
            return null;
        }

        AdminUserToken::query()
            ->whereKey($model->getKey())
            ->where('admin_user_id', $adminUserId)
            ->update([
                // Re-encrypt with the current APP_KEY. This lets operators
                // retire APP_PREVIOUS_KEYS after every retained token has
                // been revealed or otherwise rotated once.
                'token_ciphertext' => Crypt::encryptString($plain),
                'last_revealed_at' => now(),
                'reveal_count'     => DB::raw('COALESCE(reveal_count, 0) + 1'),
            ]);

        return $plain;
    }

    public static function revoke(AdminUser|int $adminUser, AdminUserToken|int $token): bool
    {
        $adminUserId = self::getAdminUserId($adminUser);
        $tokenId     = $token instanceof AdminUserToken ? $token->getKey() : $token;

        return AdminUserToken::query()
            ->whereKey($tokenId)
            ->where('admin_user_id', $adminUserId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]) === 1;
    }

    /**
     * @param $adminUser
     * @param $tokens
     * @return void
     */
    public static function updateTokensByUser($adminUser, $tokens)
    {
        $adminUserId = self::getAdminUserId($adminUser);
        if (empty($adminUserId)) {
            return;
        }

        AdminUserToken::query()
            ->where('admin_user_id', $adminUserId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
        if (empty($tokens)) {
            return;
        }

        foreach ($tokens as $token) {
            if (! is_string($token) || strlen(trim($token)) < 32) {
                continue;
            }
            $plain = trim($token);
            $hash  = hash('sha256', $plain);
            AdminUserToken::query()->create([
                'admin_user_id' => $adminUserId,
                'name'          => 'Legacy integration token',
                'token'         => $hash,
                'token_hash'    => $hash,
                'token_hint'    => substr($plain, -8),
                'abilities'     => [],
                // The legacy bulk-update endpoint has no way to communicate
                // one-time display or least-privilege scopes. Preserve input
                // for compatibility/audit only; never create a usable secret.
                'revoked_at'    => now(),
            ]);
        }
    }

    /**
     * @param $adminUser
     * @return int|mixed
     */
    private static function getAdminUserId(AdminUser|int|null $adminUser): int
    {
        $adminUserId = 0;
        if ($adminUser instanceof AdminUser) {
            $adminUserId = (int) $adminUser->getKey();
        } elseif (is_int($adminUser)) {
            $adminUserId = $adminUser;
        }

        return $adminUserId;
    }
}
