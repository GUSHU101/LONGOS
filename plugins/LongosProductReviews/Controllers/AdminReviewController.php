<?php

namespace Plugin\LongosProductReviews\Controllers;

use App\Services\BundledPluginSynchronizer;
use Beike\Admin\Http\Controllers\Controller;
use Beike\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Plugin\LongosProductReviews\Models\Review;
use Plugin\LongosProductReviews\Requests\ReviewRequest;
use Plugin\LongosProductReviews\Services\ReviewService;

class AdminReviewController extends Controller
{
    public function index(Request $request): View
    {
        $this->ensureReady();

        $filters = [
            'q'          => trim((string) $request->query('q', '')),
            'rating'     => (int) $request->query('rating', 0),
            'published'  => $request->query('published', ''),
            'verified'   => $request->query('verified', ''),
            'photos'     => $request->query('photos', ''),
            'product_id' => max(0, (int) $request->query('product_id', 0)),
        ];
        $reviews = Review::query()->with(['product.description']);

        if ($filters['q'] !== '') {
            $term = '%' . $filters['q'] . '%';
            $reviews->where(function ($query) use ($term): void {
                $query->where('author_name', 'like', $term)
                    ->orWhere('title', 'like', $term)
                    ->orWhere('body', 'like', $term)
                    ->orWhereHas('product.description', fn ($product) => $product->where('name', 'like', $term));
            });
        }
        if (in_array($filters['rating'], range(1, 5), true)) {
            $reviews->where('rating', $filters['rating']);
        }
        if (in_array((string) $filters['published'], ['0', '1'], true)) {
            $reviews->where('published', (bool) $filters['published']);
        }
        if (in_array((string) $filters['verified'], ['0', '1'], true)) {
            $reviews->where('verified_purchase', (bool) $filters['verified']);
        }
        if ((string) $filters['photos'] === '1') {
            $reviews->whereJsonLength('images', '>', 0);
        }
        if ($filters['product_id'] > 0) {
            $reviews->where('product_id', $filters['product_id']);
        }

        $stats = [
            'total'     => Review::query()->count(),
            'published' => Review::query()->published()->count(),
            'drafts'    => Review::query()->where('published', false)->count(),
            'verified'  => Review::query()->where('verified_purchase', true)->count(),
            'average'   => round((float) Review::query()->published()->avg('rating'), 1),
        ];

        return view('LongosProductReviews::admin.index', [
            'reviews' => $reviews->orderByDesc('reviewed_at')->orderByDesc('id')->paginate(20)->withQueryString(),
            'filters' => $filters,
            'stats'   => $stats,
        ]);
    }

    public function create(Request $request): View
    {
        $this->ensureReady();

        $productId = max(0, (int) $request->query('product_id', 0));

        return $this->form(new Review(['product_id' => $productId, 'rating' => 5, 'published' => true, 'verified_purchase' => true, 'reviewed_at' => now()]));
    }

    public function store(ReviewRequest $request, ReviewService $service): RedirectResponse
    {
        $this->ensureReady();
        $review = $service->create($request->validated(), $request->file('images', []));

        return redirect()->to(admin_route('longos_product_reviews.edit', $review))->with('success', trans('common.created_success'));
    }

    public function edit(int $review): View
    {
        $this->ensureReady();

        return $this->form(Review::query()->findOrFail($review));
    }

    public function update(ReviewRequest $request, int $review, ReviewService $service): RedirectResponse
    {
        $this->ensureReady();
        $service->update(
            Review::query()->findOrFail($review),
            $request->validated(),
            $request->file('images', []),
            (array) $request->input('remove_images', []),
        );

        return back()->with('success', trans('common.updated_success'));
    }

    public function destroy(int $review, ReviewService $service): RedirectResponse
    {
        $this->ensureReady();
        $service->delete(Review::query()->findOrFail($review));

        return back()->with('success', trans('common.deleted_success'));
    }

    public function publication(Request $request, int $review, ReviewService $service): RedirectResponse
    {
        $this->ensureReady();
        $validated = $request->validate(['published' => ['required', 'boolean']]);
        $service->setPublished(Review::query()->findOrFail($review), (bool) $validated['published']);

        return back()->with('success', trans('LongosProductReviews::reviews.publication_updated'));
    }

    private function form(Review $review): View
    {
        $selectedProduct = null;
        if ($review->product_id) {
            $selectedProduct = Product::query()->with('description')->find($review->product_id);
        }

        $fallbackProducts = Product::query()
            ->with('description')
            ->where('active', true)
            ->orderByDesc('updated_at')
            ->limit(80)
            ->get();

        if ($selectedProduct && ! $fallbackProducts->contains('id', $selectedProduct->id)) {
            $fallbackProducts->prepend($selectedProduct);
        }

        return view('LongosProductReviews::admin.form', compact('review', 'selectedProduct', 'fallbackProducts'));
    }

    private function ensureReady(): void
    {
        app(BundledPluginSynchronizer::class)->ensureProductReviewsReady();
    }
}
