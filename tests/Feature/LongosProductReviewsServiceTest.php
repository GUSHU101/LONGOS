<?php

namespace Tests\Feature;

use Beike\Repositories\PluginRepo;
use Beike\Shop\Providers\PluginServiceProvider;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\TestCase;
use Plugin\LongosProductReviews\Controllers\AdminReviewController;
use Plugin\LongosProductReviews\Controllers\AdminReviewGenerationController;
use Plugin\LongosProductReviews\Exceptions\GenerationBatchException;
use Plugin\LongosProductReviews\Jobs\GenerateReviewBatch;
use Plugin\LongosProductReviews\Models\Review;
use Plugin\LongosProductReviews\Models\ReviewGenerationBatch;
use Plugin\LongosProductReviews\Services\ReviewGenerationBatchService;
use Plugin\LongosProductReviews\Services\ReviewGenerationService;
use Plugin\LongosProductReviews\Services\ReviewService;
use Plugin\LongosProductReviews\Services\SiliconFlowClient;
use Plugin\LongosProductReviews\Services\SiliconFlowSettings;

class LongosProductReviewsServiceTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        app('view')->addNamespace('LongosProductReviews', base_path('plugins/LongosProductReviews/Views'));
        app('translator')->addNamespace('LongosProductReviews', base_path('plugins/LongosProductReviews/Lang'));

        $this->databasePath = tempnam(sys_get_temp_dir(), 'longos-reviews-');
        config([
            'database.default'                  => 'reviews_test',
            'database.connections.reviews_test' => [
                'driver'                  => 'sqlite',
                'database'                => $this->databasePath,
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('reviews_test');
        DB::setDefaultConnection('reviews_test');

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('product_descriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('locale', 20);
            $table->string('name');
            $table->text('content')->nullable();
            $table->text('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('meta_keywords')->nullable();
            $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('space');
            $table->string('name');
            $table->text('value');
            $table->boolean('json')->default(false);
            $table->timestamps();
        });
        $migration = require base_path('plugins/LongosProductReviews/Migrations/2026_10_09_000000_create_longos_product_reviews_table.php');
        $migration->up();
        $aiMigration = require base_path('plugins/LongosProductReviews/Migrations/2026_11_15_000000_add_ai_metadata_to_longos_product_reviews_table.php');
        $aiMigration->up();
        $repairMigration = require base_path('plugins/LongosProductReviews/Migrations/2026_11_15_010000_repair_generation_batch_product_foreign_key.php');
        $repairMigration->up();
        DB::table('products')->insert(['id' => 71, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_descriptions')->insert([
            'product_id' => 71,
            'locale'     => locale(),
            'name'       => 'LONGOS Travel Bottle',
            'content'    => '<p>Insulated stainless steel bottle with a leak-resistant lid.</p>',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        PluginRepo::resetRuntimeCache();
        if (app()->bound('plugin')) {
            app('plugin')->forgetRuntimeCache();
        }
        DB::disconnect('reviews_test');
        @unlink($this->databasePath);
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_storefront_payload_excludes_drafts_and_calculates_distribution(): void
    {
        $this->review(5, true, 3, '<script>alert(1)</script> Excellent quality');
        $this->review(5, true, 2, 'Exactly as described');
        $this->review(4, true, 1, 'Very good overall');
        $this->review(1, false, 100, 'Draft must never appear');

        $payload = (new ReviewService)->storefrontPayload(71, 3);

        self::assertNotNull($payload);
        self::assertSame(3, $payload['total']);
        self::assertSame(4.7, $payload['average']);
        self::assertFalse($payload['has_more']);
        self::assertSame([5, 5, 4], $payload['reviews']->pluck('rating')->all());
        self::assertSame(2, $payload['distribution'][0]['count']);
        self::assertSame(67, $payload['distribution'][0]['percentage']);
        self::assertSame(1, $payload['distribution'][1]['count']);

        $html = view('LongosProductReviews::shop.cards', ['reviews' => $payload['reviews']])->render();
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);

        $section = view('LongosProductReviews::shop.section', [
            'product' => ['id' => 71],
            'reviews' => $payload,
        ])->render();
        self::assertStringContainsString('id="customer-reviews"', $section);
        self::assertStringContainsString('id="customer-reviews-heading"', $section);
        self::assertStringContainsString('data-review-sort', $section);
        self::assertStringContainsString('data-review-lightbox', $section);
        self::assertStringContainsString('Customer 5', $section);
        self::assertStringNotContainsString('Draft must never appear', $section);
    }

    public function test_plugin_routes_are_named_and_guarded_for_the_active_tenant(): void
    {
        $provider = new PluginServiceProvider(app());
        $register = Closure::bind(function (): void {
            $this->pluginBasePath = base_path('plugins');
            $this->registerRoutes('LongosProductReviews', 'longos_product_reviews');
        }, $provider, PluginServiceProvider::class);
        $register();

        $routes     = collect(Route::getRoutes()->getRoutes());
        $shopRoute  = $routes->first(static fn ($route): bool => $route->uri() === 'products/{product}/customer-reviews');
        $adminRoute = $routes->first(static fn ($route): bool => $route->uri() === admin_name() . '/product-reviews');

        self::assertNotNull($shopRoute);
        self::assertSame('shop.longos_product_reviews.index', $shopRoute->getName());
        self::assertContains('shop', $shopRoute->gatherMiddleware());
        self::assertContains('plugin.enabled:longos_product_reviews', $shopRoute->gatherMiddleware());
        self::assertNotNull($adminRoute);
        self::assertSame(admin_name() . '.longos_product_reviews.index', $adminRoute->getName());
        self::assertContains('plugin.enabled:longos_product_reviews', $adminRoute->gatherMiddleware());
        self::assertNotNull($routes->first(static fn ($route): bool => $route->getName() === admin_name() . '.longos_product_reviews.publication'));
        self::assertNotNull($routes->first(static fn ($route): bool => $route->getName() === admin_name() . '.longos_product_reviews.generate.preview'));
        self::assertNotNull($routes->first(static fn ($route): bool => $route->getName() === admin_name() . '.longos_product_reviews.generate.status'));
        self::assertNotNull($routes->first(static fn ($route): bool => $route->getName() === admin_name() . '.longos_product_reviews.generate.cancel'));
        self::assertNotNull($routes->first(static fn ($route): bool => $route->getName() === admin_name() . '.longos_product_reviews.bulk'));
    }

    public function test_storefront_pagination_never_returns_unpublished_reviews(): void
    {
        foreach ([5, 4, 3, 2] as $index => $rating) {
            $this->review($rating, true, 4 - $index, 'Published review ' . $rating);
        }
        $this->review(1, false, 999, 'Hidden draft');

        $first = (new ReviewService)->storefrontPage(71, 0, 3);
        $next  = (new ReviewService)->storefrontPage(71, $first['next_offset'], 3);

        self::assertCount(3, $first['reviews']);
        self::assertTrue($first['has_more']);
        self::assertCount(1, $next['reviews']);
        self::assertFalse($next['has_more']);
        self::assertFalse($first['reviews']->concat($next['reviews'])->contains('published', false));
    }

    public function test_storefront_filters_and_sorting_are_scoped_to_matching_published_reviews(): void
    {
        $five = $this->review(5, true, 0, 'Highest rating');
        $two  = $this->review(2, true, 99, 'Photo review');
        $this->review(4, true, 10, 'Middle rating');
        $this->review(1, false, 999, 'Hidden draft');
        $two->update(['images' => ['upload/reviews/2026/10/customer.webp']]);

        $service = new ReviewService;
        $highest = $service->storefrontPage(71, 0, 12, ['sort' => 'highest']);
        $lowest  = $service->storefrontPage(71, 0, 12, ['sort' => 'lowest']);
        $photos  = $service->storefrontPage(71, 0, 12, ['with_images' => true]);
        $rating  = $service->storefrontPage(71, 0, 12, ['rating' => 4]);

        self::assertSame([5, 4, 2], $highest['reviews']->pluck('rating')->all());
        self::assertSame([2, 4, 5], $lowest['reviews']->pluck('rating')->all());
        self::assertSame(1, $photos['total']);
        self::assertSame($two->id, $photos['reviews']->first()->id);
        self::assertSame(1, $rating['total']);
        self::assertSame(4, $rating['reviews']->first()->rating);

        $service->setPublished($five, false);
        self::assertSame(2, $service->storefrontPage(71, 0, 12)['total']);
    }

    public function test_permanently_deleted_products_remove_reviews_and_their_tenant_files(): void
    {
        Storage::fake('upload');
        Storage::disk('upload')->put('reviews/2026/10/customer.webp', 'image');
        $review = $this->review(5, true, 1, 'Review with a customer photo');
        $review->update(['images' => ['upload/reviews/2026/10/customer.webp']]);

        (new ReviewService)->deleteForProducts([71]);

        self::assertSame(0, Review::query()->where('product_id', 71)->count());
        Storage::disk('upload')->assertMissing('reviews/2026/10/customer.webp');
    }

    public function test_admin_page_repairs_a_registered_plugin_with_a_missing_table(): void
    {
        Schema::drop('longos_product_reviews');
        Schema::create('migrations', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        Schema::create('plugins', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('code');
            $table->timestamps();
        });
        PluginRepo::resetRuntimeCache();
        app('plugin')->forgetRuntimeCache();
        $stalePlugin = app('plugin')->getPlugin('longos_product_reviews');
        self::assertNotNull($stalePlugin);
        self::assertFalse($stalePlugin->getInstalled());

        $view = (new AdminReviewController)->index(Request::create('/admin/product-reviews'));

        self::assertTrue(Schema::hasTable('longos_product_reviews'));
        self::assertSame('LongosProductReviews::admin.index', $view->name());
        self::assertTrue(DB::table('plugins')->where('code', 'longos_product_reviews')->exists());
        self::assertSame('1', (string) DB::table('settings')
            ->where('space', 'longos_product_reviews')
            ->where('name', 'status')
            ->value('value'));
        self::assertTrue(app('plugin')->getPlugin('longos_product_reviews')?->getInstalled());
        self::assertTrue(Schema::hasTable('longos_product_reviews'));
        self::assertTrue(Schema::hasColumn('longos_product_reviews', 'content_source'));
        self::assertTrue(Schema::hasColumn('longos_product_reviews', 'generation_batch_id'));
        self::assertTrue(Schema::hasColumn('longos_product_reviews', 'staff_reviewed'));
        self::assertTrue(Schema::hasTable('longos_product_review_generation_batches'));
    }

    public function test_generation_batch_migration_uses_short_tenant_unique_foreign_key_and_repairs_partial_table(): void
    {
        $connection     = Schema::getConnection();
        $originalPrefix = $connection->getTablePrefix();
        $migration      = require base_path('plugins/LongosProductReviews/Migrations/2026_11_15_000000_add_ai_metadata_to_longos_product_reviews_table.php');
        $method         = new \ReflectionMethod($migration, 'productForeignKeyName');

        try {
            $connection->setTablePrefix('lt_32d004ebe585_');
            $constraint = (string) $method->invoke($migration);
        } finally {
            $connection->setTablePrefix($originalPrefix);
        }

        self::assertLessThanOrEqual(64, strlen($constraint));
        self::assertMatchesRegularExpression('/^lprgb_[a-f0-9]{12}_product_fk$/', $constraint);

        Schema::drop('longos_product_review_generation_batches');
        Schema::create('longos_product_review_generation_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('product_id');
        });
        self::assertSame([], Schema::getForeignKeys('longos_product_review_generation_batches'));

        $repair = require base_path('plugins/LongosProductReviews/Migrations/2026_11_15_010000_repair_generation_batch_product_foreign_key.php');
        $repair->up();
        $foreignKeys = Schema::getForeignKeys('longos_product_review_generation_batches');

        self::assertCount(1, $foreignKeys);
        self::assertSame(['product_id'], $foreignKeys[0]['columns']);
        self::assertSame('cascade', $foreignKeys[0]['on_delete']);

        $repair->down();
        self::assertSame([], Schema::getForeignKeys('longos_product_review_generation_batches'));
        $repair->up();
        self::assertCount(1, Schema::getForeignKeys('longos_product_review_generation_batches'));
    }

    public function test_siliconflow_generation_respects_server_assigned_ranges_and_creates_safe_drafts(): void
    {
        config([
            'bk.plugin.longos_product_reviews.siliconflow_api_key' => 'test-api-key',
            'bk.plugin.longos_product_reviews.siliconflow_model'   => 'XingChenAGI/Xing4.0-29B',
            'bk.plugin.longos_product_reviews.siliconflow_timeout' => 30,
        ]);
        Http::fake([
            'api.siliconflow.cn/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode(['reviews' => [
                            ['author_name' => 'Jamie Chen', 'title' => 'Easy to carry', 'body' => 'The lid feels secure and the bottle fits neatly in my daily bag.'],
                            ['author_name' => 'Mia Laurent', 'title' => 'Solid everyday bottle', 'body' => 'A practical size with a clean finish, and it has been simple to rinse after use.'],
                            ['author_name' => 'Kenji Sato', 'title' => 'Useful design', 'body' => 'The shape is comfortable to hold and the cap is straightforward to open.'],
                        ]], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ], 200),
        ]);

        $preview = app(ReviewGenerationService::class)->preview([
            'product_id'      => 71,
            'count'           => 3,
            'date_from'       => '2026-10-01',
            'date_to'         => '2026-10-10',
            'rating_min'      => 2,
            'rating_max'      => 4,
            'countries'       => "Japan\nFrance",
            'examples'        => 'Compact, useful and easy to clean. The finish looks understated.',
            'output_language' => 'en-US',
        ]);

        self::assertCount(3, $preview['items']);
        self::assertCount(3, array_unique(array_column($preview['items'], 'reviewed_at')));
        foreach ($preview['items'] as $item) {
            self::assertContains($item['country'], ['Japan', 'France']);
            self::assertGreaterThanOrEqual(2, $item['rating']);
            self::assertLessThanOrEqual(4, $item['rating']);
            self::assertGreaterThanOrEqual('2026-10-01 00:00:00', $item['reviewed_at']);
            self::assertLessThanOrEqual('2026-10-10 23:59:59', $item['reviewed_at']);
        }
        Http::assertSent(function ($request): bool {
            $payload     = $request->data();
            $userPayload = json_decode((string) data_get($payload, 'messages.1.content'), true, 64, JSON_THROW_ON_ERROR);
            $schemaKeys  = array_keys((array) data_get($userPayload, 'required_schema.reviews.0', []));
            sort($schemaKeys);

            return $request->url() === 'https://api.siliconflow.cn/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-api-key')
                && data_get($payload, 'model')                === 'XingChenAGI/Xing4.0-29B'
                && data_get($payload, 'response_format.type') === 'json_object'
                && $schemaKeys                                === ['author_name', 'body', 'title']
                && ! array_key_exists('spec_id', (array) data_get($userPayload, 'specifications.0', []))
                && str_contains((string) data_get($payload, 'messages.1.content'), 'Compact, useful');
        });

        $created = (new ReviewService)->createGeneratedDrafts($preview['items'], $preview['batch_id']);
        self::assertCount(3, $created);
        self::assertSame([false], $created->pluck('published')->unique()->values()->all());
        self::assertSame([false], $created->pluck('verified_purchase')->unique()->values()->all());
        self::assertSame([false], $created->pluck('staff_reviewed')->unique()->values()->all());
        self::assertSame([Review::SOURCE_GENERATED], $created->pluck('content_source')->unique()->values()->all());
        self::assertSame(
            collect($preview['items'])->pluck('country')->all(),
            $created->pluck('author_country')->all(),
        );
    }

    public function test_siliconflow_api_key_is_encrypted_and_blank_updates_preserve_it(): void
    {
        $settings = new SiliconFlowSettings;
        $settings->save([
            'api_key'         => 'sk-tenant-secret',
            'model'           => 'XingChenAGI/Xing4.0-29B',
            'parallel_models' => "tencent/Hunyuan-MT-7B\nXingChenAGI/Xing4.0-29B",
            'timeout'         => 45,
        ]);

        $stored = (string) DB::table('settings')
            ->where('space', 'longos_product_reviews')
            ->where('name', 'siliconflow_api_key')
            ->value('value');
        self::assertStringStartsWith('encrypted:v1:', $stored);
        self::assertStringNotContainsString('sk-tenant-secret', $stored);
        self::assertSame('sk-tenant-secret', $settings->runtime()['api_key']);
        self::assertSame(
            ['XingChenAGI/Xing4.0-29B', 'tencent/Hunyuan-MT-7B'],
            $settings->runtime()['models'],
        );

        $settings->save([
            'api_key' => '',
            'model'   => 'XingChenAGI/Xing4.0-29B',
            'timeout' => 60,
        ]);
        self::assertSame('sk-tenant-secret', $settings->runtime()['api_key']);
        self::assertSame(60, $settings->runtime()['timeout']);

        $prefixLikeKey = 'encrypted:v1:this-is-a-real-key-value';
        $settings->save([
            'api_key' => $prefixLikeKey,
            'model'   => 'XingChenAGI/Xing4.0-29B',
            'timeout' => 60,
        ]);
        $stored = (string) DB::table('settings')
            ->where('space', 'longos_product_reviews')
            ->where('name', 'siliconflow_api_key')
            ->value('value');
        self::assertNotSame($prefixLikeKey, $stored);
        self::assertSame($prefixLikeKey, $settings->runtime()['api_key']);
    }

    public function test_siliconflow_client_retries_transient_provider_failures_only_until_success(): void
    {
        config([
            'bk.plugin.longos_product_reviews.siliconflow_api_key' => 'test-api-key',
            'bk.plugin.longos_product_reviews.siliconflow_model'   => 'XingChenAGI/Xing4.0-29B',
            'bk.plugin.longos_product_reviews.siliconflow_timeout' => 30,
        ]);
        Http::fakeSequence()
            ->push(['message' => 'overloaded'], 503, ['x-siliconcloud-trace-id' => 'trace-retry'])
            ->push(['choices' => [['message' => ['content' => '{"reviews":[]}']]]], 200);

        $response = app(SiliconFlowClient::class)->chatJson([
            ['role' => 'user', 'content' => 'Return JSON.'],
        ]);

        self::assertSame(['reviews' => []], $response);
        Http::assertSentCount(2);
    }

    public function test_generation_distributes_chunks_across_the_parallel_model_pool(): void
    {
        config([
            'bk.plugin.longos_product_reviews.siliconflow_api_key' => 'test-api-key',
            'bk.plugin.longos_product_reviews.siliconflow_model'   => 'XingChenAGI/Xing4.0-29B',
            'bk.plugin.longos_product_reviews.siliconflow_models'  => "XingChenAGI/Xing4.0-29B\ntencent/Hunyuan-MT-7B",
            'bk.plugin.longos_product_reviews.siliconflow_timeout' => 30,
        ]);
        $call = 0;
        Http::fake(function () use (&$call) {
            $rows = [];
            foreach (range(0, 9) as $index) {
                $token  = strtr(hash('sha256', $call . ':' . $index), '0123456789', 'qwertyuiop');
                $rows[] = [
                    'author_name' => 'Avery ' . ucfirst(substr($token, 0, 8)),
                    'title'       => 'Practical fit ' . substr($token, 8, 10),
                    'body'        => 'The compact handling felt straightforward during ordinary use, especially around ' . $token . '.',
                ];
            }
            $call++;

            return Http::response([
                'choices' => [['message' => ['content' => json_encode(['reviews' => $rows], JSON_THROW_ON_ERROR)]]],
            ]);
        });

        $preview = app(ReviewGenerationService::class)->preview([
            'product_id'          => 71,
            'count'               => 20,
            'date_from'           => '2026-10-01',
            'date_to'             => '2026-10-10',
            'rating_min'          => 4,
            'rating_max'          => 5,
            'rating_distribution' => 'balanced',
            'review_length'       => 'mixed',
            'countries'           => "Japan\nFrance",
            'examples'            => 'Compact and useful, with a simple finish suitable for routine use.',
            'output_language'     => 'en-US',
            '_models'             => ['XingChenAGI/Xing4.0-29B', 'tencent/Hunyuan-MT-7B'],
        ]);

        self::assertCount(20, $preview['items']);
        self::assertSame(['XingChenAGI/Xing4.0-29B', 'tencent/Hunyuan-MT-7B'], $preview['models']);
        $sentModels = Http::recorded()->map(fn (array $pair): string => (string) $pair[0]['model'])->sort()->values()->all();
        self::assertSame(['XingChenAGI/Xing4.0-29B', 'tencent/Hunyuan-MT-7B'], $sentModels);
        Http::assertSentCount(2);
    }

    public function test_generation_retries_model_duplicates_against_existing_reviews(): void
    {
        config([
            'bk.plugin.longos_product_reviews.siliconflow_api_key' => 'test-api-key',
            'bk.plugin.longos_product_reviews.siliconflow_model'   => 'XingChenAGI/Xing4.0-29B',
            'bk.plugin.longos_product_reviews.siliconflow_timeout' => 30,
        ]);
        $this->review(4, true, 0, 'Existing exact duplicate body');
        $duplicateResponse = json_encode(['reviews' => [[
            'author_name' => 'First Name',
            'title'       => 'Review 4',
            'body'        => 'Existing exact duplicate body',
        ]]], JSON_THROW_ON_ERROR);
        $uniqueResponse = json_encode(['reviews' => [[
            'author_name' => 'Second Name',
            'title'       => 'A distinct generated title',
            'body'        => 'A clearly different generated review body for this product.',
        ]]], JSON_THROW_ON_ERROR);
        Http::fakeSequence()
            ->push(['choices' => [['message' => ['content' => $duplicateResponse]]]], 200)
            ->push(['choices' => [['message' => ['content' => $uniqueResponse]]]], 200);

        $preview = app(ReviewGenerationService::class)->preview([
            'product_id'      => 71,
            'count'           => 1,
            'date_from'       => '2026-10-01',
            'date_to'         => '2026-10-10',
            'rating_min'      => 4,
            'rating_max'      => 4,
            'countries'       => 'Japan',
            'examples'        => 'A sufficiently detailed example review for style guidance.',
            'output_language' => 'en-US',
        ]);

        self::assertSame('A distinct generated title', $preview['items'][0]['title']);
        Http::assertSentCount(2);
    }

    public function test_generation_rejects_verified_purchase_claims_in_supported_languages(): void
    {
        config([
            'bk.plugin.longos_product_reviews.siliconflow_api_key' => 'test-api-key',
            'bk.plugin.longos_product_reviews.siliconflow_model'   => 'XingChenAGI/Xing4.0-29B',
            'bk.plugin.longos_product_reviews.siliconflow_timeout' => 30,
        ]);
        $unsafeResponse = json_encode(['reviews' => [[
            'author_name' => 'Lucia Martin',
            'title'       => 'Compra verificada',
            'body'        => 'El formato es compacto y resulta sencillo de guardar cada día.',
        ]]], JSON_THROW_ON_ERROR);
        $safeResponse = json_encode(['reviews' => [[
            'author_name' => 'Lucia Martin',
            'title'       => 'Formato compacto',
            'body'        => 'El tamaño es práctico y el acabado exterior tiene un aspecto sencillo.',
        ]]], JSON_THROW_ON_ERROR);
        Http::fakeSequence()
            ->push(['choices' => [['message' => ['content' => $unsafeResponse]]]], 200)
            ->push(['choices' => [['message' => ['content' => $safeResponse]]]], 200);

        $preview = app(ReviewGenerationService::class)->preview([
            'product_id'      => 71,
            'count'           => 1,
            'date_from'       => '2026-10-01',
            'date_to'         => '2026-10-10',
            'rating_min'      => 4,
            'rating_max'      => 5,
            'countries'       => 'Spain',
            'examples'        => 'Una reseña de ejemplo suficientemente detallada para orientar el estilo.',
            'output_language' => 'es-ES',
        ]);

        self::assertSame('Formato compacto', $preview['items'][0]['title']);
        Http::assertSentCount(2);
    }

    public function test_generation_retries_near_duplicate_copy_against_existing_reviews(): void
    {
        config([
            'bk.plugin.longos_product_reviews.siliconflow_api_key' => 'test-api-key',
            'bk.plugin.longos_product_reviews.siliconflow_model'   => 'XingChenAGI/Xing4.0-29B',
            'bk.plugin.longos_product_reviews.siliconflow_timeout' => 30,
        ]);
        $this->review(4, true, 0, 'The compact bottle fits neatly in my work bag and is straightforward to rinse each evening.');
        $nearDuplicate = json_encode(['reviews' => [[
            'author_name' => 'Morgan Lee',
            'title'       => 'Review 4',
            'body'        => 'The compact bottle fits neatly in my daily bag and is straightforward to rinse each evening.',
        ]]], JSON_THROW_ON_ERROR);
        $unique = json_encode(['reviews' => [[
            'author_name' => 'Rina Park',
            'title'       => 'Understated finish',
            'body'        => 'The muted exterior looks clean, while the narrow shape is comfortable to hold during a commute.',
        ]]], JSON_THROW_ON_ERROR);
        Http::fakeSequence()
            ->push(['choices' => [['message' => ['content' => $nearDuplicate]]]], 200)
            ->push(['choices' => [['message' => ['content' => $unique]]]], 200);

        $preview = app(ReviewGenerationService::class)->preview([
            'product_id'          => 71,
            'count'               => 1,
            'date_from'           => '2026-10-01',
            'date_to'             => '2026-10-10',
            'rating_min'          => 4,
            'rating_max'          => 4,
            'rating_distribution' => 'balanced',
            'review_length'       => 'medium',
            'countries'           => 'Japan',
            'examples'            => 'A sufficiently detailed example review for style guidance.',
            'output_language'     => 'en-US',
        ]);

        self::assertSame('Understated finish', $preview['items'][0]['title']);
        Http::assertSentCount(2);
    }

    public function test_generation_retries_meta_copy_that_discloses_ai_or_merchant_creation(): void
    {
        config([
            'bk.plugin.longos_product_reviews.siliconflow_api_key' => 'test-api-key',
            'bk.plugin.longos_product_reviews.siliconflow_model'   => 'XingChenAGI/Xing4.0-29B',
            'bk.plugin.longos_product_reviews.siliconflow_timeout' => 30,
        ]);
        $disclosed = json_encode(['reviews' => [[
            'author_name' => 'Lin Chen',
            'title'       => '商家自定义评论',
            'body'        => '这是一条由AI生成的示例评论，用于介绍商品外观。',
        ]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $safe = json_encode(['reviews' => [[
            'author_name' => 'Lin Chen',
            'title'       => '外形简洁',
            'body'        => '瓶身线条简洁，放进日常通勤包的侧袋比较方便，清洗也不费事。',
        ]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        Http::fakeSequence()
            ->push(['choices' => [['message' => ['content' => $disclosed]]]], 200)
            ->push(['choices' => [['message' => ['content' => $safe]]]], 200);

        $preview = app(ReviewGenerationService::class)->preview([
            'product_id'          => 71,
            'count'               => 1,
            'date_from'           => '2026-10-01',
            'date_to'             => '2026-10-10',
            'rating_min'          => 4,
            'rating_max'          => 5,
            'rating_distribution' => 'natural',
            'review_length'       => 'medium',
            'countries'           => '中国',
            'examples'            => '瓶身小巧，外观简洁，适合日常携带。',
            'output_language'     => 'zh-CN',
        ]);

        self::assertSame('外形简洁', $preview['items'][0]['title']);
        self::assertStringNotContainsString('AI', $preview['items'][0]['body']);
        self::assertStringNotContainsString('商家', $preview['items'][0]['body']);
        Http::assertSentCount(2);
    }

    public function test_generation_retries_unrequested_source_or_contact_fields(): void
    {
        config([
            'bk.plugin.longos_product_reviews.siliconflow_api_key' => 'test-api-key',
            'bk.plugin.longos_product_reviews.siliconflow_model'   => 'XingChenAGI/Xing4.0-29B',
            'bk.plugin.longos_product_reviews.siliconflow_timeout' => 30,
        ]);
        Http::fakeSequence()
            ->push(['choices' => [['message' => ['content' => json_encode(['reviews' => [[
                'author_name' => 'Alex Morgan',
                'title'       => 'Convenient shape',
                'body'        => 'The narrow profile is straightforward to carry during the day.',
                'source'      => 'AI-generated',
                'email'       => 'alex@example.test',
            ]]], JSON_THROW_ON_ERROR)]]]], 200)
            ->push(['choices' => [['message' => ['content' => json_encode(['reviews' => [[
                'author_name' => 'Alex Morgan',
                'title'       => 'Convenient shape',
                'body'        => 'The narrow profile is straightforward to carry during the day.',
            ]]], JSON_THROW_ON_ERROR)]]]], 200);

        $preview = app(ReviewGenerationService::class)->preview([
            'product_id'          => 71,
            'count'               => 1,
            'date_from'           => '2026-10-01',
            'date_to'             => '2026-10-10',
            'rating_min'          => 4,
            'rating_max'          => 5,
            'rating_distribution' => 'natural',
            'review_length'       => 'short',
            'countries'           => 'United States',
            'examples'            => 'Compact and convenient for a daily bag.',
            'output_language'     => 'en-US',
        ]);

        self::assertSame(['spec_id', 'rating', 'country', 'reviewed_at', 'product_id', 'author_name', 'title', 'body'], array_keys($preview['items'][0]));
        self::assertArrayNotHasKey('angle', $preview['items'][0]);
        self::assertArrayNotHasKey('length_guidance', $preview['items'][0]);
        self::assertArrayNotHasKey('source', $preview['items'][0]);
        self::assertArrayNotHasKey('email', $preview['items'][0]);
        Http::assertSentCount(2);
    }

    public function test_async_generation_batch_encrypts_payload_tracks_progress_and_commits_selected_items_once(): void
    {
        register('admin_user', (new \Beike\Models\AdminUser)->forceFill(['id' => 19]), true);
        config([
            'bk.plugin.longos_product_reviews.siliconflow_api_key' => 'test-api-key',
            'bk.plugin.longos_product_reviews.siliconflow_model'   => 'XingChenAGI/Xing4.0-29B',
            'bk.plugin.longos_product_reviews.siliconflow_timeout' => 30,
        ]);
        Http::fake([
            'api.siliconflow.cn/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode(['reviews' => [
                    ['author_name' => 'Taylor Reed', 'title' => 'Convenient size', 'body' => 'The compact shape makes this straightforward to carry during the day.'],
                    ['author_name' => 'Aiko Mori', 'title' => 'Simple to use', 'body' => 'The cap opens smoothly and the exterior has a clean understated finish.'],
                    ['author_name' => 'Noah Martin', 'title' => 'Practical bottle', 'body' => 'It fits well in a side pocket and has been uncomplicated to rinse.'],
                ]], JSON_THROW_ON_ERROR)]]],
            ], 200),
        ]);

        $input = [
            'product_id'      => 71,
            'count'           => 3,
            'date_from'       => '2026-10-01',
            'date_to'         => '2026-10-10',
            'rating_min'      => 3,
            'rating_max'      => 5,
            'countries'       => "Japan\nFrance",
            'examples'        => 'Private style example that must be encrypted in storage.',
            'output_language' => 'en-US',
        ];
        $batches = app(ReviewGenerationBatchService::class);
        $batch   = $batches->create($input, 'XingChenAGI/Xing4.0-29B');
        self::assertTrue($batch->expires_at->gt(now()->addDays(6)));
        self::assertStringNotContainsString('Private style example', $batch->request_ciphertext);
        self::assertSame($input, $batches->requestData($batch));

        // A queued batch must not silently switch models after settings change.
        config(['bk.plugin.longos_product_reviews.siliconflow_model' => 'AnotherOrg/NewerModel']);
        $job = new GenerateReviewBatch($batch->id);
        $job->handle($batches, app(ReviewGenerationService::class));
        $batch->refresh();
        self::assertSame(ReviewGenerationBatch::STATUS_COMPLETED, $batch->status);
        self::assertSame($batch->id, $batches->latestResumableOwned()?->id);
        self::assertSame($batch->id, $batches->recentOwned()->first()?->id);
        self::assertSame(3, $batch->processed_count);
        self::assertStringNotContainsString('Convenient size', (string) $batch->result_ciphertext);

        $result = $batches->result($batch);
        self::assertIsArray($result);
        self::assertSame($batch->id, $result['batch_id']);
        self::assertSame('XingChenAGI/Xing4.0-29B', $result['model']);
        Http::assertSent(fn ($request): bool => $request['model'] === 'XingChenAGI/Xing4.0-29B'
            && is_string($request->header('X-Trace-Id')[0] ?? null)
            && ($request->header('X-Trace-Id')[0] ?? '') !== '');
        Http::assertSentCount(1);
        $job->handle($batches, app(ReviewGenerationService::class));
        Http::assertSentCount(1);
        $selected = [$result['items'][0]['spec_id'], $result['items'][2]['spec_id']];

        try {
            $batches->commit($batch->id, new ReviewService, ['999']);
            self::fail('A tampered preview selection must not be committed.');
        } catch (GenerationBatchException) {
            self::assertSame(0, Review::query()->where('generation_batch_id', $batch->id)->count());
            self::assertSame(ReviewGenerationBatch::STATUS_COMPLETED, $batch->refresh()->status);
        }

        $created  = $batches->commit($batch->id, new ReviewService, $selected);
        self::assertCount(2, $created);
        self::assertSame(ReviewGenerationBatch::STATUS_COMMITTED, $batch->refresh()->status);
        self::assertSame([$batch->id], $created->pluck('generation_batch_id')->unique()->values()->all());

        $this->expectException(GenerationBatchException::class);
        $batches->commit($batch->id, new ReviewService, $selected);
    }

    public function test_async_generation_batch_fails_safely_on_repeated_malformed_output(): void
    {
        register('admin_user', (new \Beike\Models\AdminUser)->forceFill(['id' => 20]), true);
        config([
            'bk.plugin.longos_product_reviews.siliconflow_api_key' => 'test-api-key',
            'bk.plugin.longos_product_reviews.siliconflow_model'   => 'XingChenAGI/Xing4.0-29B',
            'bk.plugin.longos_product_reviews.siliconflow_timeout' => 30,
        ]);
        Http::fakeSequence()
            ->push(['choices' => [['message' => ['content' => '{"unexpected":[]}']]]], 200)
            ->push(['choices' => [['message' => ['content' => '{"unexpected":[]}']]]], 200);
        $input = [
            'product_id'        => 71,
            'count'             => 1,
            'date_from'         => '2026-10-01',
            'date_to'           => '2026-10-10',
            'rating_min'        => 3,
            'rating_max'        => 5,
            'countries'         => 'Japan',
            'examples'          => 'A sufficiently detailed example review for style guidance.',
            'output_language'   => 'en-US',
            '_interface_locale' => 'zh_cn',
        ];
        $batches = app(ReviewGenerationBatchService::class);
        $batch   = $batches->create($input, 'XingChenAGI/Xing4.0-29B');
        $job     = new GenerateReviewBatch($batch->id);

        $job->handle($batches, app(ReviewGenerationService::class));

        self::assertSame(ReviewGenerationBatch::STATUS_FAILED, $batch->refresh()->status);
        self::assertSame(0, $batch->processed_count);
        self::assertNotSame('', trim((string) $batch->error));
        self::assertStringContainsString('模型返回', (string) $batch->error);
        self::assertNull($batch->result_ciphertext);
        self::assertSame(0, Review::query()->where('generation_batch_id', $batch->id)->count());
        Http::assertSentCount(2);
        $job->handle($batches, app(ReviewGenerationService::class));
        Http::assertSentCount(2);
    }

    public function test_active_product_batch_is_reused_and_can_be_cancelled_without_duplicate_work(): void
    {
        register('admin_user', (new \Beike\Models\AdminUser)->forceFill(['id' => 22]), true);
        $input = [
            'product_id'          => 71,
            'count'               => 3,
            'date_from'           => '2026-10-01',
            'date_to'             => '2026-10-10',
            'rating_min'          => 3,
            'rating_max'          => 5,
            'rating_distribution' => 'natural',
            'review_length'       => 'mixed',
            'countries'           => "Japan\nFrance",
            'examples'            => 'Encrypted temporary example content for idempotency.',
            'output_language'     => 'en-US',
        ];
        $batches = app(ReviewGenerationBatchService::class);
        $first   = $batches->create($input, 'XingChenAGI/Xing4.0-29B');
        $second  = $batches->create(array_replace($input, ['count' => 20]), 'XingChenAGI/Xing4.0-29B');

        self::assertSame($first->id, $second->id);
        self::assertFalse($second->wasRecentlyCreated);
        self::assertSame(1, ReviewGenerationBatch::query()->count());

        $cancelled = $batches->cancel($first->id);
        self::assertSame(ReviewGenerationBatch::STATUS_CANCELLED, $cancelled->status);
        self::assertTrue($cancelled->isFinished());
        self::assertFalse($batches->progress($cancelled, 1));
        Http::fake();
        (new GenerateReviewBatch($cancelled->id))->handle($batches, app(ReviewGenerationService::class));
        Http::assertNothingSent();
        self::assertSame(ReviewGenerationBatch::STATUS_CANCELLED, $cancelled->refresh()->status);

        $replacement = $batches->create($input, 'XingChenAGI/Xing4.0-29B');
        self::assertNotSame($first->id, $replacement->id);
        self::assertTrue($replacement->wasRecentlyCreated);
    }

    public function test_retryable_batch_requeue_refreshes_stale_clock_and_can_be_claimed_again(): void
    {
        register('admin_user', (new \Beike\Models\AdminUser)->forceFill(['id' => 24]), true);
        $batches = app(ReviewGenerationBatchService::class);
        $batch   = $batches->create([
            'product_id'      => 71,
            'count'           => 1,
            'date_from'       => '2026-10-01',
            'date_to'         => '2026-10-10',
            'rating_min'      => 4,
            'rating_max'      => 5,
            'countries'       => 'Japan',
            'examples'        => 'A sufficiently detailed encrypted example.',
            'output_language' => 'en-US',
        ], 'XingChenAGI/Xing4.0-29B');
        $claimed = $batches->claim($batch->id);
        self::assertNotNull($claimed);

        $claimed->forceFill(['updated_at' => now()->subHours(2)])->saveOrFail();
        self::assertTrue($batches->requeue($claimed));
        $batch->refresh();
        self::assertSame(ReviewGenerationBatch::STATUS_PENDING, $batch->status);
        self::assertTrue($batch->updated_at->gt(now()->subMinute()));
        self::assertNull($batch->started_at);
        self::assertNotNull($batches->claim($batch->id));
    }

    public function test_admin_pages_restore_the_latest_owned_generation_job_without_a_batch_query(): void
    {
        register('admin_user', (new \Beike\Models\AdminUser)->forceFill(['id' => 23]), true);
        $batches = app(ReviewGenerationBatchService::class);
        $batch   = $batches->create([
            'product_id'      => 71,
            'count'           => 2,
            'date_from'       => '2026-10-01',
            'date_to'         => '2026-10-10',
            'rating_min'      => 4,
            'rating_max'      => 5,
            'countries'       => 'Japan',
            'examples'        => 'An encrypted style example that is long enough.',
            'output_language' => 'en-US',
        ], 'XingChenAGI/Xing4.0-29B');

        $reviewIndex = (new AdminReviewController)->index(
            Request::create('/admin/product-reviews'),
            $batches,
        );
        self::assertSame($batch->id, $reviewIndex->getData()['resumeGenerationBatch']?->id);
        self::assertSame($batch->id, $reviewIndex->getData()['generationBatches']->first()?->id);
        Route::get('/admin/product-reviews/generate', static fn (): string => 'generator')
            ->name(admin_name() . '.longos_product_reviews.generate');
        Route::get('/admin/product-reviews/generate/{batch}/status', static fn (): string => 'status')
            ->name(admin_name() . '.longos_product_reviews.generate.status');
        Route::getRoutes()->refreshNameLookups();
        $tasksHtml = view('LongosProductReviews::admin.partials.generation_tasks', [
            'generationBatches' => $reviewIndex->getData()['generationBatches'],
            'watchTasks'        => true,
        ])->render();
        self::assertStringContainsString($batch->id, $tasksHtml);
        self::assertStringContainsString('data-generation-task', $tasksHtml);

        $generator = (new AdminReviewGenerationController)->index(
            Request::create('/admin/product-reviews/generate'),
            app(SiliconFlowSettings::class),
            $batches,
        );
        self::assertSame($batch->id, $generator->getData()['batch']?->id);
        self::assertSame($batch->id, $generator->getData()['recentBatches']->first()?->id);
    }

    public function test_generated_meta_copy_is_rejected_again_before_storage_edit_and_publication(): void
    {
        $service = new ReviewService;
        $unsafe  = [
            'product_id'  => 71,
            'spec_id'     => '1',
            'author_name' => 'Alex Morgan',
            'country'     => 'United States',
            'rating'      => 4,
            'title'       => 'AI-generated review',
            'body'        => 'This merchant-provided sample review describes the compact bottle.',
            'reviewed_at' => now()->subDay()->toDateTimeString(),
        ];

        try {
            $service->createGeneratedDrafts([$unsafe], (string) \Illuminate\Support\Str::uuid());
            self::fail('Meta copy must be rejected before a generated draft is stored.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            self::assertArrayHasKey('body', $exception->errors());
            self::assertSame(0, Review::query()->where('content_source', Review::SOURCE_GENERATED)->count());
        }

        $generated = $this->review(4, false, 0, 'A compact shape that is straightforward to carry.');
        $generated->forceFill([
            'content_source' => Review::SOURCE_GENERATED,
            'title'          => '商家自定义评论',
            'body'           => '这是一条由AI生成的示例评论。',
        ])->saveOrFail();

        try {
            $service->setPublished($generated->refresh(), true);
            self::fail('Unsafe legacy generated copy must not be publishable.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            self::assertArrayHasKey('body', $exception->errors());
            self::assertFalse($generated->refresh()->published);
        }

        $generated->forceFill(['published' => true])->saveOrFail();
        $storefront = $service->storefrontPage(71, 0, 12);
        self::assertSame(0, $storefront['total']);
        self::assertFalse($storefront['reviews']->contains('id', $generated->id));
    }

    public function test_generation_prune_command_removes_expired_encrypted_previews(): void
    {
        register('admin_user', (new \Beike\Models\AdminUser)->forceFill(['id' => 21]), true);
        $batches = app(ReviewGenerationBatchService::class);
        $batch   = $batches->create([
            'product_id'      => 71,
            'count'           => 1,
            'date_from'       => '2026-10-01',
            'date_to'         => '2026-10-10',
            'rating_min'      => 4,
            'rating_max'      => 5,
            'countries'       => 'Japan',
            'examples'        => 'Encrypted temporary example content.',
            'output_language' => 'en-US',
        ], 'XingChenAGI/Xing4.0-29B');
        $batch->forceFill(['expires_at' => now()->subMinute()])->saveOrFail();

        self::assertSame(0, Artisan::call('longos:product-reviews:prune-generation'));
        self::assertNull(ReviewGenerationBatch::query()->find($batch->id));
        self::assertStringContainsString('pruned 1 expired preview', Artisan::output());
    }

    public function test_review_admin_blade_templates_compile_with_generator_and_bulk_controls(): void
    {
        $compiler = app('blade.compiler');
        foreach (['generate.blade.php', 'index.blade.php', 'form.blade.php', 'partials/generation_tasks.blade.php'] as $file) {
            $source   = (string) file_get_contents(base_path('plugins/LongosProductReviews/Views/admin/' . $file));
            $compiled = $compiler->compileString($source);

            self::assertNotSame('', trim($compiled));
            self::assertStringContainsString('<?php', $compiled);
        }

        $generator = (string) file_get_contents(base_path('plugins/LongosProductReviews/Views/admin/generate.blade.php'));
        self::assertMatchesRegularExpression('/const searchUrl = @json\(.+\);$/m', $generator);
        self::assertMatchesRegularExpression('/const commitTemplate = @json\(.+\);$/m', $generator);
        self::assertMatchesRegularExpression('/const progressTemplate = @json\(.+\);$/m', $generator);
        self::assertMatchesRegularExpression('/count\.textContent = @json\(.+\);$/m', $generator);
    }

    public function test_bulk_verification_updates_manual_reviews_and_skips_generated_content(): void
    {
        $manual = $this->review(5, true, 0, 'A manual review with order evidence');
        $manual->update(['verified_purchase' => false]);
        $generated = $this->review(4, false, 0, 'The compact shape is straightforward to carry.');
        $generated->update(['verified_purchase' => false, 'content_source' => Review::SOURCE_GENERATED]);

        $result = (new ReviewService)->bulkSetVerification([$manual->id, $generated->id], true);

        self::assertSame(['updated' => 1, 'skipped' => 1], $result);
        self::assertTrue($manual->refresh()->verified_purchase);
        self::assertFalse($generated->refresh()->verified_purchase);
        self::assertSame(2, (new ReviewService)->bulkSetStaffReviewed([$manual->id, $generated->id], true));
        self::assertTrue($generated->refresh()->staff_reviewed);
        $generated->update(['published' => true]);
        $html = view('LongosProductReviews::shop.cards', ['reviews' => collect([$generated->refresh()])])->render();
        self::assertStringContainsString('Merchant-provided content', $html);
        self::assertMatchesRegularExpression('/The compact shape is straightforward to carry\.\s*<span class="longos-review-card__provided-inline">\s*·\s*Merchant-provided content<\/span>/', $html);
        self::assertStringNotContainsString('longos-review-card__provided">', $html);
        self::assertStringNotContainsString('Verified purchase', $html);
    }

    private function review(int $rating, bool $published, int $sortOrder, string $body): Review
    {
        return Review::query()->create([
            'product_id'         => 71,
            'author_name'        => 'Customer ' . $rating,
            'author_country'     => 'United States',
            'rating'             => $rating,
            'title'              => 'Review ' . $rating,
            'body'               => $body,
            'images'             => [],
            'verified_purchase'  => true,
            'published'          => $published,
            'sort_order'         => $sortOrder,
            'reviewed_at'        => now()->subDays(5 - $rating),
        ]);
    }
}
