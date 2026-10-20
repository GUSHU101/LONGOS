<?php

use Beike\Libraries\Request;
use Illuminate\Contracts\Http\Kernel;

if (! file_exists(__DIR__ . '/../storage/installed')
    && ! (isset($_SERVER['REQUEST_URI']) && substr($_SERVER['REQUEST_URI'], 0, 10) == '/installer')
    && (stripos($_SERVER['REQUEST_URI'], '_debugbar') !== 1)) {
    header('Location: /installer');
    exit;
}

if (version_compare(PHP_VERSION, '8.2', '<')) {
    echo 'Your current version of PHP does not meet the requirements. Upgrade to at least version 8.2 <br/>';
    echo '您当前PHP版本不满足要求，至少升级至 8.2 版本';
    exit;
}

/*
 * Run a dependency-only preflight before Laravel boots the installer. On a
 * server missing mbstring, XML, or another required extension, framework
 * startup can itself fail before the requirements wizard has a chance to
 * explain the problem. This keeps the first-deploy experience actionable
 * instead of returning an opaque HTTP 500 page.
 */
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($requestPath, '/installer')) {
    /*
     * The installer uses encrypted cookies before its environment form is
     * shown. A fresh release deliberately has no .env file (and an existing
     * incomplete .env can have APP_KEY=), so Laravel would otherwise fail
     * before the installer can repair the environment. Seed a key here,
     * before the framework loads, and persist it when the directory allows
     * writes. The wizard preserves this key so its redirect session remains
     * valid through the database-install step.
     */
    $envPath        = __DIR__ . '/../.env';
    $envExamplePath = __DIR__ . '/../.env.example';
    $envContents    = is_file($envPath) ? @file_get_contents($envPath) : false;
    if (! is_string($envContents)) {
        $envContents = is_file($envExamplePath) ? @file_get_contents($envExamplePath) : '';
    }

    $hasAppKey = false;
    if (preg_match('/^APP_KEY=(.*)$/m', $envContents, $keyMatch) === 1) {
        $configuredKey = trim($keyMatch[1], " \t\n\r\0\x0B\"'");
        if (str_starts_with($configuredKey, 'base64:')) {
            $hasAppKey = strlen((string) base64_decode(substr($configuredKey, 7), true)) === 32;
        } else {
            $hasAppKey = strlen($configuredKey) >= 32;
        }
    }
    if (! $hasAppKey) {
        $installerKey = 'base64:' . base64_encode(random_bytes(32));
        if (preg_match('/^APP_KEY=.*$/m', $envContents) === 1) {
            $envContents = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $installerKey, $envContents, 1);
        } else {
            $envContents = rtrim($envContents) . "\nAPP_KEY={$installerKey}\n";
        }

        $written = @file_put_contents($envPath, $envContents, LOCK_EX);
        if ($written === false) {
            http_response_code(503);
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<title>LONGOS installer permissions</title><style>body{margin:0;background:#f6f8fb;color:#1c2838;font:16px/1.6 system-ui,-apple-system,Segoe UI,sans-serif}.card{max-width:720px;margin:10vh auto;padding:36px;background:#fff;border:1px solid #dde5ee;border-radius:16px;box-shadow:0 18px 45px #10204013}h1{margin:0 0 12px;font-size:26px}.error{padding:14px 16px;border-radius:10px;background:#fff2f0;color:#a12b20;font-weight:700}code{font:14px ui-monospace,SFMono-Regular,Consolas,monospace}</style></head><body><main class="card"><h1>安装器无法保存应用密钥</h1><p class="error">项目根目录或 <code>.env</code> 不可写，继续安装会导致提交后会话立即失效。</p><p>生产环境请通过 Coolify Docker Compose 部署；容器入口会自动创建环境文件并修复运行目录权限。开发环境请确保当前 PHP 用户可写项目根目录。</p></main></body></html>';
            exit;
        }
        @chmod($envPath, 0600);
        putenv("APP_KEY={$installerKey}");
        $_ENV['APP_KEY']    = $installerKey;
        $_SERVER['APP_KEY'] = $installerKey;
    }

    $requiredExtensions = [
        'bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'gd', 'intl',
        'iconv', 'json', 'mbstring', 'openssl', 'pcre', 'pdo', 'simplexml',
        'tokenizer', 'xml', 'zip',
    ];
    $missingExtensions = array_values(array_filter($requiredExtensions, static function (string $extension): bool {
        return ! extension_loaded($extension);
    }));

    if ($missingExtensions) {
        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
        $missing = htmlspecialchars(implode(', ', $missingExtensions), ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>LONGOS installer requirements</title><style>body{margin:0;background:#f6f8fb;color:#1c2838;font:16px/1.6 system-ui,-apple-system,Segoe UI,sans-serif}.card{max-width:680px;margin:10vh auto;padding:36px;background:#fff;border:1px solid #dde5ee;border-radius:16px;box-shadow:0 18px 45px #10204013}h1{margin:0 0 12px;font-size:26px}.error{padding:14px 16px;border-radius:10px;background:#fff2f0;color:#a12b20;font-weight:700}code{font:14px ui-monospace,SFMono-Regular,Consolas,monospace}</style></head><body><main class="card"><h1>Server requirements need attention</h1><p>The installer cannot start until the required PHP extensions are enabled.</p><p class="error">Missing: <code>' . $missing . '</code></p><p>Enable these extensions for the PHP version used by this website, then refresh this page. For MySQL, also enable <code>pdo_mysql</code>; for PostgreSQL, enable <code>pdo_pgsql</code>.</p></main></body></html>';
        exit;
    }
}

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Check If The Application Is Under Maintenance
|--------------------------------------------------------------------------
|
| If the application is in maintenance / demo mode via the "down" command
| we will load this file so that any pre-rendered content can be shown
| instead of starting the framework, which could cause an exception.
|
*/

if (file_exists(__DIR__ . '/../storage/framework/maintenance.php')) {
    require __DIR__ . '/../storage/framework/maintenance.php';
}

/*
|--------------------------------------------------------------------------
| Register The Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader for
| this application. We just need to utilize it! We'll simply require it
| into the script here so we don't need to manually load our classes.
|
*/

require __DIR__ . '/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Run The Application
|--------------------------------------------------------------------------
|
| Once we have the application, we can handle the incoming request using
| the application's HTTP kernel. Then, we will send the response back
| to this client's browser, allowing them to enjoy our application.
|
*/

$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);

$response = $kernel->handle(
    $request = Request::capture()
)->send();

$kernel->terminate($request, $response);
