<?php

namespace Beike\Channels;

use Beike\Models\MailLog;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class BkMailChannel extends MailChannel
{
    public function send($notifiable, Notification $notification)
    {
        try {
            return parent::send($notifiable, $notification);

        } catch (\Throwable $e) {
            // 尝试通过邮件信息匹配找到对应的记录并更新状态
            $this->handleMailFailure($notifiable, $notification, $e->getMessage());
            Log::warning('Queued mail delivery failed and will follow the queue retry policy.', [
                'notification' => $notification::class,
                'exception'    => $e::class,
            ]);

            // Queued notifications must fail visibly so Laravel can retry and
            // eventually place the job in failed_jobs. Swallowing a relay
            // outage here would incorrectly mark an undelivered order email
            // as successfully processed.
            throw $e;
        }
    }

    /**
     * 处理邮件发送失败
     */
    private function handleMailFailure($notifiable, $notification, $errorMessage)
    {
        try {
            // 尝试通过邮件信息匹配找到最近的 pending 记录
            $mailLog = $this->findPendingMailLog($notifiable, $notification);

            if ($mailLog) {
                // 更新现有记录状态为失败
                $this->updateMailLogStatus($mailLog->id, $errorMessage);
            } else {
                // 如果没有找到对应的记录，创建新的失败记录
                $this->createFailedMailLog($notifiable, $notification, $errorMessage);
            }
        } catch (\Throwable $e) {
            Log::error('处理邮件失败记录失败。', [
                'notification' => get_class($notification),
                'exception'    => $e::class,
            ]);
        }
    }

    /**
     * 查找对应的待发送邮件记录
     */
    private function findPendingMailLog($notifiable, $notification)
    {
        try {
            $deliveryId = $this->deliveryIdFromNotification($notifiable, $notification);
            if ($deliveryId !== '') {
                return MailLog::query()
                    ->where('delivery_id', $deliveryId)
                    ->where('status', MailLog::STATUS_PENDING)
                    ->first();
            }

            $mailData = $this->extractMailDataFromNotification($notifiable, $notification);

            // 查找最近创建的相同收件人和主题的 pending 记录
            $query = MailLog::where('status', MailLog::STATUS_PENDING)
                ->where('to_email', $mailData['to_email'])
                ->orderBy('created_at', 'desc');

            // 如果有主题，也按主题匹配
            if (! empty($mailData['subject'])) {
                $query->where('subject', $mailData['subject']);
            }

            // 查找最近5分钟内创建的记录
            $query->where('created_at', '>=', now()->subMinutes(5));

            return $query->first();
        } catch (\Throwable $e) {
            Log::error('查找待发送邮件记录失败。', ['exception' => $e::class]);

            return null;
        }
    }

    /**
     * 更新邮件记录状态为失败
     */
    private function updateMailLogStatus($mailLogId, $errorMessage)
    {
        try {
            $mailLog = MailLog::find($mailLogId);
            if ($mailLog) {
                $mailLog->update([
                    'status'        => MailLog::STATUS_FAILED,
                    'error_message' => MailLog::sanitizeError($errorMessage),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('更新邮件记录状态失败。', [
                'mail_log_id' => $mailLogId,
                'exception'   => $e::class,
            ]);
        }
    }

    /**
     * 创建失败的邮件记录
     */
    private function createFailedMailLog($notifiable, $notification, $errorMessage)
    {
        try {
            $mailData = $this->extractMailDataFromNotification($notifiable, $notification);

            if ($mailData['delivery_id'] !== '') {
                $existing = MailLog::query()->where('delivery_id', $mailData['delivery_id'])->first();
                if ($existing) {
                    if ($existing->status !== MailLog::STATUS_SENT) {
                        $existing->update([
                            'status'          => MailLog::STATUS_FAILED,
                            'error_message'   => MailLog::sanitizeError($errorMessage),
                            'last_attempt_at' => now(),
                        ]);
                    }

                    return;
                }
            }

            MailLog::create([
                'delivery_id'     => $mailData['delivery_id'] ?: null,
                'to_email'        => $mailData['to_email'],
                'to_name'         => $mailData['to_name'],
                'from_email'      => $mailData['from_email'],
                'from_name'       => $mailData['from_name'],
                'subject'         => $mailData['subject'],
                'content'         => $mailData['content'],
                'mail_type'       => $mailData['mail_type'],
                'transport'       => $mailData['transport'],
                'status'          => MailLog::STATUS_FAILED,
                'attempts'        => 1,
                'error_message'   => MailLog::sanitizeError($errorMessage),
                'headers'         => $mailData['headers'],
                'attachments'     => $mailData['attachments'],
                'last_attempt_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('创建失败邮件记录失败。', [
                'notification' => get_class($notification),
                'exception'    => $e::class,
            ]);
        }
    }

    /**
     * 从通知中提取邮件数据
     */
    private function extractMailDataFromNotification($notifiable, $notification)
    {
        $data = [
            'to_email'    => $notifiable->email ?? '',
            'to_name'     => $notifiable->name  ?? '',
            'from_email'  => '',
            'from_name'   => '',
            'subject'     => '',
            'content'     => '',
            'mail_type'   => 'other',
            'transport'   => $this->getCurrentTransport(),
            'headers'     => [],
            'attachments' => [],
            'delivery_id' => $this->deliveryIdFromNotification($notifiable, $notification),
        ];

        try {
            // 获取邮件消息
            $message = $notification->toMail($notifiable);

            if ($message) {
                // 获取发件人信息
                $from               = $message->from     ?? config('mail.from.address');
                $fromName           = $message->fromName ?? config('mail.from.name');
                $data['from_email'] = is_array($from) ? $from[0] : $from;
                $data['from_name']  = is_array($fromName) ? $fromName[0] : $fromName;

                // 获取主题
                $data['subject'] = $message->subject ?? '';

                // 获取内容
                if (method_exists($message, 'render')) {
                    $data['content'] = $message->render();
                }

                // 检测邮件类型
                $data['mail_type'] = $this->detectMailType($data['subject'], $data['content']);
            }
        } catch (\Throwable $e) {
            Log::error('提取邮件数据失败。', ['exception' => $e::class]);
        }

        return $data;
    }

    /**
     * 检测邮件类型
     */
    private function detectMailType($subject, $content)
    {
        $subject = strtolower($subject);
        $content = strtolower($content);

        // 根据主题关键词判断邮件类型
        if (strpos($subject, '注册') !== false || strpos($subject, 'registration') !== false) {
            return 'customer_registration';
        }

        if (strpos($subject, '忘记密码') !== false || strpos($subject, 'forgotten') !== false || strpos($subject, 'password') !== false) {
            return 'customer_forgotten';
        }

        if (strpos($subject, '订单') !== false || strpos($subject, 'order') !== false) {
            if (strpos($subject, '新') !== false || strpos($subject, 'new') !== false) {
                return 'order_new';
            }

            return 'order_update';
        }

        if (strpos($subject, '退货') !== false || strpos($subject, 'return') !== false || strpos($subject, 'rma') !== false) {
            return 'rma_new';
        }

        if (strpos($subject, '管理员') !== false || strpos($subject, 'admin') !== false) {
            return 'admin_forgotten';
        }

        return 'other';
    }

    /**
     * 获取当前邮件传输方式
     */
    private function getCurrentTransport()
    {
        try {
            // 获取当前默认的邮件驱动
            $defaultMailer = Config::get('mail.default', 'smtp');

            if ($defaultMailer === 'self_hosted') {
                return 'self_hosted';
            }

            // 获取对应驱动的传输方式
            $mailerConfig = Config::get("mail.mailers.{$defaultMailer}");

            if (is_array($mailerConfig) && isset($mailerConfig['transport'])) {
                return $mailerConfig['transport'];
            }

            // 如果没有明确的传输方式配置，返回驱动名称
            return $defaultMailer;

        } catch (\Throwable $e) {
            Log::error('获取邮件传输方式失败。', ['exception' => $e::class]);

            return 'unknown';
        }
    }

    private function deliveryIdFromNotification($notifiable, $notification): string
    {
        if (! method_exists($notification, 'mailDeliveryIdFor')) {
            return '';
        }

        try {
            $deliveryId = $notification->mailDeliveryIdFor($notifiable);

            return is_string($deliveryId) && preg_match('/^[a-zA-Z0-9._:-]{1,128}$/', $deliveryId)
                ? $deliveryId
                : '';
        } catch (\Throwable $e) {
            Log::warning('无法读取邮件稳定投递 ID。', [
                'notification' => get_class($notification),
                'exception'    => $e::class,
            ]);

            return '';
        }
    }
}
