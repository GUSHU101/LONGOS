<?php

namespace App\Saas;

use App\Saas\Models\Site;
use App\Saas\Models\SiteEvent;
use App\Saas\Support\SensitiveValueRedactor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SiteAuditLogger
{
    public function record(?Site $site, string $event, string $status, ?string $message = null, array $context = []): void
    {
        try {
            SiteEvent::query()->create([
                'site_id'    => $site?->id,
                'event'      => Str::limit($event, 80, ''),
                'status'     => Str::limit($status, 24, ''),
                'message'    => $message === null ? null : Str::limit(SensitiveValueRedactor::redact($message), 1000, ''),
                'context'    => $this->sanitize($context),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // Observability must never turn a successful lifecycle operation
            // into an outage (for example while its migration is pending).
            Log::warning('Unable to persist SaaS audit event.', [
                'site_id' => $site?->id,
                'event'   => $event,
                'error'   => SensitiveValueRedactor::redact($exception->getMessage()),
            ]);
        }
    }

    private function sanitize(array $context): array
    {
        foreach ($context as $key => $value) {
            if (preg_match('/password|secret|token|api[_-]?key|authorization|credential/i', (string) $key)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = $this->sanitize($value);
            } elseif (is_string($value)) {
                $context[$key] = SensitiveValueRedactor::redact($value);
            }
        }

        return $context;
    }
}
