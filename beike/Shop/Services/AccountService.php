<?php

/**
 * CartService.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-01-05 10:12:57
 * @modified   2022-01-05 10:12:57
 */

namespace Beike\Shop\Services;

use Beike\Models\Customer;
use Beike\Repositories\CustomerRepo;
use Beike\Repositories\VerifyCodeRepo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AccountService
{
    /**
     * 注册用户
     *
     * @param array $data // ['email', 'password']
     * @return mixed
     */
    public static function register(array $data)
    {
        $customerApproved            = system_setting('base.customer_approved', 0); // approve_customer为是否需要审核客户

        $data['customer_group_id'] = system_setting('base.default_customer_group_id', 0); // default_customer_group_id为默认客户组名称
        $data['from']              = $data['from'] ?? 'pc';
        $data['locale']            = locale();

        if ($data['email'] ?? '') {
            $data['name'] = substr($data['email'], 0, strrpos($data['email'], '@'));
        }
        $data['avatar'] = $data['avatar'] ?? '';
        $data['active'] = 1;

        if ($customerApproved) {
            $data['status'] = 'pending';
        } else {
            $data['status'] = 'approved';
        }

        hook_action('service.account.register.before', $data);

        $customer = CustomerRepo::create($data);

        try {
            $customer->notifyRegistration();
        } catch (\Throwable $e) {
            // Registration is durable even when a synchronous mail transport is unavailable.
            report($e);
        }

        hook_action('service.account.register.after', $customer);

        return $customer;
    }

    /**
     * 发送验证码通过$type方式，type为email或telephone
     * @param $email
     * @param $type
     * @return void
     */
    public static function sendVerifyCodeForForgotten($email, $type)
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        VerifyCodeRepo::deleteByAccount($email);
        VerifyCodeRepo::create([
            'account' => $email,
            'code'    => VerifyCodeRepo::encode((string) $email, $code),
        ]);

        Customer::query()->where('email', $email)->firstOrFail()->notifyVerifyCodeForForgotten($code);
    }

    /**
     * 验证验证码是否正确，并修改密码为新密码
     * @param        $code
     * @param        $account
     * @param        $password
     * @param string $type     $account类型，email代表$account为邮箱地址，telephone代表$account为手机号码
     * @return void
     * @throws \Exception
     */
    public static function verifyAndChangePassword($code, $account, $password, string $type = 'email')
    {
        DB::transaction(function () use ($code, $account, $password, $type): void {
            $verifyCode = VerifyCodeRepo::findByAccountForUpdate($account);
            if (! $verifyCode) {
                throw new \Exception(trans('shop/account.verify_code_error'));
            }
            if ($verifyCode->created_at->copy()->addMinutes(10) < Carbon::now()) {
                $verifyCode->delete();

                throw new \Exception(trans('shop/account.verify_code_expired'));
            }

            if (! VerifyCodeRepo::matches((string) $verifyCode->code, (string) $account, (string) $code)) {
                throw new \Exception(trans('shop/account.verify_code_error'));
            }

            if ($type == 'email') {
                $customer = CustomerRepo::findByEmail($account);
                if (! $customer) {
                    throw new \Exception(trans('shop/account.account_not_exist'));
                }
            } elseif ($type == 'telephone') {
                throw new \Exception('暂不支持手机号码找回密码');
            } else {
                throw new \Exception('找回密码类型错误');
            }
            CustomerRepo::update($customer, ['password' => $password]);
            $verifyCode->delete();
        }, 3);
    }
}
