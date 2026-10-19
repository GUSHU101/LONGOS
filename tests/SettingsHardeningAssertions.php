<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Beike\Admin\Services\SettingsReturnUrl;

$verify = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$fallback = 'https://shop.example/admin/settings';
$verify(
    SettingsReturnUrl::resolve(
        'https://shop.example/admin/settings/policies?tab=content',
        'https://shop.example',
        'admin',
        $fallback
    ) === 'https://shop.example/admin/settings/policies?tab=content',
    'A same-origin settings URL must be retained.'
);
$verify(
    SettingsReturnUrl::resolve('https://attacker.example/admin/settings', 'https://shop.example', 'admin', $fallback) === $fallback,
    'An external return URL must be rejected.'
);
$verify(
    SettingsReturnUrl::resolve('https://shop.example/admin/products', 'https://shop.example', 'admin', $fallback) === $fallback,
    'A same-origin URL outside settings must be rejected.'
);
$verify(
    SettingsReturnUrl::resolve('//attacker.example/admin/settings', 'https://shop.example', 'admin', $fallback) === $fallback,
    'A protocol-relative return URL must be rejected.'
);
$verify(
    SettingsReturnUrl::resolve('https://shop.example/admin/settings/%2e%2e/products', 'https://shop.example', 'admin', $fallback) === $fallback,
    'A path traversal return URL must be rejected.'
);
$verify(
    SettingsReturnUrl::resolve(['not-a-url'], 'https://shop.example', 'admin', $fallback) === $fallback,
    'Non-string return URL input must be rejected.'
);
$verify(
    SettingsReturnUrl::replaceAdminPrefix(
        'https://shop.example/admin/settings/basic?source=admin#form',
        'admin',
        'control'
    ) === 'https://shop.example/control/settings/basic?source=admin#form',
    'Changing the admin prefix must not rewrite query values.'
);

fwrite(STDOUT, "Settings hardening assertions passed.\n");
