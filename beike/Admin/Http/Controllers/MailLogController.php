<?php

/**
 * MailLogController.php
 *
 * @copyright  2025 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2025-07-22
 */

namespace Beike\Admin\Http\Controllers;

use App\Mail\MailSenderProfileService;
use Beike\Models\MailLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Throwable;

class MailLogController extends Controller
{
    protected string $defaultRoute = 'mail_logs.index';

    /**
     * 邮件记录列表页面
     */
    public function index(Request $request): View
    {
        $filters = $request->only(['status', 'mail_type', 'transport', 'recipient', 'start_date', 'end_date']);

        $query = MailLog::query()
            ->byStatus($filters['status'] ?? '')
            ->byMailType($filters['mail_type'] ?? '')
            ->byTransport($filters['transport'] ?? '')
            ->byRecipient($filters['recipient'] ?? '')
            ->byDateRange($filters['start_date'] ?? '', $filters['end_date'] ?? '')
            ->orderBy('id', 'desc');

        $mailLogs = $query->paginate(20)->appends($filters);

        $data = [
            'mail_logs'         => $mailLogs,
            'filters'           => $filters,
            'status_options'    => MailLog::getStatusOptions(),
            'mail_type_options' => MailLog::getMailTypeOptions(),
            'transport_options' => MailLog::getTransportOptions(),
            'total_count'       => MailLog::count(),
            'sent_count'        => MailLog::where('status', MailLog::STATUS_SENT)->count(),
            'failed_count'      => MailLog::where('status', MailLog::STATUS_FAILED)->count(),
            'pending_count'     => MailLog::where('status', MailLog::STATUS_PENDING)->count(),
        ];

        return view('admin::pages.mail_logs.index', $data);
    }

    /**
     * 邮件记录详情页面
     */
    public function show(MailLog $mailLog): View
    {
        $data = [
            'mail_log' => $mailLog,
        ];

        return view('admin::pages.mail_logs.show', $data);
    }

