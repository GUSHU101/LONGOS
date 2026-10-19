<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Beike\Admin\Http\Controllers\DesignController;
use Beike\Services\BuilderStateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

$database = tempnam(sys_get_temp_dir(), 'longos-builder-');
if (! is_string($database)) {
    throw new RuntimeException('Unable to create an isolated Builder database.');
}

try {
    $app = require dirname(__DIR__) . '/bootstrap/app.php';
    // Boot through the installer-safe path so this isolated test never reads
    // a developer's configured database, even when storage/installed exists.
    $app->instance('request', Request::create('/installer', 'GET'));
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config([
        'database.default'                     => 'sqlite',
        'database.connections.sqlite.database' => $database,
        'cache.default'                        => 'array',
        'logging.default'                      => 'null',
    ]);
    Log::setDefaultDriver('null');
    DB::purge('sqlite');

    Schema::create('settings', static function (Blueprint $table): void {
        $table->id();
        $table->string('type');
        $table->string('space');
        $table->string('name');
        $table->text('value');
        $table->boolean('json')->default(false);
        $table->timestamps();
        $table->unique(['type', 'space', 'name']);
    });

    $published = BuilderStateService::normalize([]);
    foreach (['design', 'header', 'footer', 'menu'] as $part) {
        $name = $part === 'design' ? 'design_setting' : "{$part}_setting";
        DB::table('settings')->insert([
            'type'  => 'system', 'space' => 'base', 'name' => $name,
            'value' => json_encode($published[$part], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'json'  => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        config(["bk.system.base.{$name}" => $published[$part]]);
    }
    // Simulate an old or unavailable history store. The core publish must
    // remain successful because the recovery snapshot is now best-effort.
    DB::unprepared("CREATE TRIGGER reject_revision_history BEFORE INSERT ON settings WHEN NEW.name = 'design_revisions' BEGIN SELECT RAISE(FAIL, 'history unavailable'); END");

    $payload = [
        'puck' => [
            'content' => [[
                'type'  => 'promo_bar',
                'props' => [
                    'id'       => 'publish-proof', 'placement' => 'home',
                    'title'    => ['zh_cn' => '发布校验'], 'sub_title' => ['zh_cn' => 'LONGOS'],
                    '_builder' => ['code' => 'promo_bar', 'name' => '活动横幅', 'visible' => true],
                ],
            ]],
            'root' => ['props' => ['schemaVersion' => 5, 'pageType' => 'home', 'globalStyle' => [], 'appEmbeds' => [], 'pageMetadata' => []]],
        ],
        'header'  => $published['header'], 'footer' => $published['footer'], 'menu' => $published['menu'],
        'publish' => true,
    ];
    $request = Request::create('/admin/design/builder', 'PUT', [], [], [], [
        'CONTENT_TYPE'                 => 'application/json',
        'HTTP_ACCEPT'                  => 'application/json',
        'HTTP_X_BUILDER_DRAFT_VERSION' => '0',
    ], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    $response = (new DesignController)->update($request);
    $result   = $response->getData(true);
    if ($response->getStatusCode() !== 200 || ($result['status'] ?? null) !== 'success' || ($result['data']['published'] ?? false) !== true || ($result['data']['revision_created'] ?? true) !== false) {
        throw new RuntimeException('LONGOS publish endpoint did not report a successful publish.');
    }

    $stored     = DB::table('settings')->where('type', 'system')->where('space', 'base')->get()->keyBy('name');
    $liveDesign = json_decode((string) $stored['design_setting']->value, true, 512, JSON_THROW_ON_ERROR);
    $draft      = json_decode((string) $stored[BuilderStateService::DRAFT_SETTING]->value, true, 512, JSON_THROW_ON_ERROR);
    if (($liveDesign['modules'][0]['module_id'] ?? null) !== 'publish-proof') {
        throw new RuntimeException('The verified module did not reach the published design setting.');
    }
    if (! hash_equals(BuilderStateService::contentHash($draft), BuilderStateService::contentHash(BuilderStateService::normalize([
        'design' => $liveDesign,
        'header' => json_decode((string) $stored['header_setting']->value, true, 512, JSON_THROW_ON_ERROR),
        'footer' => json_decode((string) $stored['footer_setting']->value, true, 512, JSON_THROW_ON_ERROR),
        'menu'   => json_decode((string) $stored['menu_setting']->value, true, 512, JSON_THROW_ON_ERROR),
    ])))) {
        throw new RuntimeException('The draft and published LONGOS states differ after verification.');
    }

    echo "Builder publish assertions: PASS\n";
} finally {
    DB::disconnect('sqlite');
    @unlink($database);
}
