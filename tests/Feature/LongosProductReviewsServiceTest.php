<?php

namespace Tests\Feature;

use App\Services\SiliconFlowRateLimiter as SharedSiliconFlowRateLimiter;
use Beike\Repositories\PluginRepo;
use Beike\Shop\Providers\PluginServiceProvider;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Plugin\LongosProductReviews\Controllers\AdminReviewController;
use Plugin\LongosProductReviews\Controllers\AdminReviewGenerationController;
use Plugin\LongosProductReviews\Exceptions\GenerationBatchException;
use Plugin\LongosProductReviews\Jobs\GenerateReviewBatch;
use Plugin\LongosProductReviews\Models\Review;
use Plugin\LongosProductReviews\Models\ReviewGenerationBatch;
use Plugin\LongosProductReviews\Requests\GenerateReviewsRequest;
use Plugin\LongosProductReviews\Services\ReviewGenerationBatchService;
use Plugin\LongosProductReviews\Services\ReviewGenerationLimits;
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

    public function test_storefront_card_summaries_batch_products_and_match_detail_visibility_rules(): void
    {
        DB::table('products')->insert(['id' => 72, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $this->review(5, true, 3, 'Excellent quality');
        $this->review(4, true, 2, 'Very good overall');
        $this->review(1, false, 100, 'Draft must never affect the card rating');
        $unsafe = $this->review(1, true, 99, 'Safe text before simulating legacy content.');
        $unsafe->update([
            'content_source' => Review::SOURCE_GENERATED,
            'body'           => 'This is an AI-generated sample review.',
        ]);

        Review::query()->create([
            'product_id'        => 72,
            'author_name'       => 'Second Customer',
            'author_country'    => 'Canada',
            'rating'            => 3,
            'title'             => 'Second product review',
            'body'              => 'A published review for the second product.',
            'images'            => [],
            'verified_purchase' => true,
            'published'         => true,
            'sort_order'        => 0,
            'reviewed_at'       => now(),
        ]);

        $service   = new ReviewService;
        $summaries = $service->storefrontSummaries([71, 72, 999, 71]);

        self::assertSame(['average' => 4.5, 'total' => 2], $summaries[71]);
        self::assertSame(['average' => 3.0, 'total' => 1], $summaries[72]);
        self::assertArrayNotHasKey(999, $summaries);
        self::assertSame($summaries[71], $service->storefrontSummaries([71])[71]);

        $html = view('LongosProductReviews::shop.card_rating', [
            'summary'    => $summaries[71],
            'productUrl' => '/products/71?variant=black#old-fragment',
        ])->render();

        self::assertStringContainsString('href="/products/71?variant=black#customer-reviews"', $html);
        self::assertStringContainsString('class="longos-card-rating__stars"', $html);
        self::assertStringContainsString('--longos-card-rating: 90.00%', $html);
        self::assertStringContainsString('<strong>4.5</strong>', $html);
        self::assertStringContainsString('(2)', $html);

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
        self::assertContains(
            'throttle:product-review-admin',
            $routes->first(static fn ($route): bool => $route->getName() === admin_name() . '.longos_product_reviews.generate.test')->gatherMiddleware(),
        );
        self::assertContains(
            'throttle:product-review-admin',
            $routes->first(static fn ($route): bool => $route->getName() === admin_name() . '.longos_product_reviews.generate.preview')->gatherMiddleware(),
        );
        self::assertContains(
            'throttle:product-review-admin',
            $routes->first(static fn ($route): bool => $route->getName() === admin_name() . '.longos_product_reviews.generate.cancel')->gatherMiddleware(),
        );
        self::assertNotContains(
            'throttle:120,1',
            $routes->first(static fn ($route): bool => $route->getName() === admin_name() . '.longos_product_reviews.generate.status')->gatherMiddleware(),
        );
        $limiter = RateLimiter::limiter('product-review-admin');
        self::assertIsCallable($limiter);
        $limiterRequest = Request::create('/admin/product-reviews/generate/test-connection', 'POST');
        $limiterRoute   = (new \Illuminate\Routing\Route('POST', '/admin/product-reviews/generate/test-connection', fn (): null => null))
            ->name(admin_name() . '.longos_product_reviews.generate.test');
        $limiterRequest->setRouteResolver(static fn () => $limiterRoute);
        $limit = $limiter($limiterRequest);
        self::assertSame(30, $limit->maxAttempts);
        self::assertIsCallable($limit->responseCallback);
    }

    public function test_generation_limit_accepts_500_and_rejects_501_everywhere(): void
    {
        self::assertSame(500, ReviewGenerationLimits::MAX_REVIEWS_PER_BATCH);

        $countRules = (new GenerateReviewsRequest)->rules()['count'];
        self::assertFalse(Validator::make(['count' => 500], ['count' => $countRules])->fails());
        self::assertTrue(Validator::make(['count' => 501], ['count' => $countRules])->fails());

        $generator  = (string) file_get_contents(base_path('plugins/LongosProductReviews/Views/admin/generate.blade.php'));
        $controller = (string) file_get_contents(base_path('plugins/LongosProductReviews/Controllers/AdminReviewGenerationController.php'));
        $batches    = (string) file_get_contents(base_path('plugins/LongosProductReviews/Services/ReviewGenerationBatchService.php'));

        self::assertStringContainsString('ReviewGenerationLimits::MAX_REVIEWS_PER_BATCH', $generator);
        self::assertStringContainsString('ReviewGenerationLimits::MAX_REVIEWS_PER_BATCH', $controller);
        self::assertStringContainsString('ReviewGenerationLimits::MAX_REVIEWS_PER_BATCH', $batches);
        self::assertStringNotContainsString("'max:30'", $controller . $batches);
    }

    public function test_maximum_500_item_preview_can_be_completed_and_committed(): void
    {
        register('admin_user', (new \Beike\Models\AdminUser)->forceFill(['id' => 31]), true);
        $service = app(ReviewGenerationBatchService::class);
        $batch   = $service->create([
            'product_id' => 71,
            'count'      => ReviewGenerationLimits::MAX_REVIEWS_PER_BATCH,
        ], 'XingChenAGI/Xing4.0-29B');
        $batch = $service->claim($batch->id);
        self::assertNotNull($batch);

        $items = [];
        foreach (range(1, ReviewGenerationLimits::MAX_REVIEWS_PER_BATCH) as $index) {
            $items[] = [
                'spec_id'     => (string) $index,
                'product_id'  => 71,
                'author_name' => 'Customer ' . $index,
                'country'     => 'United States',
                'rating'      => 5,
                'title'       => 'Practical choice ' . $index,
                'body'        => 'The compact shape is straightforward to carry for routine ' . $index . '.',
                'reviewed_at' => now()->subMinute()->toDateTimeString(),
            ];
        }

        $service->complete($batch, [
            'batch_id'     => $batch->id,
            'product_id'   => 71,
            'product_name' => 'LONGOS Travel Bottle',
            'model'        => 'XingChenAGI/Xing4.0-29B',
            'items'        => $items,
        ]);

        $created = $service->commit(
            $batch->id,
            new ReviewService,
            array_map(static fn (int $id): string => (string) $id, range(1, ReviewGenerationLimits::MAX_REVIEWS_PER_BATCH)),
        );

        self::assertCount(500, $created);
        self::assertSame(500, Review::query()->where('generation_batch_id', $batch->id)->count());
        self::assertSame(ReviewGenerationBatch::STATUS_COMMITTED, $batch->refresh()->status);
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

    public function test_connection_model_discovery_is_rate_gated_and_short_lived_cached(): void
    {
        $apiKey = 'models-cache-test-key';
        config([
            'bk.plugin.longos_product_reviews.siliconflow_api_key' => $apiKey,
            'bk.plugin.longos_product_reviews.siliconflow_model'   => 'XingChenAGI/Xing4.0-29B',
            'bk.plugin.longos_product_reviews.siliconflow_timeout' => 30,
        ]);
        Cache::forget('longos:siliconflow:chat-models:' . hash('sha256', $apiKey));
        Cache::forget('longos:siliconflow:chat-models:' . hash('sha256', $apiKey) . ':stale');
        Cache::forget('longos:siliconflow:rate:' . hash('sha256', $apiKey . "\0XingChenAGI/Xing4.0-29B"));
        Http::fake([
            'api.siliconflow.cn/v1/models*' => Http::response(['data' => [
                ['id' => 'XingChenAGI/Xing4.0-29B'],
                ['id' => 'tencent/Hunyuan-MT-7B'],
            ]], 200),
        ]);

        $client = app(SiliconFlowClient::class);
        self::assertSame(
            ['XingChenAGI/Xing4.0-29B', 'tencent/Hunyuan-MT-7B'],
            $client->availableChatModels(),
        );
        self::assertSame(
            ['XingChenAGI/Xing4.0-29B', 'tencent/Hunyuan-MT-7B'],
            $client->availableChatModels(),
        );

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.siliconflow.cn/v1/models?sub_type=chat'
            && $request->hasHeader('Authorization', 'Bearer ' . $apiKey));
    }

    public function test_connection_model_discovery_uses_stale_success_during_provider_429(): void
    {
        $apiKey   = 'models-stale-fallback-key';
        $cacheKey = 'longos:siliconflow:chat-models:' . hash('sha256', $apiKey);
        config([
            'bk.plugin.longos_product_reviews.siliconflow_api_key' => $apiKey,
            'bk.plugin.longos_product_reviews.siliconflow_model'   => 'XingChenAGI/Xing4.0-29B',
            'bk.plugin.longos_product_reviews.siliconflow_timeout' => 30,
        ]);
        Cache::forget($cacheKey);
        Cache::put($cacheKey . ':stale', ['XingChenAGI/Xing4.0-29B'], now()->addHour());
        Cache::forget('longos:siliconflow:rate:' . hash('sha256', $apiKey . "\0XingChenAGI/Xing4.0-29B"));
        Http::fake([
            'api.siliconflow.cn/v1/models*' => Http::response(
                ['message' => 'RPM limit reached'],
                429,
                ['Retry-After' => '60'],
            ),
        ]);

        $client = app(SiliconFlowClient::class);
        self::assertSame(['XingChenAGI/Xing4.0-29B'], $client->availableChatModels());
        // The provider cooldown is shared, so the next click returns the last
        // known-good model list without sending another request or waiting.
        self::assertSame(['XingChenAGI/Xing4.0-29B'], $client->availableChatModels());
        Http::assertSentCount(1);
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
        Http::fake(function ($request) use (&$call) {
            $payload   = json_decode((string) data_get($request->data(), 'messages.1.content'), true, 64, JSON_THROW_ON_ERROR);
            $requested = count((array) data_get($payload, 'specifications'));
            $rows      = [];
            foreach (range(0, $requested - 1) as $index) {
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
        self::assertSame([
            'XingChenAGI/Xing4.0-29B',
            'XingChenAGI/Xing4.0-29B',
            'tencent/Hunyuan-MT-7B',
            'tencent/Hunyuan-MT-7B',
        ], $sentModels);
        foreach (Http::recorded() as [$request]) {
            $payload = json_decode((string) data_get($request->data(), 'messages.1.content'), true, 64, JSON_THROW_ON_ERROR);
            self::assertCount(5, (array) data_get($payload, 'specifications'));
            self::assertGreaterThanOrEqual(2_688, (int) data_get($request->data(), 'max_tokens'));
            self::assertLessThanOrEqual(7_808, (int) data_get($request->data(), 'max_tokens'));
        }
        Http::assertSentCount(4);
    }

    public function test_generation_shrinks_truncated_chunks_until_every_review_is_complete(): void
    {
        config([
            'bk.plugin.longos_product_reviews.siliconflow_api_key' => 'test-api-key',
            'bk.plugin.longos_product_reviews.siliconflow_model'   => 'XingChenAGI/Xing4.0-29B',
            'bk.plugin.longos_product_reviews.siliconflow_models'  => "XingChenAGI/Xing4.0-29B\ntencent/Hunyuan-MT-7B",
            'bk.plugin.longos_product_reviews.siliconflow_timeout' => 30,
        ]);
        $call = 0;
        Http::fake(function ($request) use (&$call) {
            $payload   = json_decode((string) data_get($request->data(), 'messages.1.content'), true, 64, JSON_THROW_ON_ERROR);
            $requested = count((array) data_get($payload, 'specifications'));
            $call++;
            if ($requested > 1) {
                return Http::response([
                    'choices' => [[
                        'finish_reason' => 'length',
                        'message'       => ['content' => '{"reviews":['],
                    ]],
                ]);
            }

            $token = strtr(hash('sha256', 'adaptive:' . $call), '0123456789', 'qwertyuiop');

            return Http::response([
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message'       => ['content' => json_encode(['reviews' => [[
                        'author_name' => 'Customer ' . ucfirst(substr($token, 0, 12)),
                        'title'       => 'Adaptive result ' . substr($token, 12, 12),
                        'body'        => 'The compact shape fits neatly into a daily bag and the controls remain accessible ' . $token . '.',
                    ]]], JSON_THROW_ON_ERROR)],
                ]],
            ]);
        });

        $preview = app(ReviewGenerationService::class)->preview([
            'product_id'          => 71,
            'count'               => 6,
            'date_from'           => '2026-10-01',
            'date_to'             => '2026-10-10',
            'rating_min'          => 4,
            'rating_max'          => 5,
            'rating_distribution' => 'balanced',
            'review_length'       => 'long',
            'countries'           => "Japan\nFrance",
            'examples'            => 'Compact and useful, with a simple finish suitable for routine use.',
            'output_language'     => 'en-US',
            '_models'             => ['XingChenAGI/Xing4.0-29B', 'tencent/Hunyuan-MT-7B'],
        ]);

        self::assertCount(6, $preview['items']);
        self::assertCount(6, array_unique(array_column($preview['items'], 'author_name')));
        $requestedCounts = Http::recorded()->map(function (array $pair): int {
            $payload = json_decode((string) data_get($pair[0]->data(), 'messages.1.content'), true, 64, JSON_THROW_ON_ERROR);

            return count((array) data_get($payload, 'specifications'));
        })->all();
        self::assertSame(2, count(array_filter($requestedCounts, static fn (int $count): bool => $count === 5)));
        self::assertSame(1, count(array_filter($requestedCounts, static fn (int $count): bool => $count === 2)));
        self::assertSame(7, count(array_filter($requestedCounts, static fn (int $count): bool => $count === 1)));
        Http::assertSentCount(10);
    }

    public function test_parallel_generation_refills_only_duplicate_rows_and_rotates_the_retry_model(): void
    {
        config([
            'bk.plugin.longos_product_reviews.siliconflow_api_key' => 'test-api-key',
            'bk.plugin.longos_product_reviews.siliconflow_model'   => 'XingChenAGI/Xing4.0-29B',
            'bk.plugin.longos_product_reviews.siliconflow_models'  => "XingChenAGI/Xing4.0-29B\ntencent/Hunyuan-MT-7B",
            'bk.plugin.longos_product_reviews.siliconflow_timeout' => 30,
        ]);
        $call = 0;
        Http::fake(function ($request) use (&$call) {
            $requestNumber = $call++;
            $payload       = json_decode((string) data_get($request->data(), 'messages.1.content'), true, 64, JSON_THROW_ON_ERROR);
            $requested     = count((array) data_get($payload, 'specifications'));
            $rows          = [];
            foreach (range(0, $requested - 1) as $index) {
                $token  = strtr(hash('sha256', 'parallel-refill:' . $requestNumber . ':' . $index), '0123456789', 'qwertyuiop');
                $rows[] = [
                    'author_name' => 'Customer ' . ucfirst(substr($token, 0, 12)),
                    'title'       => 'Different handling ' . substr($token, 12, 12),
                    'body'        => 'This review uses a separate observation and vocabulary marker ' . $token . ' for uniqueness.',
                ];
            }
            if ($requestNumber === 0) {
                $rows[1] = $rows[0];
            }

            return Http::response([
                'choices' => [['message' => ['content' => json_encode(['reviews' => $rows], JSON_THROW_ON_ERROR)]]],
            ]);
        });

        $preview = app(ReviewGenerationService::class)->preview([
            'product_id'          => 71,
            'count'               => 20,
            'date_from'           => '2026-10-01',
            'date_to'             => '2026-10-20',
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
        self::assertCount(20, array_unique(array_column($preview['items'], 'author_name')));
        $requests = Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->values();
        $retry    = json_decode((string) data_get($requests[4], 'messages.1.content'), true, 64, JSON_THROW_ON_ERROR);
        self::assertCount(1, (array) data_get($retry, 'specifications'));
        self::assertSame('tencent/Hunyuan-MT-7B', data_get($requests[4], 'model'));
        Http::assertSentCount(5);
    }

    public function test_shared_rate_limiter_releases_unused_token_reservations(): void
    {
        $limiter    = app(SharedSiliconFlowRateLimiter::class);
        $apiKey     = 'review-settlement-' . (string) \Illuminate\Support\Str::uuid();
        $model      = 'XingChenAGI/Xing4.0-29B';
        $reserved   = 20_000;
        $reservedAt = time();

        self::assertSame(0, $limiter->reserveCapacity($apiKey, $model, $reserved));
        self::assertGreaterThan(0, $limiter->reserveCapacity($apiKey, $model, $reserved));

        $limiter->settleCapacity($apiKey, $model, $reserved, 2_000, $reservedAt);

        self::assertSame(0, $limiter->reserveCapacity($apiKey, $model, $reserved));
    }

    public function test_siliconflow_client_settles_reported_usage_before_the_next_large_request(): void
    {
        config([
            'bk.plugin.longos_product_reviews.siliconflow_api_key' => 'settlement-client-' . (string) \Illuminate\Support\Str::uuid(),
            'bk.plugin.longos_product_reviews.siliconflow_model'   => 'XingChenAGI/Xing4.0-29B',
            'bk.plugin.longos_product_reviews.siliconflow_timeout' => 30,
        ]);
        Http::fake([
            'api.siliconflow.cn/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{"reviews":[]}']]],
                'usage'   => ['total_tokens' => 500],
            ]),
        ]);
        $messages = [['role' => 'user', 'content' => str_repeat('a', 35_000)]];
        $client   = app(SiliconFlowClient::class);

        self::assertSame(['reviews' => []], $client->chatJson($messages, 8_192));
        self::assertSame(['reviews' => []], $client->chatJson($messages, 8_192));
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

    public function test_generation_keeps_valid_rows_and_only_regenerates_duplicate_gaps(): void
    {
        config([
            'bk.plugin.longos_product_reviews.siliconflow_api_key' => 'test-api-key',
            'bk.plugin.longos_product_reviews.siliconflow_model'   => 'XingChenAGI/Xing4.0-29B',
            'bk.plugin.longos_product_reviews.siliconflow_timeout' => 30,
        ]);
        $this->review(4, true, 0, 'Existing exact duplicate body');

        $firstPass = json_encode(['reviews' => [
            [
                'author_name' => 'Jamie North',
                'title'       => 'Compact profile',
                'body'        => 'Slim edges make the case comfortable to slide into a small evening bag.',
            ],
            [
                'author_name' => 'Jamie North',
                'title'       => 'Compact profile',
                'body'        => 'Slim edges make the case comfortable to slide into a small evening bag.',
            ],
            [
                'author_name' => 'Aiko Fujita',
                'title'       => 'Distinct clasp',
                'body'        => 'The magnetic closure aligns quickly, while the stitched border gives the exterior a tidy look.',
            ],
            [
                'author_name' => 'Legacy Customer',
                'title'       => 'Review 4',
                'body'        => 'Existing exact duplicate body',
            ],
        ]], JSON_THROW_ON_ERROR);
        $replacementPass = json_encode(['reviews' => [
            [
                'author_name' => 'Priya Shah',
                'title'       => 'Room for essentials',
                'body'        => 'Cards sit in separate slots, and the wrist strap makes short errands more convenient.',
            ],
            [
                'author_name' => 'Camille Roy',
                'title'       => 'Soft interior lining',
                'body'        => 'Inside material feels smooth around the phone, with cutouts that remain easy to reach.',
            ],
        ]], JSON_THROW_ON_ERROR);
        Http::fakeSequence()
            ->push(['choices' => [['message' => ['content' => $firstPass]]]], 200)
            ->push(['choices' => [['message' => ['content' => $replacementPass]]]], 200);

        $preview = app(ReviewGenerationService::class)->preview([
            'product_id'          => 71,
            'count'               => 4,
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

        self::assertCount(4, $preview['items']);
        $itemsBySpec = collect($preview['items'])->keyBy('spec_id');
        self::assertSame('Compact profile', $itemsBySpec->get('1')['title']);
        self::assertSame('Room for essentials', $itemsBySpec->get('2')['title']);
        self::assertSame('Distinct clasp', $itemsBySpec->get('3')['title']);
        self::assertSame('Soft interior lining', $itemsBySpec->get('4')['title']);
        self::assertCount(4, array_unique(array_column($preview['items'], 'author_name')));

        $requests = Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->values();
        $first    = json_decode((string) data_get($requests[0], 'messages.1.content'), true, 64, JSON_THROW_ON_ERROR);
        $retry    = json_decode((string) data_get($requests[1], 'messages.1.content'), true, 64, JSON_THROW_ON_ERROR);
        self::assertCount(4, (array) data_get($first, 'specifications'));
        self::assertCount(2, (array) data_get($retry, 'specifications'));
        self::assertStringContainsString('remaining 2 items only', (string) data_get($requests[1], 'messages.0.content'));
        Http::assertSentCount(2);
    }

    public function test_generation_keeps_valid_rows_when_one_row_fails_content_validation(): void
    {
        config([
            'bk.plugin.longos_product_reviews.siliconflow_api_key' => 'test-api-key',
            'bk.plugin.longos_product_reviews.siliconflow_model'   => 'XingChenAGI/Xing4.0-29B',
            'bk.plugin.longos_product_reviews.siliconflow_timeout' => 30,
        ]);
        Http::fakeSequence()
            ->push(['choices' => [['message' => ['content' => json_encode(['reviews' => [
                [
                    'author_name' => 'Nora Kim',
                    'title'       => 'Neat exterior',
                    'body'        => 'The clean outer finish works well with a compact everyday bag.',
                ],
                [
                    'author_name' => 'Invalid Row',
                    'title'       => 'Extra metadata',
                    'body'        => 'This otherwise usable sentence includes a field that was never requested.',
                    'source'      => 'model-added',
                ],
                [
                    'author_name' => 'Leo Martin',
                    'title'       => 'Accessible buttons',
                    'body'        => 'The side controls remain easy to press and the opening around the camera is tidy.',
                ],
            ]], JSON_THROW_ON_ERROR)]]]], 200)
            ->push(['choices' => [['message' => ['content' => json_encode(['reviews' => [[
                'author_name' => 'Mina Patel',
                'title'       => 'Useful card slots',
                'body'        => 'The separate slots keep a few essential cards organized without adding much bulk.',
            ]]], JSON_THROW_ON_ERROR)]]]], 200);

        $preview = app(ReviewGenerationService::class)->preview([
            'product_id'          => 71,
            'count'               => 3,
            'date_from'           => '2026-10-01',
            'date_to'             => '2026-10-10',
            'rating_min'          => 4,
            'rating_max'          => 5,
            'rating_distribution' => 'balanced',
            'review_length'       => 'medium',
            'countries'           => 'Japan',
            'examples'            => 'A sufficiently detailed example review for style guidance.',
            'output_language'     => 'en-US',
        ]);

        $itemsBySpec = collect($preview['items'])->keyBy('spec_id');
        self::assertSame('Neat exterior', $itemsBySpec->get('1')['title']);
        self::assertSame('Useful card slots', $itemsBySpec->get('2')['title']);
        self::assertSame('Accessible buttons', $itemsBySpec->get('3')['title']);
        $requests = Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->values();
        $retry    = json_decode((string) data_get($requests[1], 'messages.1.content'), true, 64, JSON_THROW_ON_ERROR);
        self::assertCount(1, (array) data_get($retry, 'specifications'));
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

    public function test_async_generation_batch_requeues_after_repeated_single_item_truncation(): void
    {
        register('admin_user', (new \Beike\Models\AdminUser)->forceFill(['id' => 25]), true);
        config([
            'bk.plugin.longos_product_reviews.siliconflow_api_key' => 'test-api-key',
            'bk.plugin.longos_product_reviews.siliconflow_model'   => 'XingChenAGI/Xing4.0-29B',
            'bk.plugin.longos_product_reviews.siliconflow_timeout' => 30,
        ]);
        Http::fake([
            'api.siliconflow.cn/v1/chat/completions' => Http::response([
                'choices' => [[
                    'finish_reason' => 'length',
                    'message'       => ['content' => '{"reviews":['],
                ]],
            ]),
        ]);
        $batches = app(ReviewGenerationBatchService::class);
        $batch   = $batches->create([
            'product_id'      => 71,
            'count'           => 1,
            'date_from'       => '2026-10-01',
            'date_to'         => '2026-10-10',
            'rating_min'      => 4,
            'rating_max'      => 5,
            'countries'       => 'Japan',
            'examples'        => 'A sufficiently detailed example review for style guidance.',
            'output_language' => 'en-US',
        ], 'XingChenAGI/Xing4.0-29B');

        (new GenerateReviewBatch($batch->id))->handle($batches, app(ReviewGenerationService::class));

        $batch->refresh();
        self::assertSame(ReviewGenerationBatch::STATUS_PENDING, $batch->status);
        self::assertSame(0, $batch->processed_count);
        self::assertNull($batch->started_at);
        self::assertNull($batch->error);
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
        self::assertStringContainsString('data-connection-test-form', $generator);
        self::assertStringContainsString('delay * 2', $generator);
        self::assertStringNotContainsString('setInterval(poll', $generator);
    }

    public function test_product_card_templates_compile_with_the_review_rating_hook(): void
    {
        $compiler = app('blade.compiler');
        foreach ([
            'themes/default/shared/product.blade.php',
            'plugins/LongosChristmasTheme/Themes/longos_christmas/shared/product.blade.php',
            'plugins/LongosProductReviews/Views/shop/card_assets.blade.php',
            'plugins/LongosProductReviews/Views/shop/card_rating.blade.php',
        ] as $file) {
            $source   = (string) file_get_contents(base_path($file));
            $compiled = $compiler->compileString($source);

            self::assertNotSame('', trim($compiled));
            self::assertStringContainsString('<?php', $compiled);
        }

        $defaultCard = (string) file_get_contents(base_path('themes/default/shared/product.blade.php'));
        $category    = (string) file_get_contents(base_path('beike/Shop/Http/Controllers/CategoryController.php'));
        self::assertStringContainsString("\$product['review_summary']", $defaultCard);
        self::assertStringContainsString('withProductReviewSummaries($productsFormat)', $category);
        self::assertStringContainsString('storefrontSummaries($productIds)', $category);
    }

    public function test_generated_reviews_support_individual_and_bulk_verification_updates(): void
    {
        $manual = $this->review(5, true, 0, 'A manual review with order evidence');
        $manual->update(['verified_purchase' => false]);
        $generated = $this->review(4, false, 0, 'The compact shape is straightforward to carry.');
        $generated->update(['verified_purchase' => false, 'content_source' => Review::SOURCE_GENERATED]);
        $service = new ReviewService;

        $service->update($generated, [
            'product_id'        => $generated->product_id,
            'author_name'       => $generated->author_name,
            'author_country'    => $generated->author_country,
            'rating'            => $generated->rating,
            'title'             => $generated->title,
            'body'              => $generated->body,
            'verified_purchase' => true,
            'published'         => false,
            'sort_order'        => $generated->sort_order,
            'reviewed_at'       => $generated->reviewed_at,
        ]);
        self::assertTrue($generated->refresh()->verified_purchase);
        $generated->update(['verified_purchase' => false]);

        $updated = $service->bulkSetVerification([$manual->id, $generated->id], true);

        self::assertSame(2, $updated);
        self::assertTrue($manual->refresh()->verified_purchase);
        self::assertTrue($generated->refresh()->verified_purchase);
        self::assertSame(2, $service->bulkSetStaffReviewed([$manual->id, $generated->id], true));
        self::assertTrue($generated->refresh()->staff_reviewed);
        $generated->update(['published' => true]);
        $html = view('LongosProductReviews::shop.cards', ['reviews' => collect([$generated->refresh()])])->render();
        self::assertStringContainsString('The compact shape is straightforward to carry.', $html);
        self::assertStringNotContainsString('Merchant-provided content', $html);
        self::assertStringNotContainsString('longos-review-card__provided', $html);
        self::assertStringContainsString('Verified purchase', $html);
    }

    public function test_bulk_publication_updates_manual_and_safe_generated_reviews_atomically(): void
    {
        $service   = new ReviewService;
        $manual    = $this->review(5, false, 0, 'A concise manual review ready to publish.');
        $generated = $this->review(4, false, 0, 'The compact shape is straightforward to carry every day.');
        $generated->update(['content_source' => Review::SOURCE_GENERATED]);

        self::assertSame(2, $service->bulkSetPublished([$manual->id, $generated->id], true));
        self::assertTrue($manual->refresh()->published);
        self::assertTrue($generated->refresh()->published);

        self::assertSame(2, $service->bulkSetPublished([$manual->id, $generated->id], false));
        self::assertFalse($manual->refresh()->published);
        self::assertFalse($generated->refresh()->published);

        $unsafe = $this->review(3, false, 0, 'Safe text before simulating legacy unsafe content.');
        $unsafe->update([
            'content_source' => Review::SOURCE_GENERATED,
            'body'           => 'This is an AI-generated sample review.',
        ]);

        try {
            $service->bulkSetPublished([$manual->id, $unsafe->id], true);
            self::fail('Unsafe legacy generated content must reject the entire bulk publication.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('body', $exception->errors());
        }

        self::assertFalse($manual->refresh()->published);
        self::assertFalse($unsafe->refresh()->published);
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
