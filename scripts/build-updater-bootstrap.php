<?php

declare(strict_types=1);

$root     = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (! is_file($autoload)) {
    throw new RuntimeException('Run Composer before building the updater bootstrap.');
}
require $autoload;

$config  = require $root . '/beike/Config/beike.php';
$version = (string) ($config['version'] ?? '');
$output  = $argv[1] ?? ($root . '/dist/longos-updater-bootstrap.zip');
if (! preg_match('#^(?:[A-Za-z]:[\\/]|/)#', $output)) {
    $output = $root . DIRECTORY_SEPARATOR . $output;
}

$applicationFiles = [
    'composer.json',
    'composer.lock',
    'app/Console/Commands/RunSystemUpdate.php',
    'app/Http/Middleware/PreventRequestsDuringMaintenance.php',
    'app/Updates/DatabaseBackupService.php',
    'app/Updates/GitHubReleaseClient.php',
    'app/Updates/ReleasePackage.php',
    'app/Updates/UpdateLauncher.php',
    'app/Updates/UpdateManager.php',
    'app/Updates/UpdateSettingsStore.php',
    'app/Updates/UpdateStateStore.php',
    'beike/Admin/Http/Controllers/SettingController.php',
    'beike/Admin/Http/Controllers/SystemUpdateController.php',
    'beike/Admin/Routes/admin.php',
    'beike/Admin/View/Components/Sidebar.php',
    'config/longos.php',
    'lang/en/admin/setting.php',
    'lang/zh_cn/admin/setting.php',
    'resources/beike/admin/views/pages/system_updates/index.blade.php',
];

$files = array_values(array_unique($applicationFiles));
sort($files, SORT_STRING);
$hashes = [];
foreach ($files as $relative) {
    if (! is_string($relative)
        || $relative === ''
        || str_replace('\\', '/', $relative) !== $relative
        || trim($relative, '/')              !== $relative
        || str_contains($relative, "\0")
        || str_contains($relative, '//')
        || preg_match('#(?:^|/)\.\.?(?:/|$)#', $relative)
        || preg_match('/^[A-Za-z]:/', $relative)) {
        throw new RuntimeException("Unsafe updater bootstrap path: {$relative}");
    }
    $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (! is_file($absolute) || is_link($absolute)) {
        throw new RuntimeException("Updater bootstrap file is missing or unsafe: {$relative}");
    }
    $digest = hash_file('sha256', $absolute);
    if (! is_string($digest)) {
        throw new RuntimeException("Unable to hash updater bootstrap file: {$relative}");
    }
    $hashes[$relative] = $digest;
}

$manifest = [
    'schema'     => 1,
    'product'    => 'LONGOS',
    'purpose'    => 'web-updater-bootstrap',
    'version'    => $version,
    'created_at' => gmdate('c'),
    'files'      => $hashes,
    'preserves'  => ['.env', 'storage', 'public/image/catalog', 'public/upload', 'database data'],
];

$directory = dirname($output);
if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
    throw new RuntimeException('Unable to create updater bootstrap output directory.');
}
$temporary = $output . '.tmp-' . bin2hex(random_bytes(6));
@unlink($temporary);
$zip = new ZipArchive;
if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    throw new RuntimeException('Unable to create updater bootstrap ZIP.');
}

try {
    foreach ($files as $relative) {
        $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (! $zip->addFile($absolute, $relative)) {
            throw new RuntimeException("Unable to add updater bootstrap file: {$relative}");
        }
        $mode = $relative === 'install.sh' || str_starts_with($relative, 'scripts/') && str_ends_with($relative, '.sh')
            ? 0100750
            : 0100644;
        $zip->setExternalAttributesName($relative, ZipArchive::OPSYS_UNIX, $mode << 16);
    }
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (! $zip->addFromString('longos-updater-bootstrap.json', $json)) {
        throw new RuntimeException('Unable to add updater bootstrap manifest.');
    }
    $zip->setExternalAttributesName('longos-updater-bootstrap.json', ZipArchive::OPSYS_UNIX, 0100644 << 16);
} finally {
    $zip->close();
}

if (! @rename($temporary, $output)) {
    @unlink($temporary);

    throw new RuntimeException('Unable to publish updater bootstrap ZIP atomically.');
}

$digest = hash_file('sha256', $output);
if (! is_string($digest)) {
    throw new RuntimeException('Unable to hash updater bootstrap ZIP.');
}
file_put_contents($output . '.sha256', $digest . '  ' . basename($output) . PHP_EOL, LOCK_EX);
fwrite(STDOUT, "Built {$output}\nSHA-256: {$digest}\nFiles: " . count($files) . "\n");
