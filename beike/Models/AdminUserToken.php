<?php

/**
 * AdminUserToken.php
 *
 * @copyright  2023 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2023-04-20 10:18:56
 * @modified   2023-04-20 10:18:56
 */

namespace Beike\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminUserToken extends Base
{
    protected $fillable = [
        'admin_user_id', 'name', 'token', 'token_hash', 'token_ciphertext', 'token_hint',
        'abilities', 'last_used_at', 'last_revealed_at', 'reveal_count', 'expires_at', 'revoked_at',
    ];

    protected $hidden = ['token', 'token_hash', 'token_ciphertext'];

    protected $casts = [
        'abilities'        => 'array',
        'last_used_at'     => 'datetime',
        'last_revealed_at' => 'datetime',
        'reveal_count'     => 'integer',
        'expires_at'       => 'datetime',
        'revoked_at'       => 'datetime',
    ];

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }

    public function can(string $ability): bool
    {
        $abilities = is_array($this->abilities) ? $this->abilities : [];

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && (bool) $this->adminUser?->active;
    }
}