    /**
     * 删除邮件记录
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'min:1'],
        ]);
        $ids = array_values(array_unique($data['ids']));

        if (empty($ids)) {
            return json_fail(__('admin/mail_log.select_records_to_delete'));
        }

        try {
            MailLog::whereIn('id', $ids)->delete();

            return json_success(__('admin/mail_log.delete_success_message'));
        } catch (\Exception $e) {
            Log::warning('Unable to delete mail audit records.', ['exception' => $e::class]);

            return json_fail(__('admin/mail_log.delete_failed_message', ['error' => MailLog::sanitizeError($e->getMessage())]));
        }
    }

    /**
     * 批量操作
     */
    public function batchAction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:delete,mark_as_sent,mark_as_failed'],
            'ids'    => ['required', 'array', 'min:1', 'max:500'],
            'ids.*'  => ['integer', 'min:1'],
        ]);
        $action = $data['action'];
        $ids    = array_values(array_unique($data['ids']));

        if (empty($ids)) {
            return json_fail(__('admin/mail_log.select_records_to_operate'));
        }

        try {
            switch ($action) {
                case 'delete':
                    MailLog::whereIn('id', $ids)->delete();

                    return json_success(__('admin/mail_log.batch_delete_success_message'));

                case 'mark_as_sent':
                    MailLog::whereIn('id', $ids)->update([
                        'status'  => MailLog::STATUS_SENT,
                        'sent_at' => now(),
                    ]);

                    return json_success(__('admin/mail_log.batch_mark_sent_success'));

                case 'mark_as_failed':
                    MailLog::whereIn('id', $ids)->update([
                        'status' => MailLog::STATUS_FAILED,
                    ]);

                    return json_success(__('admin/mail_log.batch_mark_failed_success'));

                default:
                    return json_fail(__('admin/mail_log.unsupported_operation'));
            }
        } catch (\Exception $e) {
            Log::warning('Unable to update mail audit records.', ['exception' => $e::class]);

            return json_fail(__('admin/mail_log.operation_failed_message', ['error' => MailLog::sanitizeError($e->getMessage())]));
        }
    }

    /**
     * 清理旧记录
     */
    public function cleanup(Request $request): JsonResponse
    {
        $days = (int) $request->validate(['days' => ['required', 'integer', 'min:1', 'max:365']])['days'];

        try {
            $cutoffDate   = now()->subDays($days);
            // Never delete an in-flight attempt from the manual maintenance
            // endpoint. The scheduled retention command first ages abandoned
            // attempts into failed records and only prunes terminal rows.
            $deletedCount = MailLog::where('status', '!=', MailLog::STATUS_PENDING)
                ->where('created_at', '<', $cutoffDate)
                ->delete();

            return json_success(__('admin/mail_log.cleanup_success_message', ['count' => $deletedCount]));
        } catch (\Exception $e) {
            Log::warning('Unable to prune mail audit records.', ['exception' => $e::class]);

            return json_fail(__('admin/mail_log.cleanup_failed_message', ['error' => MailLog::sanitizeError($e->getMessage())]));
        }
    }

    /**
     * 获取统计数据
     */
    public function statistics(Request $request): JsonResponse
    {
        $validated = $request->validate(['days' => ['sometimes', 'integer', 'min:1', 'max:365']]);
        $days      = (int) ($validated['days'] ?? 7);

        try {
            $startDate = now()->subDays($days)->startOfDay();

            // 按日期统计
            $dailyStats = MailLog::where('created_at', '>=', $startDate)
                ->selectRaw('DATE(created_at) as date, status, COUNT(*) as count')
                ->groupBy('date', 'status')
                ->orderBy('date')
                ->get()
                ->groupBy('date');

            // 按邮件类型统计
            $typeStats = MailLog::where('created_at', '>=', $startDate)
                ->selectRaw('mail_type, COUNT(*) as count')
                ->groupBy('mail_type')
                ->orderBy('count', 'desc')
                ->get();

            // 总体统计
            $totalStats = [
                'total'   => MailLog::count(),
                'sent'    => MailLog::where('status', MailLog::STATUS_SENT)->count(),
                'failed'  => MailLog::where('status', MailLog::STATUS_FAILED)->count(),
                'pending' => MailLog::where('status', MailLog::STATUS_PENDING)->count(),
                'recent'  => MailLog::where('created_at', '>=', $startDate)->count(),
            ];

            return json_success(__('admin/mail_log.get_statistics_success'), [
                'daily_stats' => $dailyStats,
                'type_stats'  => $typeStats,
                'total_stats' => $totalStats,
            ]);
        } catch (\Exception $e) {
            Log::warning('Unable to calculate mail audit statistics.', ['exception' => $e::class]);

            return json_fail(__('admin/mail_log.get_statistics_failed', ['error' => MailLog::sanitizeError($e->getMessage())]));
        }
    }

    /**
     * 重新发送邮件
     */
    public function resend(MailLog $mailLog): JsonResponse
    {
        if ($mailLog->status === MailLog::STATUS_SENT) {
            return json_fail(__('admin/mail_log.mail_already_sent'));
        }

        $rateLimitKey = 'admin-mail-resend:' . request()->ip() . ':' . $mailLog->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            return json_fail(__('admin/mail_log.resend_rate_limited'));
        }

        $recipient = trim((string) $mailLog->to_email);
        $subject   = trim((string) $mailLog->subject);
        $content   = (string) $mailLog->content;

        // Audit rows retain attachment metadata, not attachment bytes. Never
        // claim that a partial replay succeeded after silently dropping files.
        if (count((array) $mailLog->attachments) > 0) {
            return json_fail(__('admin/mail_log.resend_attachments_unavailable'));
        }

        // Replaying a record must never turn a malformed or manually edited
        // database row into an arbitrary mail-header injection.
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL) || $subject === '' || $content === '' || preg_match('/[\r\n]/', $subject)) {
            return json_fail(__('admin/mail_log.resend_invalid_record'));
        }

        RateLimiter::hit($rateLimitKey, 60);
        $loggedCategory = collect((array) $mailLog->headers)->first(
            static fn (mixed $value, mixed $name): bool => strtolower((string) $name) === 'x-longos-sender-category'
        );
        $loggedCategory = strtolower(trim((string) $loggedCategory));
        $senderCategory = array_key_exists($loggedCategory, MailSenderProfileService::categories())
            ? $loggedCategory
            : MailSenderProfileService::categoryForMailType($mailLog->mail_type);
        $senderProfile  = app(MailSenderProfileService::class)->resolve($senderCategory);

        try {
            Mail::html($content, function ($message) use ($mailLog, $recipient, $subject, $senderCategory, $senderProfile): void {
                $message->to($recipient, $mailLog->to_name ?: null)
                    ->from($senderProfile['address'], $senderProfile['name'] ?: null)
                    ->subject($subject);
                if ($senderProfile['reply_to'] !== '') {
                    $message->replyTo($senderProfile['reply_to']);
                }

                $headers = $message->getSymfonyMessage()->getHeaders();
                $headers->addTextHeader('X-BK-Mail-Type', (string) ($mailLog->mail_type ?: 'other'));
                $headers->addTextHeader('X-BK-Mail-Resend-Of', (string) $mailLog->id);
                $headers->addTextHeader('X-Longos-Sender-Category', $senderCategory);
                $headers->addTextHeader('X-Longos-Delivery-Id', 'resend:' . $mailLog->id);
            });

            return json_success(__('admin/mail_log.resend_success'));
        } catch (Throwable $e) {
            $safeError = MailLog::sanitizeError($e->getMessage());
            Log::warning('Admin mail log resend failed.', [
                'mail_log_id' => $mailLog->id,
                'recipient'   => $recipient,
                'exception'   => $e::class,
            ]);

            // MessageSending creates a pending audit record before a transport
            // is called. Mark that exact retry as failed when it exists; only
            // fall back to a new record if the event could not be persisted.
            try {
                $updated = MailLog::query()
                    ->where('delivery_id', 'resend:' . $mailLog->id)
                    ->where('status', MailLog::STATUS_PENDING)
                    ->limit(1)
                    ->update([
                        'status'          => MailLog::STATUS_FAILED,
                        'error_message'   => $safeError,
                        'last_attempt_at' => now(),
                    ]);

                if ($updated) {
                    return json_fail(__('admin/mail_log.resend_failed_with_reason', ['reason' => $safeError]));
                }

                MailLog::query()->create([
                    'delivery_id'     => 'resend:' . $mailLog->id,
                    'to_email'        => $recipient,
                    'to_name'         => $mailLog->to_name,
                    'from_email'      => (string) config('mail.from.address', ''),
                    'from_name'       => (string) config('mail.from.name', ''),
                    'subject'         => $subject,
                    'content'         => $content,
                    'mail_type'       => (string) ($mailLog->mail_type ?: 'other'),
                    'transport'       => (string) config('mail.default', ''),
                    'status'          => MailLog::STATUS_FAILED,
                    'attempts'        => 1,
                    'error_message'   => $safeError,
                    'headers'         => ['X-BK-Mail-Resend-Of' => (string) $mailLog->id],
                    'attachments'     => [],
                    'last_attempt_at' => now(),
                ]);
            } catch (Throwable $logException) {
                Log::warning('Unable to record failed mail resend.', ['exception' => $logException::class]);
            }

            return json_fail(__('admin/mail_log.resend_failed_with_reason', ['reason' => $safeError]));
        }
    }
}
