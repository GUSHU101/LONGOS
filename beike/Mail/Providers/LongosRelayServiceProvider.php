<?php

namespace Beike\Mail\Providers;

use Beike\Mail\Transport\LongosRelayTransport;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;

class LongosRelayServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->resolving(MailManager::class, function (MailManager $mailManager): void {
            $mailManager->extend('longos_relay', static function (array $config): LongosRelayTransport {
                return new LongosRelayTransport(
                    (string) ($config['endpoint'] ?? ''),
                    (string) ($config['secret'] ?? ''),
                    (int) ($config['timeout'] ?? 30),
                    [
                        'max_request_bytes'    => $config['max_request_bytes']    ?? null,
                        'max_attachment_bytes' => $config['max_attachment_bytes'] ?? null,
                        'max_attachments'      => $config['max_attachments']      ?? null,
                        'max_recipients'       => $config['max_recipients']       ?? null,
                    ],
                );
            });
        });
    }
}
