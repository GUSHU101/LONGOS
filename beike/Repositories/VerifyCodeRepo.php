<?php

/**
 * VerifyCodeRepo.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-07 15:22:05
 * @modified   2022-07-07 15:22:05
 */

namespace Beike\Repositories;

use Beike\Models\VerifyCode;

class VerifyCodeRepo
{
    public static function encode(string $account, string $code): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \RuntimeException('APP_KEY is required before verification codes can be created.');
        }

        return substr(hash_hmac('sha256', strtolower(trim($account)) . '|' . $code, $key), 0, 16);
    }

    public static function matches(string $storedCode, string $account, string $candidate): bool
    {
        $candidate = trim($candidate);
        $encoded   = self::encode($account, $candidate);

        // Accept one already-issued legacy plaintext code during a rolling
        // deployment. Every newly created code is stored as a keyed digest.
        return hash_equals($storedCode, $encoded)
            || (preg_match('/^\d{6}$/', $storedCode) === 1 && hash_equals($storedCode, $candidate));
    }

    /**
     * 创建一个记录
     * @param $data
     * @return int
     */
    public static function create($data)
    {
        $verifyCode = VerifyCode::query()->create($data);

        return $verifyCode;
    }

    /**
     * @param $id
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Model|null
     */
    public static function find($id)
    {
        return VerifyCode::query()->find($id);
    }

    public static function findByAccount($account)
    {
        return VerifyCode::query()->where('account', $account)->first();
    }

    public static function findByAccountForUpdate($account)
    {
        return VerifyCode::query()->where('account', $account)->lockForUpdate()->first();
    }

    public static function deleteByAccount($account)
    {
        return VerifyCode::query()->where('account', $account)->delete();
    }

    /**
     * @param $id
     * @return void
     */
    public static function delete($id)
    {
        $verifyCode = VerifyCode::query()->find($id);
        if ($verifyCode) {
            $verifyCode->delete();
        }
    }
}
