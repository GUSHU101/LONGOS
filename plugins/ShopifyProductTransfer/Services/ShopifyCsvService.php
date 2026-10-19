<?php

namespace Plugin\ShopifyProductTransfer\Services;

use Beike\Admin\Services\CategoryService;
use Beike\Admin\Services\ProductService;
use Beike\Admin\Services\RemoteImageImporter;
use Beike\Libraries\Weight;
use Beike\Models\Brand;
use Beike\Models\CategoryDescription;
use Beike\Models\Product;
use Beike\Models\ProductSku;
use Beike\Repositories\CategoryRepo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShopifyCsvService
{
    private const MAX_ROWS = 50000;

    private const MAX_PRODUCTS = 10000;

    private const MAX_ROWS_PER_PRODUCT = 2500;

    private const MAX_VARIANTS_PER_PRODUCT = 2048;

    private const MAX_ERROR_DETAILS = 100;

    private const MAX_IMAGES_PER_PRODUCT = 250;

    private const MAX_BODY_BYTES = 60000;

    private const MAX_OPTION_LENGTH = 255;

    private const MAX_HEADERS = 500;

    private const MAX_HEADER_LENGTH = 512;

    private const MAX_SKU_LENGTH = 255;

    // cart_products.product_sku is the runtime stock/cart identity and is
    // limited to 32 characters by the LONGOS core schema.
    private const MAX_INTERNAL_SKU_LENGTH = 32;

    private const IMAGE_DOWNLOAD_ATTEMPTS = 2;

    private ?bool $handleTableAvailable = null;

    private ?bool $variantSkuTableAvailable = null;

    private ?RemoteImageImporter $remoteImages;

    /**
     * Shopify's current product CSV header set. Imports also accept the
     * pre-2025 legacy names through HEADER_ALIASES below, while templates and
     * exports deliberately use the current names so a generated file can be
     * uploaded to a new Shopify store without manual column renaming.
     */
    private const HEADERS = [
        'Title', 'URL handle', 'Description', 'Vendor', 'Product category', 'Type', 'Tags',
        'Published on online store', 'Status', 'SKU', 'Barcodes',
        'Option1 name', 'Option1 value', 'Option1 LinkedTo',
        'Option2 name', 'Option2 value', 'Option2 LinkedTo',
        'Option3 name', 'Option3 value', 'Option3 LinkedTo',
        'Price', 'Compare-at price', 'Cost per item', 'Charge tax',
        'Inventory tracker', 'Inventory quantity',
        'Continue selling when out of stock', 'Weight value (grams)', 'Weight unit for display',
        'Packed product length', 'Packed product width', 'Packed product height',
        'Packed product dimension unit',
        'Requires shipping', 'Fulfillment service', 'Product image URL', 'Image position',
        'Image alt text', 'Variant image URL', 'Gift card', 'SEO title', 'SEO description',
        'Google Shopping / Google Product Category', 'Google Shopping / Gender',
        'Google Shopping / Age Group', 'Google Shopping / MPN',
        'Google Shopping / Condition', 'Google Shopping / Custom Product',
        'Google Shopping / Custom Label 0', 'Google Shopping / Custom Label 1',
        'Google Shopping / Custom Label 2', 'Google Shopping / Custom Label 3',
        'Google Shopping / Custom Label 4',
    ];

    /** @var array<string, string> */
    private const HEADER_ALIASES = [
        // Current Shopify names -> the stable internal/legacy field names.
        'url handle'                                       => 'handle',
        'description'                                      => 'body (html)',
        'published on online store'                        => 'published',
        'sku'                                              => 'variant sku',
        'barcodes'                                         => 'variant barcode',
        'variant barcodes'                                 => 'variant barcode',
        'barcode'                                          => 'variant barcode',
        'option1 linked to'                                => 'option1 linkedto',
        'option2 linked to'                                => 'option2 linkedto',
        'option3 linked to'                                => 'option3 linkedto',
        'price'                                            => 'variant price',
        'compare-at price'                                 => 'variant compare at price',
        'charge tax'                                       => 'variant taxable',
        'tax code'                                         => 'variant tax code',
        'inventory tracker'                                => 'variant inventory tracker',
        'inventory quantity'                               => 'variant inventory qty',
        'continue selling when out of stock'               => 'variant inventory policy',
        'weight value (grams)'                             => 'variant grams',
        'weight unit for display'                          => 'variant weight unit',
        'requires shipping'                                => 'variant requires shipping',
        'fulfillment service'                              => 'variant fulfillment service',
        'product image url'                                => 'image src',
        'image position'                                   => 'image position',
        'image alt text'                                   => 'image alt text',
        'variant image url'                                => 'variant image',
        'google shopping / manufacturer part number (mpn)' => 'google shopping / mpn',
        // A legacy Shopify spelling found in older exports.
        'variant compare-at price'           => 'variant compare at price',
    ];

    public function __construct(?RemoteImageImporter $remoteImages = null)
    {
        $this->remoteImages = $remoteImages;
    }

    public function import(UploadedFile|string $file, bool $replaceExisting, ?callable $progress = null): array
    {
        $result    = [
            'created'  => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0,
            'warnings' => 0, 'adjusted_skus' => 0, 'errors' => [], 'warning_details' => [],
        ];
        $path      = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $stream    = is_string($path) && is_file($path) ? fopen($path, 'rb') : false;
        if (! is_resource($stream)) {
            throw new \RuntimeException('Unable to read the CSV file.');
        }

        try {
            $delimiter = $this->detectDelimiter($stream);
            $headers   = fgetcsv($stream, 0, $delimiter, '"', '');

            if (! $headers) {
                throw new \RuntimeException('Unable to read the CSV file.');
            }

            if (count($headers) > self::MAX_HEADERS) {
                throw new \InvalidArgumentException('The CSV contains too many columns. The maximum is 500.');
            }

            $normalizedHeaders = [];
            foreach ($headers as $header) {
                $header = $this->normalizeCsvValue($header);
                if ($header === '') {
                    throw new \InvalidArgumentException('The CSV contains an empty column header.');
                }
                if (mb_strlen($header) > self::MAX_HEADER_LENGTH) {
                    throw new \InvalidArgumentException('A CSV column header exceeds 512 characters.');
                }

                $normalizedHeaders[] = $this->headerKey($header);
            }
            $headers = $normalizedHeaders;
            if (count(array_unique($headers)) !== count($headers)) {
                throw new \InvalidArgumentException('The CSV contains duplicate column headers.');
            }
            if (! in_array($this->headerKey('Handle'), $headers, true) && ! in_array($this->headerKey('Title'), $headers, true)) {
                throw new \InvalidArgumentException('The CSV must contain a Handle or Title column.');
            }
            $groups         = [];
            $sourceHandles  = [];
            $sourceSkus     = [];
            $line           = 1;
            $lastHandle     = '';
            while (true) {
                $offset = ftell($stream);
                if ($offset === false) {
                    throw new \RuntimeException('Unable to index the CSV file.');
                }
                $values = fgetcsv($stream, 0, $delimiter, '"', '');
                if ($values === false) {
                    break;
                }
                $line++;
                if ($line > self::MAX_ROWS + 1) {
                    throw new \InvalidArgumentException('The CSV exceeds the 50,000-row import limit.');
                }
                $values = array_map(fn ($value) => $this->normalizeCsvValue($value), $values);
                if (! array_filter($values, fn ($value) => $value !== '')) {
                    continue;
                }
                if (count($values) > count($headers)
                    && array_filter(array_slice($values, count($headers)), fn ($value) => $value !== '')) {
                    $result['failed']++;
                    $this->addError($result, "Line {$line}: the row contains more values than the header.");

                    continue;
                }
                $values       = array_pad($values, count($headers), '');
                $row          = array_combine($headers, array_slice($values, 0, count($headers)));
                $sourceHandle = $this->value($row, 'Handle');
                $title        = $this->value($row, 'Title');
                $handle       = $sourceHandle === '' && $title === '' ? $lastHandle : $this->canonicalHandle($sourceHandle, $title);
                if ($handle === '') {
                    $result['failed']++;
                    $this->addError($result, "Line {$line}: a Handle or Title is required.");

                    continue;
                }
                if ($sourceHandle !== '') {
                    $sourceIdentity = mb_strtolower(trim($sourceHandle));
                    if (isset($sourceHandles[$handle]) && $sourceHandles[$handle] !== $sourceIdentity) {
                        $result['failed']++;
                        $this->addError($result, "Line {$line}: different Shopify Handles normalize to the same value '{$handle}'.");

                        continue;
                    }
                    $sourceHandles[$handle] = $sourceIdentity;
                }
                $variantSku = mb_strtolower($this->value($row, 'Variant SKU'));
                if ($variantSku !== '') {
                    $sourceSkus[$variantSku] = ($sourceSkus[$variantSku] ?? 0) + 1;
                }
                $lastHandle                    = $handle;
                $groups[$handle]['offsets'][]  = $offset;
                $groups[$handle]['lines'][]    = $line;
                $rowsForProduct                = count($groups[$handle]['offsets']);
                if ($rowsForProduct > self::MAX_ROWS_PER_PRODUCT) {
                    throw new \InvalidArgumentException("Product '{$handle}' exceeds the 2,500-row safety limit.");
                }
                if (count($groups) > self::MAX_PRODUCTS) {
                    throw new \InvalidArgumentException('The CSV exceeds the 10,000-product import limit.');
                }
            }

            if ($groups === [] && $result['failed'] === 0) {
                throw new \InvalidArgumentException('The CSV does not contain any product rows.');
            }

            // Shopify permits merchants to reuse one SKU across products and
            // variants, while LONGOS uses SKU as a global cart/stock key. Mark
            // repeated source values so each affected variant receives a
            // deterministic internal SKU instead of rejecting a valid export.
            $duplicateSourceSkus = array_fill_keys(array_keys(array_filter(
                $sourceSkus,
                static fn (int $count): bool => $count > 1,
            )), true);

            $processed = 0;
            $total     = count($groups);
            $this->notifyProgress($progress, $processed, $total, $result);

            try {
                foreach ($groups as $handle => $group) {
                    try {
                        // Keep only row offsets in memory. Re-reading one product
                        // at a time prevents a valid 50,000-row Shopify export from
                        // exhausting a typical 128 MB PHP-FPM worker.
                        $rows = $this->rowsAtOffsets($stream, $group['offsets'], $headers, $delimiter);
                        // Remote downloads must not run inside a database transaction.
                        // A slow Shopify CDN previously kept tenant transactions open
                        // for minutes and could end in lock/connection failures.
                        $imported = $this->importProduct($handle, $rows, $replaceExisting, $duplicateSourceSkus);
                        unset($rows);
                        $result[$imported['state']]++;
                        $result['warnings']      += $imported['warnings'];
                        $result['adjusted_skus'] += $imported['adjusted_skus'];
                        if ($imported['warnings'] > 0) {
                            $this->addWarning($result, trans('ShopifyProductTransfer::transfer.image_warning', [
                                'handle' => $handle,
                                'count'  => $imported['warnings'],
                            ]));
                        }
                        $this->deleteOwnedImages($imported['stale_paths']);
                    } catch (\Throwable $e) {
                        $result['failed']++;
                        if (! $e instanceof \InvalidArgumentException) {
                            report($e);
                        }
                        $detail = $e instanceof \InvalidArgumentException
                            ? $e->getMessage()
                            : trans('ShopifyProductTransfer::transfer.import_item_failed');
                        $this->addError($result, 'Handle ' . $handle . ' (line ' . $this->lineSummary($group['lines']) . '): ' . $detail);
                    } finally {
                        $processed++;
                        $this->notifyProgress($progress, $processed, $total, $result);
                    }
                }
            } finally {
                fclose($stream);
            }

            return $result;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function notifyProgress(?callable $progress, int $processed, int $total, array $result): void
    {
        if ($progress === null) {
            return;
        }

        try {
            $progress($processed, $total, $result);
        } catch (\Throwable $exception) {
            // Progress persistence must never roll back an already imported
            // product or abort the remainder of a valid CSV.
            report($exception);
        }
    }

    /**
     * @param resource           $stream
     * @param array<int, int>    $offsets
     * @param array<int, string> $headers
     * @return array<int, array<string, string>>
     */
    private function rowsAtOffsets($stream, array $offsets, array $headers, string $delimiter): array
    {
        $rows = [];
        foreach ($offsets as $offset) {
            if (fseek($stream, $offset) !== 0) {
                throw new \RuntimeException('Unable to seek within the CSV file.');
            }
            $values = fgetcsv($stream, 0, $delimiter, '"', '');
            if ($values === false) {
                throw new \RuntimeException('Unable to re-read an indexed CSV row.');
            }
            $values = array_map(fn ($value) => $this->normalizeCsvValue($value), $values);
            $values = array_pad($values, count($headers), '');
            $row    = array_combine($headers, array_slice($values, 0, count($headers)));
            if (! is_array($row)) {
                throw new \RuntimeException('Unable to map a CSV row to its headers.');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param array<int, int> $lines */
    private function lineSummary(array $lines): string
    {
        $visible = array_slice($lines, 0, 10);
        $summary = implode(', ', $visible);

        return count($lines) > count($visible)
            ? $summary . ', … (' . count($lines) . ' rows)'
            : $summary;
    }

    public function export(array $ids = []): StreamedResponse
    {
        $ids = array_slice(array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0
        ))), 0, 2000);
        $filename = 'shopify-products-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($ids) {
            $out = fopen('php://output', 'wb');
            if (! is_resource($out)) {
                throw new \RuntimeException('Unable to open the CSV export stream.');
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::HEADERS, ',', '"', '');

            $query = Product::query()->with([
                'descriptions',
                'skus',
                'brand',
                'categories.descriptions',
                'categories.paths.pathCategory.descriptions',
            ])->orderBy('id');
            if ($ids) {
                $query->whereIn('id', $ids);
            }
            $query->chunkById(100, function ($products) use ($out) {
                $handles = $this->hasHandleTable()
                    ? DB::table('shopify_product_handles')->whereIn('product_id', $products->pluck('id'))->pluck('handle', 'product_id')->all()
                    : [];
                $sourceSkus = $this->hasVariantSkuTable()
                    ? DB::table('shopify_product_variant_skus')
                        ->whereIn('product_id', $products->pluck('id'))
                        ->get(['product_id', 'internal_sku', 'source_sku'])
                        ->groupBy('product_id')
                        ->map(static fn ($mappings): array => $mappings->pluck('source_sku', 'internal_sku')->all())
                        ->all()
                    : [];
                foreach ($products as $product) {
                    $this->writeProduct(
                        $out,
                        $product,
                        $handles[$product->id]    ?? null,
                        $sourceSkus[$product->id] ?? [],
                    );
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'wb');
            if (! is_resource($out)) {
                throw new \RuntimeException('Unable to open the CSV template stream.');
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::HEADERS, ',', '"', '');
            fclose($out);
        }, 'shopify-products-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{state: string, warnings: int, stale_paths: array<int, string>, downloaded_paths: array<int, string>}
     */
    private function importProduct(string $handle, array $rows, bool $replaceExisting, array $duplicateSourceSkus = []): array
    {
        $title = $this->firstValue($rows, 'Title');
        if ($title === '') {
            throw new \InvalidArgumentException('Title is required.');
        }

        $handle   = $this->canonicalHandle($handle, $title);
        $existing = $this->findExistingProduct($handle, $rows, false);
        if ($existing && ! $replaceExisting) {
            // A legacy LONGOS product can be found through its unique SKU
            // before it has a Shopify Handle mapping. Persist the Handle now
            // so a later Shopify product that legitimately reuses this SKU
            // cannot be mistaken for the same product.
            DB::transaction(fn () => $this->rememberHandle((int) $existing->id, $handle), 3);

            return ['state' => 'skipped', 'warnings' => 0, 'adjusted_skus' => 0, 'stale_paths' => [], 'downloaded_paths' => []];
        }

        $sourceBody    = $this->firstValue($rows, 'Body (HTML)');
        if (strlen($sourceBody) > self::MAX_BODY_BYTES) {
            throw new \InvalidArgumentException('Body (HTML) exceeds the 60 KB storage limit.');
        }
        $imageSources  = $this->remoteImageSources($rows, $sourceBody);
        $localized     = $this->localizeRemoteImages($imageSources);
        $downloaded    = array_values($localized);
        $missingImages = max(0, count($imageSources) - count($downloaded));

        try {
            $locale      = system_setting('base.locale', 'en');
            $weightClass = system_setting('base.weight', 'kg');
            $variables   = $this->buildVariables($rows, $locale);
            $images      = $this->images($rows, $localized);
            $skuBuild    = $this->buildSkus(
                $rows,
                $variables,
                $handle,
                $weightClass,
                $locale,
                $localized,
                $duplicateSourceSkus,
                $existing?->id,
                true,
            );
            $skus        = $skuBuild['skus'];
            $skuMappings = $skuBuild['source_mappings'];
            if (! $skus) {
                throw new \InvalidArgumentException('At least one valid variant row is required.');
            }
            $body = $this->localizeHtmlImages($this->cleanHtml($sourceBody), $localized);
            if (strlen($body) > self::MAX_BODY_BYTES) {
                throw new \InvalidArgumentException('The sanitized product description exceeds the 60 KB storage limit.');
            }

            $outcome = DB::transaction(function () use ($handle, $rows, $replaceExisting, $title, $downloaded, $missingImages, $locale, $weightClass, $variables, $images, $skus, $skuMappings, $skuBuild, $body): array {
                // Re-check while locked because another request may have
                // imported the same Handle while images were downloading.
                $current = $this->findExistingProduct($handle, $rows, true);
                if ($current && ! $replaceExisting) {
                    return [
                        'state'            => 'skipped',
                        'warnings'         => 0,
                        'adjusted_skus'    => 0,
                        'stale_paths'      => $downloaded,
                        'downloaded_paths' => [],
                    ];
                }
                if ($current && $replaceExisting && $missingImages > 0) {
                    // A transient Shopify/CDN failure must never turn a
                    // replacement import into destructive partial data. The
                    // surrounding catch removes any newly downloaded files,
                    // while this exception rolls the database transaction
                    // back and leaves the existing gallery and description
                    // untouched.
                    throw new \InvalidArgumentException(trans(
                        'ShopifyProductTransfer::transfer.replace_image_download_failed',
                        ['count' => $missingImages],
                    ));
                }

                $previousPaths = $current ? $this->ownedProductImages($current) : [];
                $categoryIds   = $this->categoryIds(
                    $this->firstValue($rows, 'Type') ?: $this->firstValue($rows, 'Product Category'),
                    $locale
                );
                $data          = [
                    'brand_id'     => $this->brandId($this->firstValue($rows, 'Vendor')),
                    'position'     => 0,
                    'weight'       => $skus[0]['weight'],
                    'weight_class' => $weightClass,
                    'active'       => $this->isActive($rows),
                    'shipping'     => $this->boolValue($this->firstValue($rows, 'Variant Requires Shipping'), true),
                    'variables'    => json_encode($variables, JSON_UNESCAPED_UNICODE),
                    'images'       => $images,
                    'descriptions' => [
                        $locale => [
                            'name'             => Str::limit($title, 255, ''),
                            'content'          => $body,
                            'meta_title'       => Str::limit($this->firstValue($rows, 'SEO Title') ?: $title, 255, ''),
                            'meta_description' => Str::limit($this->firstValue($rows, 'SEO Description'), 320, ''),
                            'meta_keywords'    => '',
                        ],
                    ],
                    'attributes' => [],
                    'skus'       => $skus,
                    'categories' => $categoryIds,
                    'relations'  => [],
                ];

                $service = app(ProductService::class);
                $product = $current ? $service->update($current, $data) : $service->create($data);
                if ($current) {
                    // ProductService intentionally leaves gallery management
                    // to its caller during updates. A replacement import must
                    // explicitly persist the new localized gallery before old
                    // plugin-owned files are removed.
                    $product->images = array_values($images);
                    $product->save();
                }
                $this->rememberHandle($product->id, $handle);
                $this->rememberVariantSkus((int) $product->id, $skuMappings);

                return [
                    'state'            => $current ? 'updated' : 'created',
                    'warnings'         => $missingImages,
                    'adjusted_skus'    => $skuBuild['adjusted'],
                    'stale_paths'      => array_values(array_diff($previousPaths, $downloaded)),
                    'downloaded_paths' => $downloaded,
                ];
            }, 3);
        } catch (\Throwable $e) {
            $this->deleteOwnedImages($downloaded);

            // CategoryService updates its cache while participating in this
            // outer transaction. Rebuild it after a rollback so failed
            // products cannot leave uncommitted category data cached.
            try {
                CategoryRepo::clearCache();
            } catch (\Throwable $cacheException) {
                report($cacheException);
            }

            throw $e;
        }

        return $outcome;
    }

    private function buildVariables(array $rows, string $locale): array
    {
        $variables = [];
        foreach ([1, 2, 3] as $number) {
            $names = collect($rows)
                ->map(fn ($row) => $this->value($row, "Option{$number} Name"))
                ->filter(fn (string $value): bool => $value !== '');
            if ($names->map(fn (string $value): string => mb_strtolower($value))->unique()->count() > 1) {
                throw new \InvalidArgumentException("Option{$number} Name is inconsistent across this product.");
            }

            $name   = (string) ($names->first() ?? '');
            $values = collect($rows)->map(fn ($row) => $this->value($row, "Option{$number} Value"))
                ->filter(fn ($value) => $value !== '' && strtolower($value) !== 'default title')->unique()->values();
            if ($name === '' && $values->isNotEmpty()) {
                throw new \InvalidArgumentException("Option{$number} Name is required when option values are present.");
            }
            if ($name === '' || $values->isEmpty()) {
                continue;
            }
            if (mb_strlen($name) > self::MAX_OPTION_LENGTH
                || $values->contains(fn (string $value): bool => mb_strlen($value) > self::MAX_OPTION_LENGTH)
            ) {
                throw new \InvalidArgumentException('Option names and values cannot exceed 255 characters.');
            }
            $variables[] = [
                'source' => $number,
                'name'   => $this->localized($name, $locale),
                'values' => $values->map(fn ($value) => ['name' => $this->localized($value, $locale), 'image' => ''])->all(),
            ];
        }

        return $variables;
    }

    private function buildSkus(
        array $rows,
        array $variables,
        string $handle,
        string $weightClass,
        string $locale,
        array $localizedImages = [],
        array $duplicateSourceSkus = [],
        ?int $existingProductId = null,
        bool $checkDatabase = false,
    ): array {
        $skus             = [];
        $sourceMappings   = [];
        $adjusted         = 0;
        $seenCombinations = [];
        $seenInternalSkus = [];
        $variantRows      = array_values(array_filter($rows, fn (array $row): bool => ! $this->isImageOnlyRow($row)));
        $sourceSkuCounts  = [];
        foreach ($variantRows as $row) {
            $sourceSku = $this->value($row, 'Variant SKU');
            if ($sourceSku !== '') {
                $key                   = mb_strtolower($sourceSku);
                $sourceSkuCounts[$key] = ($sourceSkuCounts[$key] ?? 0) + 1;
            }
        }

        $sourceSkuOwners = $checkDatabase ? $this->skuOwners(array_keys($sourceSkuCounts)) : [];
        $existingRows    = $checkDatabase && $existingProductId
            ? ProductSku::query()
                ->where('product_id', $existingProductId)
                ->get(['sku', 'variants', 'quantity'])
            : collect();
        $existingSkus = $existingRows->mapWithKeys(
            static fn (ProductSku $sku): array => [mb_strtolower((string) $sku->sku) => true]
        )->all();
        $existingQuantitiesBySku = $existingRows->filter(
            static fn (ProductSku $sku): bool => trim((string) $sku->sku) !== ''
        )->mapWithKeys(
            static fn (ProductSku $sku): array => [mb_strtolower((string) $sku->sku) => max(0, (int) $sku->quantity)]
        )->all();
        $existingQuantitiesByCombination = $existingRows->mapWithKeys(static function (ProductSku $sku): array {
            $variants = is_array($sku->variants) ? $sku->variants : [];

            return [implode('-', array_map('intval', $variants)) => max(0, (int) $sku->quantity)];
        })->all();

        foreach ($rows as $index => $row) {
            if ($this->isImageOnlyRow($row)) {
                continue;
            }
            $variants = [];
            foreach ($variables as $variable) {
                $value    = $this->value($row, 'Option' . $variable['source'] . ' Value');
                $position = collect($variable['values'])->search(fn ($item) => ($item['name'][$locale] ?? '') === $value);
                if ($position === false) {
                    throw new \InvalidArgumentException('An option value is missing for a variant.');
                }
                $variants[] = $position;
            }
            $combination = implode('-', $variants);
            if (isset($seenCombinations[$combination])) {
                throw new \InvalidArgumentException('Duplicate option combination in one product.');
            }
            $seenCombinations[$combination] = true;

            if (count($skus) >= self::MAX_VARIANTS_PER_PRODUCT) {
                throw new \InvalidArgumentException('A product cannot import more than 2,048 variants.');
            }

            $sourceSku = $this->value($row, 'Variant SKU');
            if (mb_strlen($sourceSku) > self::MAX_SKU_LENGTH) {
                throw new \InvalidArgumentException('Variant SKU cannot exceed 255 characters.');
            }

            $variantIdentity = collect([1, 2, 3])
                ->map(fn (int $number): string => $this->value($row, "Option{$number} Value"))
                ->implode('|') ?: 'row-' . $index;
            $sku = $sourceSku !== ''
                ? $sourceSku
                : $this->derivedSku($handle . '-' . ($index + 1), $handle, $variantIdentity);

            if ($sourceSku !== '') {
                $sourceKey       = mb_strtolower($sourceSku);
                $derivedSku      = $this->derivedSku($sourceSku, $handle, $variantIdentity);
                $owners          = $sourceSkuOwners[$sourceKey] ?? [];
                $ownedByOther    = collect($owners)->contains(
                    static fn (int $productId): bool => $existingProductId === null || $productId !== $existingProductId
                );
                $mustAdjust      = isset($duplicateSourceSkus[$sourceKey])
                    || ($sourceSkuCounts[$sourceKey] ?? 0) > 1
                    || $ownedByOther
                    || mb_strlen($sourceSku) > self::MAX_INTERNAL_SKU_LENGTH
                    || isset($existingSkus[mb_strtolower($derivedSku)]);
                if ($mustAdjust) {
                    $sku = $derivedSku;
                    $adjusted++;
                }
                $sourceMappings[] = ['internal_sku' => $sku, 'source_sku' => $sourceSku];
            }

            $skuKey = mb_strtolower($sku);
            if (isset($seenInternalSkus[$skuKey])) {
                throw new \InvalidArgumentException('Unable to generate a unique internal SKU for every variant.');
            }
            $seenInternalSkus[$skuKey] = true;
            $weightValue               = $this->value($row, 'Variant Grams');
            $weightUnit                = 'g';
            if ($weightValue === '') {
                $weightValue = $this->value($row, 'Variant Weight');
                $weightUnit  = strtolower($this->value($row, 'Variant Weight Unit') ?: 'g');
            }
            $weightUnit   = in_array($weightUnit, ['g', 'kg', 'lb', 'oz'], true) ? $weightUnit : 'g';
            $weightNumber = $this->nonNegativeNumber($weightValue, 'Variant weight');
            $weight       = $weightNumber > 0 ? Weight::getInstance()->convert($weightNumber, $weightUnit, $weightClass) : 0;
            $price        = $this->nonNegativeNumber($this->value($row, 'Variant Price'), 'Variant Price');
            $compareAt    = $this->nonNegativeNumber($this->value($row, 'Variant Compare At Price'), 'Variant Compare At Price');
            $rawQuantity  = $this->value($row, 'Variant Inventory Qty');
            // Shopify exports and merchant-edited replacement files can omit
            // inventory. Blank means "leave the current stock unchanged" for
            // an existing product; an explicit 0 still intentionally clears
            // stock. New products continue to default blank inventory to 0.
            $quantity = $rawQuantity === ''
                ? ($existingQuantitiesBySku[$skuKey]
                    ?? $existingQuantitiesByCombination[$combination]
                    ?? 0)
                : $this->inventoryQuantity($rawQuantity);
            $variantImage = $localizedImages[$this->sourceKey($this->value($row, 'Variant Image'))] ?? null;
            $skus[]       = [
                'variants'     => $variants,
                'sku'          => $sku,
                'model'        => '',
                'price'        => $price,
                'origin_price' => $compareAt > $price ? $compareAt : $price,
                'cost_price'   => $this->nonNegativeNumber($this->value($row, 'Cost per item'), 'Cost per item'),
                'weight'       => $weight,
                'quantity'     => $quantity,
                'is_default'   => empty($skus),
                'active'       => true,
                'images'       => array_values(array_filter([$variantImage])),
            ];
        }

        return [
            'skus'            => $skus,
            'source_mappings' => $sourceMappings,
            'adjusted'        => $adjusted,
        ];
    }

    /** @return array<string, array<int, int>> */
    private function skuOwners(array $normalizedSkus): array
    {
        $owners = [];
        foreach (array_chunk(array_values(array_unique(array_filter($normalizedSkus))), 400) as $chunk) {
            foreach (ProductSku::query()
                ->whereIn(DB::raw('LOWER(sku)'), $chunk)
                ->get(['product_id', 'sku']) as $productSku) {
                $key            = mb_strtolower((string) $productSku->sku);
                $owners[$key][] = (int) $productSku->product_id;
            }
        }

        foreach ($owners as $key => $productIds) {
            $owners[$key] = array_values(array_unique($productIds));
        }

        return $owners;
    }

    private function derivedSku(string $sourceSku, string $handle, string $variantIdentity): string
    {
        $suffix = '~' . substr(hash('sha256', mb_strtolower($handle) . "\n" . $sourceSku . "\n" . $variantIdentity), 0, 16);

        return Str::limit($sourceSku, self::MAX_INTERNAL_SKU_LENGTH - strlen($suffix), '') . $suffix;
    }

    private function findExistingProduct(string $handle, array $rows, bool $lock): ?Product
    {
        if ($this->hasHandleTable()) {
            $mappingQuery = DB::table('shopify_product_handles')->where('handle', $handle);
            $mapping      = ($lock ? $mappingQuery->lockForUpdate() : $mappingQuery)->first();
            $id           = $mapping?->product_id;
            if ($id) {
                $productQuery = Product::query()->whereKey($id);
                $product      = ($lock ? $productQuery->lockForUpdate() : $productQuery)->first();
                if ($product) {
                    // Shopify Handle is the authoritative product identity.
                    // A reused source SKU must never redirect an update to a
                    // different product once this stable mapping exists.
                    return $product;
                }

                DB::table('shopify_product_handles')->where('id', $mapping->id)->delete();
            }
        }
        $skuValues = collect($rows)
            ->map(fn ($row): string => mb_strtolower($this->value($row, 'Variant SKU')))
            ->filter()
            ->unique()
            ->values();
        if ($skuValues->isEmpty()) {
            return null;
        }

        $skuQuery = ProductSku::query()->whereIn(DB::raw('LOWER(sku)'), $skuValues);
        $ids      = ($lock ? $skuQuery->lockForUpdate() : $skuQuery)
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();
        if ($ids->count() !== 1) {
            // Ambiguous/missing SKU matches are not safe identities. Creating
            // a separate Handle-owned product is preferable to overwriting an
            // unrelated item that happens to reuse a merchant SKU.
            return null;
        }

        $id = (int) $ids->first();
        if ($this->hasHandleTable()) {
            $candidateMapping = DB::table('shopify_product_handles')->where('product_id', $id)->first();
            if ($candidateMapping && (string) $candidateMapping->handle !== $handle) {
                return null;
            }
        }

        $productQuery = Product::query()->whereKey($id);

        return ($lock ? $productQuery->lockForUpdate() : $productQuery)->first();
    }

    private function rememberHandle(int $productId, string $handle): void
    {
        if (! $this->hasHandleTable()) {
            return;
        }
        $owner = DB::table('shopify_product_handles')->where('handle', $handle)->lockForUpdate()->value('product_id');
        if ($owner && ! Product::query()->whereKey($owner)->exists()) {
            DB::table('shopify_product_handles')->where('handle', $handle)->delete();
            $owner = null;
        }
        if ($owner && (int) $owner !== $productId) {
            throw new \InvalidArgumentException('This Shopify Handle already belongs to another product.');
        }
        $mapping = DB::table('shopify_product_handles')->where('product_id', $productId)->lockForUpdate()->first();
        if ($mapping) {
            DB::table('shopify_product_handles')->where('id', $mapping->id)->update(['handle' => $handle, 'updated_at' => now()]);
        } else {
            DB::table('shopify_product_handles')->insert([
                'product_id' => $productId, 'handle' => $handle, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** @param array<int, array{internal_sku: string, source_sku: string}> $mappings */
    private function rememberVariantSkus(int $productId, array $mappings): void
    {
        if (! $this->hasVariantSkuTable()) {
            return;
        }

        DB::table('shopify_product_variant_skus')->where('product_id', $productId)->delete();
        $now  = now();
        $rows = collect($mappings)
            ->filter(static fn (array $mapping): bool => $mapping['source_sku'] !== '')
            ->unique('internal_sku')
            ->map(static fn (array $mapping): array => [
                'product_id'   => $productId,
                'internal_sku' => $mapping['internal_sku'],
                'source_sku'   => $mapping['source_sku'],
                'created_at'   => $now,
                'updated_at'   => $now,
            ])
            ->values()
            ->all();
        if ($rows !== []) {
            DB::table('shopify_product_variant_skus')->insert($rows);
        }
    }

    private function writeProduct($out, Product $product, ?string $savedHandle, array $sourceSkus = []): void
    {
        $locale              = system_setting('base.locale', 'en');
        $description         = $product->descriptions->firstWhere('locale', $locale) ?: $product->descriptions->first();
        $categoryModel       = $product->categories->first();
        $category            = $categoryModel?->paths
            ?->sortBy('level')
            ->map(function ($path) use ($locale): string {
                $descriptions = $path->pathCategory?->descriptions;
                $description  = $descriptions?->firstWhere('locale', $locale) ?: $descriptions?->first();

                return trim((string) ($description?->name ?? ''));
            })
            ->filter()
            ->implode(' > ') ?: null;
        if ($category === null && $categoryModel) {
            $fallback = $categoryModel->descriptions->firstWhere('locale', $locale)
                ?: $categoryModel->descriptions->first();
            $category = trim((string) ($fallback?->name ?? '')) ?: null;
        }
        $handle              = $savedHandle ?: $this->canonicalHandle('', ($description?->name ?? 'product') . '-' . $product->id);
        $variables           = $this->normalizeExportVariables($product->variables);
        $images              = $this->normalizeImageList($product->images);
        $skus                = $product->skus->sortBy('position');
        if ($skus->isEmpty()) {
            $skus = collect([(object) ['sku' => '', 'price' => 0, 'origin_price' => 0, 'weight' => 0, 'quantity' => 0, 'variants' => [], 'images' => []]]);
        }

        foreach ($skus->values() as $skuIndex => $sku) {
            $row           = [];
            $row['Handle'] = $handle;
            if ($skuIndex === 0) {
                $row['Title']            = $description?->name    ?? '';
                $row['Body (HTML)']      = $this->exportHtmlImages($description?->content ?? '');
                $row['Vendor']           = $product->brand?->name ?? '';
                // LONGOS categories are merchant-defined. Shopify's Product
                // category accepts only its own taxonomy, so export the value
                // as Type instead of producing a CSV Shopify can reject.
                $row['Product Category'] = '';
                $row['Type']             = Str::limit($category ?? '', 255, '');
                $row['Published']        = $product->active ? 'TRUE' : 'FALSE';
                $row['SEO Title']        = Str::limit($description?->meta_title ?? '', 70, '');
                $row['SEO Description']  = Str::limit($description?->meta_description ?? '', 320, '');
                $row['Image Src']        = isset($images[0]) ? image_origin($images[0]) : '';
                $row['Image Position']   = isset($images[0]) ? 1 : '';
            }
            foreach (array_slice($variables, 0, 3) as $variableIndex => $variable) {
                if (! is_array($variable)) {
                    continue;
                }
                $slot                       = $variableIndex + 1;
                $names                      = is_array($variable['name'] ?? null) ? $variable['name'] : [];
                $row["Option{$slot} Name"]  = $this->localizedExportValue($names, $locale);
                $variantIndexes             = is_array($sku->variants ?? null) ? $sku->variants : [];
                $variantIndex               = $variantIndexes[$variableIndex] ?? null;
                $values                     = is_array($variable['values'] ?? null) ? $variable['values'] : [];
                $value                      = $variantIndex !== null && is_array($values[$variantIndex] ?? null) ? $values[$variantIndex] : [];
                $row["Option{$slot} Value"] = $this->localizedExportValue($value['name'] ?? [], $locale);
            }
            $baseWeight                         = $product->weight_class ?: system_setting('base.weight', 'kg');
            $row['Variant SKU']                 = $sourceSkus[$sku->sku ?? ''] ?? ($sku->sku ?? '');
            $row['Variant Grams']               = round(Weight::getInstance()->convert((float) ($sku->weight ?? 0), $baseWeight, 'g'), 3);
            $row['Variant Inventory Tracker']   = 'shopify';
            $row['Variant Inventory Qty']       = $sku->quantity ?? 0;
            $row['Variant Inventory Policy']    = 'deny';
            $row['Variant Fulfillment Service'] = 'manual';
            $row['Variant Price']               = $sku->price        ?? 0;
            $row['Variant Compare At Price']    = (float) ($sku->origin_price ?? 0) > (float) ($sku->price ?? 0)
                ? $sku->origin_price
                : '';
            $row['Variant Requires Shipping']   = $product->shipping ? 'TRUE' : 'FALSE';
            $row['Variant Taxable']             = 'TRUE';
            $skuImages                          = $this->normalizeImageList($sku->images ?? []);
            $row['Variant Image']               = isset($skuImages[0]) ? image_origin($skuImages[0]) : '';
            $row['Variant Weight Unit']         = 'g';
            $row['Cost per item']               = $sku->cost_price ?? 0;
            $row['Status']                      = $product->active ? 'active' : 'draft';
            $this->writeCsvRow($out, $this->exportRowValues($row));
        }
        foreach (array_slice($images, 1) as $position => $image) {
            $row                   = [];
            $row['Handle']         = $handle;
            $row['Image Src']      = image_origin($image);
            $row['Image Position'] = $position + 2;
            $this->writeCsvRow($out, $this->exportRowValues($row));
        }
    }

    private function categoryIds(string $name, string $locale): array
    {
        $parts = array_values(array_filter(array_map('trim', explode('>', $name)), static fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return [];
        }
        if (count($parts) > 8) {
            throw new \InvalidArgumentException('Product category hierarchy cannot exceed eight levels.');
        }

        $parentId = 0;
        foreach ($parts as $part) {
            $safeName    = Str::limit($part, 255, '');
            $description = CategoryDescription::query()
                ->where('locale', $locale)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($safeName)])
                ->whereHas('category', fn ($query) => $query->where('parent_id', $parentId))
                ->first();
            if ($description) {
                $category = $description->category;
                if ($category && ! $category->paths()->exists()) {
                    // Repair categories created by plugin versions before 1.4,
                    // which wrote descriptions without the required path row.
                    app(CategoryService::class)->createPath($category);
                    CategoryRepo::clearCache();
                }
                $parentId = (int) $description->category_id;

                continue;
            }

            $category = app(CategoryService::class)->createOrUpdate([
                'parent_id'    => $parentId,
                'position'     => 0,
                'image'        => '',
                'active'       => true,
                'descriptions' => [
                    $locale => [
                        'name'             => $safeName,
                        'content'          => '',
                        'meta_title'       => '',
                        'meta_description' => '',
                        'meta_keywords'    => '',
                    ],
                ],
            ], null);
            $parentId = (int) $category->id;
        }

        return $parentId > 0 ? [$parentId] : [];
    }

    private function brandId(string $name): int
    {
        if ($name === '') {
            return 0;
        }

        $safeName = Str::limit($name, 255, '');
        $brand    = Brand::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($safeName)])->first();

        return ($brand ?: Brand::query()->create([
            'name'       => $safeName,
            'first'      => Str::upper(Str::substr($safeName, 0, 1)),
            'logo'       => '',
            'sort_order' => 0,
            'active'     => true,
        ]))->id;
    }

    private function images(array $rows, array $localizedImages): array
    {
        $images = [];
        foreach ($rows as $index => $row) {
            $path = $localizedImages[$this->sourceKey($this->value($row, 'Image Src'))] ?? null;
            if (! is_string($path) || $path === '') {
                continue;
            }

            $rawPosition = $this->value($row, 'Image Position');
            if ($rawPosition !== '' && (! ctype_digit($rawPosition) || (int) $rawPosition < 1)) {
                throw new \InvalidArgumentException('Image Position must be a positive integer.');
            }
            $images[] = [
                'path'     => $path,
                'position' => $rawPosition === '' ? PHP_INT_MAX : (int) $rawPosition,
                'source'   => $index,
            ];
        }

        usort($images, static fn (array $left, array $right): int => [$left['position'], $left['source']] <=> [$right['position'], $right['source']]);

        return collect($images)->pluck('path')->unique()->take(self::MAX_IMAGES_PER_PRODUCT)->values()->all();
    }

    /** @return array<int, string> */
    private function remoteImageSources(array $rows, string $body): array
    {
        $sources = [];
        foreach ($rows as $row) {
            foreach (['Image Src', 'Variant Image'] as $column) {
                $source = $this->value($row, $column);
                if ($source !== '') {
                    $sources[] = $this->sourceKey($source);
                }
            }
        }

        $sources = array_merge($sources, $this->htmlImageSources($body));
        $sources = array_values(array_unique(array_filter(array_map('trim', $sources))));
        if (count($sources) > self::MAX_IMAGES_PER_PRODUCT) {
            throw new \InvalidArgumentException('A product cannot import more than 250 unique images.');
        }

        return $sources;
    }

    /** @return array<int, string> */
    private function htmlImageSources(string $html): array
    {
        preg_match_all('/<img\b[^>]*>/i', $html, $matches);

        return array_values(array_filter(array_map(
            fn (string $tag): string => $this->sourceKey($this->imageTagSource($tag)),
            $matches[0] ?? []
        )));
    }

    /** @return array<string, string> */
    private function localizeRemoteImages(array $sources): array
    {
        if ($sources === []) {
            return [];
        }

        $localized = [];
        $pending   = array_values(array_unique(array_filter(array_map(
            fn (mixed $source): string => $this->sourceKey((string) $source),
            $sources,
        ))));
        for ($attempt = 1; $attempt <= self::IMAGE_DOWNLOAD_ATTEMPTS && $pending !== []; $attempt++) {
            foreach ($this->remoteImageImporter()->importInto($pending, 'shopify-products') as $image) {
                $origin = $this->sourceKey((string) ($image['origin'] ?? ''));
                $path   = trim((string) ($image['path'] ?? ''));
                if ($origin !== '' && $path !== '') {
                    $localized[$origin] = $path;
                }
            }
            $pending = array_values(array_filter(
                $pending,
                static fn (string $source): bool => ! isset($localized[$source]),
            ));
        }

        return $localized;
    }

    private function localizeHtmlImages(string $html, array $localizedImages): string
    {
        return preg_replace_callback('/<img\b[^>]*>/i', function (array $tag) use ($localizedImages): string {
            $source = $this->sourceKey($this->imageTagSource($tag[0]));
            $path   = $localizedImages[$source] ?? null;
            if (! is_string($path) || $path === '') {
                return '';
            }

            $alt = Str::limit($this->htmlAttribute($tag[0], 'alt'), 500, '');

            return '<img src="/' . e(ltrim($path, '/')) . '" alt="' . e($alt) . '" loading="lazy">';
        }, $html) ?? '';
    }

    /** @return array<int, string> */
    private function ownedProductImages(Product $product): array
    {
        $paths = $this->normalizeImageList($product->images);
        foreach ($product->skus()->get(['images']) as $sku) {
            $paths = array_merge($paths, $this->normalizeImageList($sku->images));
        }
        foreach ($product->descriptions()->pluck('content') as $content) {
            foreach ($this->htmlImageSources((string) $content) as $source) {
                $path    = parse_url($source, PHP_URL_PATH);
                $paths[] = ltrim(is_string($path) ? $path : $source, '/');
            }
        }

        return array_values(array_unique(array_filter($paths, fn (string $path): bool => $this->isOwnedImage($path))));
    }

    private function deleteOwnedImages(array $paths): void
    {
        $diskPrefix = rtrim(tenant_upload_prefix(), '/') . '/';
        foreach (array_values(array_unique($paths)) as $path) {
            if (! is_string($path) || ! $this->isOwnedImage($path)) {
                continue;
            }

            try {
                Storage::disk('upload')->delete(substr($path, strlen($diskPrefix)));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    private function isOwnedImage(string $path): bool
    {
        return str_starts_with($path, rtrim(tenant_upload_prefix(), '/') . '/shopify-products/');
    }

    private function remoteImageImporter(): RemoteImageImporter
    {
        return $this->remoteImages ??= app(RemoteImageImporter::class);
    }

    private function localized(string $text, string $locale): array
    {
        $result = [];
        foreach (languages()->all() as $language) {
            $result[$language] = $text;
        }

        return $result ?: [$locale => $text];
    }

    private function isActive(array $rows): bool
    {
        $status = strtolower($this->firstValue($rows, 'Status'));
        if ($status !== '' && ! in_array($status, ['active', 'draft', 'archived', 'unlisted'], true)) {
            throw new \InvalidArgumentException('Status must be active, draft, archived, or unlisted.');
        }

        return $status !== '' ? $status === 'active' : $this->boolValue($this->firstValue($rows, 'Published'), true);
    }

    private function firstValue(array $rows, string $key): string
    {
        foreach ($rows as $row) {
            if (($value = $this->value($row, $key)) !== '') {
                return $value;
            }
        }

        return '';
    }

    private function isImageOnlyRow(array $row): bool
    {
        return $this->value($row, 'Title')         === ''
            && $this->value($row, 'Variant SKU')   === ''
            && $this->value($row, 'Variant Price') === ''
            && $this->value($row, 'Option1 Value') === ''
            && $this->value($row, 'Option2 Value') === ''
            && $this->value($row, 'Option3 Value') === ''
            && $this->value($row, 'Image Src') !== '';
    }

    /**
     * Shopify emits one row per variant. Canonicalizing before grouping keeps
     * case variations and non-Latin titles from being imported as separate
     * products when the source does not include a Handle column.
     */
    private function canonicalHandle(string $handle, string $title): string
    {
        $source = trim($handle) !== '' ? $handle : $title;
        if ($source === '') {
            return '';
        }

        $normalized = Str::limit(Str::slug($source), 250, '');
        if ($normalized !== '') {
            return $normalized;
        }

        return 'shopify-' . substr(hash('sha256', $source), 0, 24);
    }

    private function normalizeExportVariables(mixed $variables): array
    {
        if (is_string($variables)) {
            $decoded   = json_decode($variables, true);
            $variables = is_array($decoded) ? $decoded : [];
        }

        return is_array($variables) ? array_values($variables) : [];
    }

    private function normalizeImageList(mixed $images): array
    {
        if (is_string($images)) {
            $decoded = json_decode($images, true);
            $images  = is_array($decoded) ? $decoded : [$images];
        }
        if (! is_array($images)) {
            return [];
        }

        return array_values(array_filter($images, fn ($image) => is_string($image) && $image !== ''));
    }

    private function localizedExportValue(mixed $values, string $locale): string
    {
        if (! is_array($values)) {
            return is_scalar($values) ? (string) $values : '';
        }

        $value = $values[$locale] ?? reset($values) ?: '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function addError(array &$result, string $error): void
    {
        if (count($result['errors']) < self::MAX_ERROR_DETAILS) {
            $result['errors'][] = $error;
        }
    }

    private function addWarning(array &$result, string $warning): void
    {
        if (count($result['warning_details']) < self::MAX_ERROR_DETAILS) {
            $result['warning_details'][] = $warning;
        }
    }

    private function value(array $row, string $key): string
    {
        return trim((string) ($row[$this->headerKey($key)] ?? ''));
    }

    private function detectDelimiter($stream): string
    {
        $line = fgets($stream) ?: '';
        rewind($stream);
        $expected = array_map(fn (string $header): string => $this->headerKey($header), self::HEADERS);
        $scores   = [];
        foreach ([',', ';', "\t"] as $candidate) {
            $columns     = str_getcsv($line, $candidate, '"', '');
            $normalized  = array_map(fn ($header): string => $this->headerKey($this->normalizeCsvValue($header)), $columns);
            $known       = count(array_intersect($normalized, $expected));
            // Recognized header names outweigh raw field count, so commas in
            // a quoted semicolon-delimited description cannot win detection.
            $scores[$candidate] = ($known * 1000) + count($normalized);
        }
        arsort($scores);

        return (string) array_key_first($scores);
    }

    private function headerKey(string $key): string
    {
        $key = strtolower(trim(str_replace("\xEF\xBB\xBF", '', $key)));

        return self::HEADER_ALIASES[$key] ?? $key;
    }

    /**
     * Map the service's stable field names to Shopify's current export order.
     * Keeping this translation at the boundary lets old and new Shopify CSV
     * files share one import path without emitting duplicate legacy columns.
     *
     * @param array<string, mixed> $row
     * @return array<int, mixed>
     */
    private function exportRowValues(array $row): array
    {
        $canonical = [];
        foreach ($row as $header => $value) {
            $canonical[$this->headerKey((string) $header)] = $value;
        }

        return array_map(
            fn (string $header): mixed => $canonical[$this->headerKey($header)] ?? '',
            self::HEADERS,
        );
    }

    private function normalizeCsvValue(mixed $value): string
    {
        $value = str_replace("\0", '', (string) $value);
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        return trim($value);
    }

    private function number(string $value, string $field): float
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return 0;
        }
        if (str_contains($normalized, ',')) {
            if (! preg_match('/^[+-]?\d{1,3}(?:,\d{3})*(?:\.\d+)?$/', $normalized)) {
                throw new \InvalidArgumentException("{$field} must be numeric.");
            }
            $normalized = str_replace(',', '', $normalized);
        }
        if (! preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/', $normalized)) {
            throw new \InvalidArgumentException("{$field} must be numeric.");
        }

        $number = (float) $normalized;

        if (! is_finite($number)) {
            throw new \InvalidArgumentException("{$field} is outside the supported numeric range.");
        }

        return $number;
    }

    private function nonNegativeNumber(string $value, string $field): float
    {
        $number = $this->number($value, $field);
        if ($number < 0) {
            throw new \InvalidArgumentException("{$field} cannot be negative.");
        }

        return $number;
    }

    private function inventoryQuantity(string $value): int
    {
        $number = $this->number($value, 'Variant Inventory Qty');
        if (floor($number) !== $number || $number < -2147483648 || $number > 2147483647) {
            throw new \InvalidArgumentException('Variant Inventory Qty must be a whole number in the supported range.');
        }

        // Shopify can report negative available inventory when overselling is
        // enabled. LONGOS has no negative-stock state, so import it as zero
        // stock instead of rejecting the otherwise valid product.
        return max(0, (int) $number);
    }

    private function boolValue(string $value, bool $default): bool
    {
        if ($value === '') {
            return $default;
        }

        $normalized = strtolower($value);
        if (in_array($normalized, ['1', 'true', 'yes', 'y'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'n'], true)) {
            return false;
        }

        throw new \InvalidArgumentException("Boolean value '{$value}' is invalid.");
    }

    private function externalUrl(string $url): ?string
    {
        $url = $this->sourceKey($url);
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)
            && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        return $url;
    }

    private function sourceKey(string $source): string
    {
        $source = trim(html_entity_decode($source, ENT_QUOTES, 'UTF-8'));

        return str_starts_with($source, '//') ? 'https:' . $source : $source;
    }

    private function cleanHtml(string $html): string
    {
        $html = strip_tags($html, '<p><br><strong><em><b><i><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><a><img><table><thead><tbody><tr><th><td>');
        // A Shopify CSV is an external import source. Keep readable markup but
        // strip inline event/style attributes and unsafe link/image protocols.
        $html = preg_replace('/\\s+(?:on[a-z]+|style|srcset|formaction|xlink:href|background)\\s*=\\s*(?:"[^"]*"|\'[^\']*\'|[^\\s>]+)/i', '', $html) ?? '';

        return preg_replace_callback('/\\s+(href|src)\\s*=\\s*(?:"([^"]*)"|\'([^\']*)\'|([^\\s>]+))/i', function (array $match): string {
            $value     = html_entity_decode($match[2] ?? $match[3] ?? $match[4] ?? '', ENT_QUOTES, 'UTF-8');
            $attribute = strtolower($match[1]);
            $url       = $this->safeContentUrl($value, $attribute);

            return $url ? ' ' . $attribute . '="' . e($url) . '"' : '';
        }, $html) ?? '';
    }

    private function safeContentUrl(string $value, string $attribute): ?string
    {
        $value = trim($value);
        if ($external = $this->externalUrl($value)) {
            return $external;
        }
        if ($attribute !== 'href' || $value === '' || preg_match('/[\\x00-\\x1F\\x7F\\\\]/', $value)) {
            return null;
        }
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return $value;
        }
        if (preg_match('/^#[A-Za-z0-9_:.-]+$/', $value)) {
            return $value;
        }
        if (preg_match('/^mailto:([^?]+)(?:\\?[^\\s]*)?$/i', $value, $match)
            && filter_var(rawurldecode($match[1]), FILTER_VALIDATE_EMAIL)
        ) {
            return $value;
        }
        if (preg_match('/^tel:[+0-9(). -]{3,32}$/i', $value)) {
            return $value;
        }

        return null;
    }

    /** Convert locally stored description images into portable public URLs. */
    private function exportHtmlImages(string $html): string
    {
        return preg_replace_callback('/<img\b[^>]*>/i', function (array $tag): string {
            $source = $this->sourceKey($this->imageTagSource($tag[0]));
            if ($source === '') {
                return '';
            }
            $url    = $this->externalUrl($source);
            if ($url === null) {
                $path = ltrim((string) (parse_url($source, PHP_URL_PATH) ?: $source), '/');
                if ($path === '' || str_contains($path, '..')) {
                    return '';
                }
                $url = image_origin($path);
            }

            return '<img src="' . e($url) . '" alt="' . e(Str::limit($this->htmlAttribute($tag[0], 'alt'), 500, '')) . '" loading="lazy">';
        }, $html) ?? '';
    }

    private function imageTagSource(string $tag): string
    {
        $source = $this->htmlAttribute($tag, 'src');

        return $source !== '' ? $source : $this->htmlAttribute($tag, 'data-src');
    }

    private function htmlAttribute(string $tag, string $name): string
    {
        $quoted = preg_quote($name, '/');
        if (! preg_match('/\s' . $quoted . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $match)) {
            return '';
        }

        return trim(html_entity_decode($match[1] ?? $match[2] ?? $match[3] ?? '', ENT_QUOTES, 'UTF-8'));
    }

    private function hasHandleTable(): bool
    {
        return $this->handleTableAvailable ??= Schema::hasTable('shopify_product_handles');
    }

    private function hasVariantSkuTable(): bool
    {
        return $this->variantSkuTableAvailable ??= Schema::hasTable('shopify_product_variant_skus');
    }

    /** Prefix formula-like text before a merchant opens an export in Excel. */
    private function writeCsvRow($stream, array $row): void
    {
        $safe = array_map(static function (mixed $value): mixed {
            if (! is_string($value)) {
                return $value;
            }
            $trimmed        = ltrim($value);
            $dangerousMinus = str_starts_with($trimmed, '-') && preg_match('/^-\d+(?:\.\d+)?$/', $trimmed) !== 1;
            if (preg_match('/^[=+@]/', $trimmed) || $dangerousMinus) {
                return "'" . $value;
            }

            return $value;
        }, $row);
        fputcsv($stream, $safe, ',', '"', '');
    }
}
