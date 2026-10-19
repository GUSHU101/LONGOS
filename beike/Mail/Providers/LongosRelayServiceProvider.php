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
                    (int) ($config['timeout'] ?? 30)
                );
            });
        });
    }
}
