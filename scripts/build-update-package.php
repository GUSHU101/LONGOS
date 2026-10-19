<?php

declare(strict_types=1);

$root     = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (! is_file($autoload)) {
    throw new RuntimeException('Run Composer before building a LONGOS release.');
}
require $autoload;
$config  = require $root . '/beike/Config/beike.php';
$version = (string) ($config['version'] ?? '');
$tag     = trim((string) (getenv('GITHUB_REF_NAME') ?: ''));
if ($tag !== '' && str_starts_with(strtolower($tag), 'v') && substr($tag, 1) !== $version) {
    fwrite(STDERR, "Release tag {$tag} does not match LONGOS version {$version}.\n");
    exit(1);
}

$output = $argv[1] ?? ($root . '/dist/longos-release.zip');
if (! preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $output)) {
    $output = $root . DIRECTORY_SEPARATOR . $output;
}
$outputDirectory = dirname($output);
if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0775, true) && ! is_dir($outputDirectory)) {
    throw new RuntimeException('Unable to create release output directory.');
}

$directoryRoots = [
    'app', 'beike', 'bootstrap', 'config', 'database', 'docs', 'lang',
    'plugins', 'public', 'resources', 'routes', 'themes', 'vendor',
];
$rootFiles = [
    'composer.json', 'composer.lock',
];
$excludedPrefixes = [
    '.git/', '.github/', 'bootstrap/cache/', 'deploy/', 'dist/', 'docker/', 'node_modules/',
    'scripts/', 'storage/', 'tests/',
    'public/storage/', 'public/cache/', 'public/image/catalog/', 'public/plugin/', 'public/upload/',
];
$excludedFiles = [
    '.env', '.phpunit.result.cache', 'bootstrap/cache/config.php', 'bootstrap/cache/routes-v7.php',
    'docker-compose.yml', 'install.sh',
];

$files = [];
$add   = static function (string $absolute) use (&$files, $root, $excludedPrefixes, $excludedFiles): void {
    if (is_link($absolute)) {
        throw new RuntimeException("Release source cannot contain symbolic links: {$absolute}");
    }
    if (! is_file($absolute)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($absolute, strlen($root) + 1));
    if ($relative === ''
        || strlen($relative) > 4096
        || preg_match('/[\x00-\x1F\x7F:*?"<>|]/', $relative)
        || in_array($relative, $excludedFiles, true)) {
        if ($relative !== '' && ! in_array($relative, $excludedFiles, true)) {
            throw new RuntimeException("Unsafe or non-portable release path: {$relative}");
        }

        return;
    }
    foreach (explode('/', $relative) as $segment) {
        $stem = strtoupper((string) strtok($segment, '.'));
        if ($segment === '' || strlen($segment) > 255 || str_ends_with($segment, '.') || str_ends_with($segment, ' ')
                            || preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/', $stem)) {
            throw new RuntimeException("Unsafe or non-portable release path: {$relative}");
        }
    }
    foreach ($excludedPrefixes as $prefix) {
        if (str_starts_with($relative . '/', $prefix)) {
            return;
        }
    }
    if (str_ends_with($relative, '.log') || str_contains($relative, '/.git/')) {
        return;
    }
    $key = strtolower($relative);
    if (isset($files[$key]) && $files[$key]['relative'] !== $relative) {
        throw new RuntimeException("Case-insensitive duplicate release path: {$relative}");
    }
    $files[$key] = ['relative' => $relative, 'absolute' => $absolute];
};

foreach ($rootFiles as $file) {
    $add($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file));
}
foreach ($directoryRoots as $directory) {
    $path = $root . DIRECTORY_SEPARATOR . $directory;
    if (! is_dir($path)) {
        continue;
    }
    $directoryIterator = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
    $filteredIterator  = new RecursiveCallbackFilterIterator(
        $directoryIterator,
        static function (SplFileInfo $item) use ($root, $excludedPrefixes): bool {
            if (! $item->isDir()) {
                return true;
            }

            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1)) . '/';
            foreach ($excludedPrefixes as $prefix) {
                if (str_starts_with($relative, $prefix)) {
                    return false;
                }
            }

            return true;
        },
    );
    $iterator = new RecursiveIteratorIterator(
        $filteredIterator,
        RecursiveIteratorIterator::LEAVES_ONLY,
    );
    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $add($item->getPathname());
        }
    }
}
ksort($files, SORT_STRING);

$required = [
    'composer.json', 'composer.lock', 'beike/Config/beike.php', 'bootstrap/app.php',
    'public/index.php', 'vendor/autoload.php', 'vendor/composer/installed.php',
];
$relativeFiles = array_column($files, 'relative');
foreach ($required as $requiredFile) {
    if (! in_array($requiredFile, $relativeFiles, true)) {
        throw new RuntimeException("Release dependency is missing: {$requiredFile}");
    }
}

$hashes = [];
foreach ($files as $file) {
    $hash = hash_file('sha256', $file['absolute']);
    if (! is_string($hash)) {
        throw new RuntimeException("Unable to hash {$file['relative']}");
    }
    $hashes[$file['relative']] = $hash;
}

