<?php

/**
 * MailEventListener.php
 *
 * @copyright  2025 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2025-07-22
 */

namespace Beike\Listeners;

use Beike\Models\MailLog;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Email;
use Throwable;

class MailEventListener
{
    /**
     * 邮件发送前事件处理
     */
    public function handleMessageSending(MessageSending $event)
    {
        try {
            $message = $event->message;

            // 获取邮件信息
            $mailData = $this->extractMailData($message);

            $values = [
                'delivery_id'          => $mailData['delivery_id'],
                'relay_submission_id'  => null,
                'transport_message_id' => null,
                'to_email'             => $mailData['to_email'],
                'to_name'              => $mailData['to_name'],
                'from_email'           => $mailData['from_email'],
                'from_name'            => $mailData['from_name'],
                'subject'              => $mailData['subject'],
                'content'              => $mailData['content'],
                'mail_type'            => $mailData['mail_type'] ?: $this->detectMailType($mailData['subject'], $mailData['content']),
                'transport'            => $this->getCurrentTransport(),
                'status'               => MailLog::STATUS_PENDING,
                'error_message'        => null,
                'sent_at'              => null,
                'headers'              => $mailData['headers'],
                'attachments'          => $mailData['attachments'],
                'last_attempt_at'      => now(),
            ];

            // A queued notification carries the same delivery id through all
            // retries. Update one audit row instead of creating ambiguous
            // duplicates that can mark another concurrent message as failed.
            try {
                $mailLog = DB::transaction(static function () use ($values, $mailData): MailLog {
                    if ($mailData['delivery_id'] === '') {
                        return MailLog::query()->create($values + ['attempts' => 1]);
                    }

                    $existing = MailLog::query()
                        ->where('delivery_id', $mailData['delivery_id'])
                        ->lockForUpdate()
                        ->first();
                    if (! $existing) {
                        return MailLog::query()->create($values + ['attempts' => 1]);
                    }

                    $existing->fill($values);
                    $existing->attempts = max(1, (int) $existing->attempts) + 1;
                    $existing->save();

                    return $existing;
                });
            } catch (QueryException $exception) {
                // A duplicate-key race means another worker created the same
                // stable delivery row first. Reuse it; other DB errors remain
                // visible to the outer audit-failure handler.
                if ($mailData['delivery_id'] === '' || ! $this->isUniqueConstraintViolation($exception)) {
                    throw $exception;
                }
                $mailLog = MailLog::query()->where('delivery_id', $mailData['delivery_id'])->first();
                if (! $mailLog) {
                    throw $exception;
                }
                $mailLog->fill($values);
                $mailLog->attempts = max(1, (int) $mailLog->attempts) + 1;
                $mailLog->save();
            }

            // 将邮件记录ID存储到消息头中，以便发送后更新状态
            $message->getHeaders()->remove('X-Mail-Log-ID');
            $message->getHeaders()->addTextHeader('X-Mail-Log-ID', (string) $mailLog->id);

        } catch (Throwable $e) {
            Log::error('邮件发送前记录失败。', [
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * 邮件发送后事件处理
     */
    public function handleMessageSent(MessageSent $event)
    {
        try {
            $message = $event->message;

            // 获取邮件记录ID
            $mailLogIdHeader = $message->getHeaders()->get('X-Mail-Log-ID');
            if (! $mailLogIdHeader) {
                return;
            }

            $mailLogId = $mailLogIdHeader->getBody();
            $mailLog   = MailLog::find($mailLogId);

            if ($mailLog) {
                $receipt = $this->relayReceipt($message);
                $updates = $receipt;

                // Brevo can publish a delivery webhook before Laravel's
                // MessageSent listener runs. Never downgrade a stronger
                // provider result such as delivered, deferred or bounced to
                // the weaker local meaning of "provider accepted".
                if (in_array((string) $mailLog->status, [MailLog::STATUS_PENDING, MailLog::STATUS_FAILED], true)) {
                    $updates += [
                        'status'        => MailLog::STATUS_SENT,
                        'error_message' => null,
                        'sent_at'       => now(),
                    ];
                } elseif (! $mailLog->sent_at) {
                    $updates['sent_at'] = now();
                }

                if ($updates !== []) {
                    $mailLog->update($updates);
                }
            }

        } catch (Throwable $e) {
            Log::error('邮件发送后记录更新失败。', [
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * 邮件发送失败事件处理
     */
    public function handleMessageSendingFailed($event)
    {
        try {
            // Laravel does not dispatch a first-party mail failure event. Keep
            // this compatibility hook metadata-only so message bodies, headers
            // and transport credentials can never be written to the app log.
            Log::warning('邮件发送失败事件触发。', [
                'event_class' => is_object($event) ? $event::class : gettype($event),
            ]);

        } catch (Throwable $e) {
            Log::error('邮件发送失败记录处理失败。', [
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * 从邮件消息中提取数据
     */
    private function extractMailData($message): array
    {
        $data = [
            'to_email'    => '',
            'to_name'     => '',
            'from_email'  => '',
            'from_name'   => '',
            'subject'     => '',
            'content'     => '',
            'headers'     => [],
            'attachments' => [],
            'mail_type'   => '',
            'delivery_id' => '',
        ];

        try {
            // 获取收件人信息
            $toAddresses = $message->getTo();
            if (! empty($toAddresses)) {
                $firstTo          = reset($toAddresses);
                $data['to_email'] = $firstTo->getAddress();
                $data['to_name']  = $firstTo->getName() ?: '';
            }

            // 获取发件人信息
            $fromAddresses = $message->getFrom();
            if (! empty($fromAddresses)) {
                $firstFrom          = reset($fromAddresses);
                $data['from_email'] = $firstFrom->getAddress();
                $data['from_name']  = $firstFrom->getName() ?: '';
            }

            // 获取主题
            $data['subject'] = $message->getSubject() ?: '';

            // 获取邮件内容
            if ($message instanceof Email) {
                $htmlBody        = $message->getHtmlBody();
                $textBody        = $message->getTextBody();
                $data['content'] = $this->bodyToString($htmlBody ?: $textBody ?: '');
            }

            // 获取头信息
            $headers = [];
            foreach ($message->getHeaders()->all() as $header) {
                $name  = (string) $header->getName();
                $value = preg_replace('/[\r\n]+/', ' ', (string) $header->getBodyAsString());

                // BCC recipients and credential/signature headers should not
                // be copied into an admin-visible audit record.
                if (in_array(strtolower($name), ['bcc', 'authorization', 'proxy-authorization', 'dkim-signature'], true)) {
                    continue;
                }

                if (strtolower($name) === 'x-bk-mail-type') {
                    $candidate = strtolower(trim($value));
                    if (preg_match('/^[a-z0-9_-]{1,64}$/', $candidate)) {
                        $data['mail_type'] = $candidate;
                    }
                }

                if (strtolower($name) === 'x-longos-delivery-id') {
                    $candidate = trim($value);
                    if (preg_match('/^[a-zA-Z0-9._:-]{1,128}$/', $candidate)) {
                        $data['delivery_id'] = $candidate;
                    }
                }

                if ($name !== '' && mb_strlen($name) <= 80) {
                    $headers[$name] = mb_substr($value, 0, 1000);
                }
            }
            $data['headers'] = $headers;

            // 获取附件信息
            $attachments = [];
            if ($message instanceof Email) {
                foreach ($message->getAttachments() as $attachment) {
                    $attachments[] = [
                        'name' => $attachment->getFilename(),
                        'size' => $this->bodySize($attachment->getBody()),
                        'type' => $attachment->getMediaType(),
                    ];
                }
            }
            $data['attachments'] = $attachments;

        } catch (Throwable $e) {
            Log::error('提取邮件数据失败。', ['exception' => $e::class]);
        }

        return $data;
    }

    /**
     * 根据邮件主题和内容检测邮件类型
     */
    private function detectMailType(string $subject, string $content): string
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
    private function getCurrentTransport(): string
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

        } catch (Throwable $e) {
            Log::error('获取邮件传输方式失败。', ['exception' => $e::class]);

            return 'unknown';
        }
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');

        return in_array($sqlState, ['23000', '23505'], true);
    }

    /**
     * Symfony MIME bodies may be strings or seekable streams. Reading an
     * audit copy must not consume the stream before the transport sends it.
     */
    private function bodyToString(mixed $body): string
    {
        if (is_string($body)) {
            return $body;
        }
        if (! is_resource($body)) {
            return $body instanceof \Stringable ? (string) $body : '';
        }

        $position = ftell($body);
        $metadata = stream_get_meta_data($body);
        if (($metadata['seekable'] ?? false) === true) {
            rewind($body);
        }

        $content = stream_get_contents($body);
        if (($metadata['seekable'] ?? false) === true && $position !== false) {
            fseek($body, $position);
        }

        return is_string($content) ? $content : '';
    }

    private function bodySize(mixed $body): int
    {
        if (is_string($body)) {
            return strlen($body);
        }
        if (is_resource($body)) {
            $stat = fstat($body);
            if (is_array($stat) && isset($stat['size']) && is_int($stat['size']) && $stat['size'] >= 0) {
                return $stat['size'];
            }
        }

        return strlen($this->bodyToString($body));
    }

    /** @return array<string, string> */
    private function relayReceipt(mixed $message): array
    {
        $receipt = [];
        $headers = $message->getHeaders();

        $submission = $headers->get('X-Longos-Relay-Submission-ID')?->getBodyAsString();
        if (is_string($submission) && preg_match('/^[a-zA-Z0-9._:-]{1,128}$/', $submission) === 1) {
            $receipt['relay_submission_id'] = $submission;
        }

        $messageId = $headers->get('X-Longos-Relay-Message-ID')?->getBodyAsString();
        if (! is_string($messageId) || $messageId === '') {
            $messageId = $headers->get('X-Longos-Brevo-Message-ID')?->getBodyAsString();
        }
        if (is_string($messageId)
            && $messageId !== ''
            && strlen($messageId) <= 255
            && preg_match('/[\x00-\x1F\x7F]/', $messageId) !== 1) {
            $receipt['transport_message_id'] = $messageId;
        }

        return $receipt;
    }
}
