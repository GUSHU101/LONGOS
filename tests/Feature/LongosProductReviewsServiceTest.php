<?php

namespace Tests\Feature;

use Beike\Repositories\PluginRepo;
use Beike\Shop\Providers\PluginServiceProvider;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\TestCase;
use Plugin\LongosProductReviews\Controllers\AdminReviewController;
use Plugin\LongosProductReviews\Models\Review;
use Plugin\LongosProductReviews\Jobs\GenerateReviewDrafts;
use Plugin\LongosProductReviews\Services\SiliconFlowReviewDrafts;
use Plugin\LongosProductReviews\Services\ReviewService;

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
        $migration = require base_path('plugins/LongosProductReviews/Migrations/2026_10_09_000000_create_longos_product_reviews_table.php');
        $migration->up();
        $aiMigration = require base_path('plugins/LongosProductReviews/Migrations/2026_11_15_000000_add_ai_generated_to_longos_product_reviews_table.php');
        $aiMigration->up();
        DB::table('products')->insert(['id' => 71, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
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
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('space');
            $table->string('name');
            $table->text('value');
            $table->boolean('json')->default(false);
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
    }

    public function test_siliconflow_generates_complete_fictional_demo_record_and_never_publishes(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('space');
            $table->string('name');
            $table->text('value')->nullable();
            $table->boolean('json')->default(false);
            $table->timestamps();
        });
        Schema::create('product_descriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('locale', 20);
            $table->string('name');
            $table->text('content')->nullable();
            $table->timestamps();
        });
        DB::table('product_descriptions')->insert([
            'product_id' => 71,
            'locale' => 'en',
            'name' => 'Minimal Phone Case',
            'content' => 'Slim design with a smooth finish.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $generator = new SiliconFlowReviewDrafts;
        $generator->saveKey('sk-example-test-not-a-real-key');
        Http::fake([
            'https://api.siliconflow.cn/v1/chat/completions' => Http::response([
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['content' => json_encode(['drafts' => [[
                        'author_name' => 'Emily Parker',
                        'author_country' => 'United States',
                        'rating' => 4,
                        'title' => 'Simple design concept',
                        'body' => 'A minimal look with a smooth finish described in the product listing.',
                    ]]], JSON_THROW_ON_ERROR)],
                ]],
            ], 200),
        ]);

        $batchId = '00000000-0000-4000-8000-000000000001';
        $key = GenerateReviewDrafts::cacheKey('central', $batchId);
        Cache::put($key, ['status' => 'queued', 'total' => 1, 'completed' => 0, 'created' => 0, 'failed' => 0], 300);

        $job = new GenerateReviewDrafts($batchId, 71, 1, 'en', 'central');
        $job->handle($generator);

        $review = Review::query()->sole();
        self::assertSame('DEMO · Emily Parker', $review->author_name);
        self::assertSame('United States', $review->author_country);
        self::assertSame(4, $review->rating);
        self::assertSame('Simple design concept', $review->title);
        self::assertTrue($review->ai_generated);
        self::assertFalse($review->published);
        self::assertFalse($review->verified_purchase);
        self::assertSame(1, Cache::get($key)['created']);
        self::assertSame('finished', Cache::get($key)['status']);

        Http::assertSent(static fn ($request): bool => $request->data()['model'] === SiliconFlowReviewDrafts::MODEL
            && ($request->data()['messages'][0]['role'] ?? null) === 'system');

        try {
            (new ReviewService)->setPublished($review, true);
            self::fail('Demo record publication must be prohibited.');
        } catch (\Illuminate\Validation\ValidationException) {
            self::assertFalse($review->fresh()->published);
        }

        (new ReviewService)->update($review, [
            'product_id' => 71,
            'author_name' => 'Someone Else',
            'author_country' => 'Canada',
            'rating' => 5,
            'title' => 'Updated sample',
            'body' => 'A neutral internal-only sample of the product listing.',
            'reviewed_at' => now(),
            'published' => true,
            'verified_purchase' => true,
        ]);
        self::assertTrue($review->fresh()->ai_generated);
        self::assertFalse($review->fresh()->published);
        self::assertFalse($review->fresh()->verified_purchase);
    }

    public function test_siliconflow_rejects_output_without_complete_demo_fields(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('space');
            $table->string('name');
            $table->text('value')->nullable();
            $table->boolean('json')->default(false);
            $table->timestamps();
        });
        Schema::create('product_descriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('locale', 20);
            $table->string('name');
            $table->text('content')->nullable();
            $table->timestamps();
        });
        DB::table('product_descriptions')->insert([
            'product_id' => 71,
            'locale' => 'en',
            'name' => 'Minimal Phone Case',
            'content' => 'Slim design.',
        ]);
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $generator = new SiliconFlowReviewDrafts;
        $generator->saveKey('sk-example-test-not-a-real-key');
        Http::fake([
            'https://api.siliconflow.cn/v1/chat/completions' => Http::response([
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['content' => '{"drafts":[{"title":"Only a title","body":"Missing fictional person, country and rating."}]}'],
                ]],
            ], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SILICONFLOW_INVALID_DRAFT');
        $generator->generate(\Beike\Models\Product::query()->findOrFail(71), 1, 'en');
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
