<?php

namespace Beike\Mail\Providers;

use Beike\Mail\Transport\BrevoTransport;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;

class BrevoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->resolving(MailManager::class, function (MailManager $mailManager): void {
            $mailManager->extend('brevo', static fn (array $config): BrevoTransport => new BrevoTransport(
                (string) ($config['api_key'] ?? ''),
                (int) ($config['timeout'] ?? 15),
            ));
        });
    }
}