// When a previous verified LONGOS release is available, carry an explicit
// allow-listed deletion set into the new manifest. Without this, obsolete PHP
// files would remain executable forever even after being removed from source.
$deletes         = [];
$previousArchive = trim((string) ($argv[2] ?? ''));
if ($previousArchive !== '') {
    if (! is_file($previousArchive)) {
        throw new RuntimeException('Previous LONGOS release archive does not exist.');
    }
    $previousZip = new ZipArchive;
    if ($previousZip->open($previousArchive) !== true) {
        throw new RuntimeException('Previous LONGOS release archive is invalid.');
    }

    try {
        $previousText = $previousZip->getFromName('longos-release.json');
    } finally {
        $previousZip->close();
    }
    $previous = json_decode(is_string($previousText) ? $previousText : '', true);
    if (! is_array($previous)
        || (int) ($previous['schema'] ?? 0) !== 1
        || ($previous['product'] ?? null)   !== 'LONGOS'
        || ! is_array($previous['files'] ?? null)) {
        throw new RuntimeException('Previous LONGOS release manifest is invalid.');
    }

    foreach ($previous['files'] as $path => $sha256) {
        $path = (string) $path;
        if ($path === ''
            || str_replace('\\', '/', $path) !== $path
            || trim($path, '/')              !== $path
            || str_contains($path, "\0")
            || str_contains($path, '//')
            || preg_match('#(?:^|/)\.\.?(?:/|$)#', $path)
            || preg_match('/^[A-Za-z]:/', $path)
            || strlen($path) > 4096
            || preg_match('/[\x00-\x1F\x7F:*?"<>|]/', $path)
            || ! preg_match('/^[a-f0-9]{64}$/', (string) $sha256)) {
            throw new RuntimeException("Unsafe path in previous LONGOS release: {$path}");
        }
        foreach (explode('/', $path) as $segment) {
            $stem = strtoupper((string) strtok($segment, '.'));
            if (strlen($segment) > 255 || str_ends_with($segment, '.') || str_ends_with($segment, ' ')
                                       || preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/', $stem)) {
                throw new RuntimeException("Non-portable path in previous LONGOS release: {$path}");
            }
        }

        $owned = in_array($path, $rootFiles, true);
        foreach ($directoryRoots as $directoryRoot) {
            if (str_starts_with($path, $directoryRoot . '/')) {
                $owned = true;

                break;
            }
        }
        $excluded = in_array($path, $excludedFiles, true);
        foreach ($excludedPrefixes as $prefix) {
            if (str_starts_with($path . '/', $prefix)) {
                $excluded = true;

                break;
            }
        }
        if (! $owned || $excluded) {
            throw new RuntimeException("Previous LONGOS release claims an unowned path: {$path}");
        }
        if (! array_key_exists($path, $hashes)) {
            $deletes[] = $path;
        }
    }
    sort($deletes, SORT_STRING);
}

$manifest = [
    'schema'     => 1,
    'product'    => 'LONGOS',
    'version'    => $version,
    'build'      => (string) ($config['build'] ?? ''),
    'created_at' => gmdate('c'),
    'requires'   => [
        'php'    => '8.2.0',
        'longos' => '2.0.0.35',
    ],
    'protected'  => [
        '.env', 'storage', 'bootstrap/cache', 'public/storage', 'public/cache', 'public/image/catalog',
        'public/plugin', 'public/upload',
        'deploy', 'docker', 'scripts', 'install.sh', 'docker-compose.yml',
    ],
    'files'  => $hashes,
    'delete' => $deletes,
];

$temporary = $output . '.tmp-' . bin2hex(random_bytes(6));
@unlink($temporary);
$zip = new ZipArchive;
if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    throw new RuntimeException('Unable to create update ZIP.');
}

try {
    foreach ($files as $file) {
        if (! $zip->addFile($file['absolute'], $file['relative'])) {
            throw new RuntimeException("Unable to add {$file['relative']} to update ZIP.");
        }
        $mode = $file['relative'] === 'artisan'
            || $file['relative']  === 'install.sh'
            || str_starts_with($file['relative'], 'scripts/') && str_ends_with($file['relative'], '.sh')
            ? 0100750
            : 0100644;
        $zip->setExternalAttributesName($file['relative'], ZipArchive::OPSYS_UNIX, $mode << 16);
    }
    $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (! $zip->addFromString('longos-release.json', $manifestJson)) {
        throw new RuntimeException('Unable to add release manifest.');
    }
    $zip->setExternalAttributesName('longos-release.json', ZipArchive::OPSYS_UNIX, 0100644 << 16);
} finally {
    $zip->close();
}

if (! @rename($temporary, $output)) {
    @unlink($temporary);

    throw new RuntimeException('Unable to publish update ZIP atomically.');
}

$digest = hash_file('sha256', $output);
if (! is_string($digest)) {
    throw new RuntimeException('Unable to hash LONGOS update ZIP.');
}
file_put_contents($output . '.sha256', $digest . '  ' . basename($output) . PHP_EOL, LOCK_EX);
fwrite(STDOUT, "Built {$output}\nSHA-256: {$digest}\nFiles: " . count($hashes) . "\n");
