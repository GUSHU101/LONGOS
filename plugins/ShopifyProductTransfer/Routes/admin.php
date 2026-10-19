<?php

use Illuminate\Support\Facades\Route;
use Plugin\ShopifyProductTransfer\Controllers\ShopifyProductController;

Route::middleware('can:products_index')->group(function (): void {
    Route::get('shopify-products', [ShopifyProductController::class, 'index'])->name('shopify_product_transfer.index');
    Route::get('shopify-products/import', [ShopifyProductController::class, 'importForm'])->name('shopify_product_transfer.import_form');
    Route::get('shopify-products/template', [ShopifyProductController::class, 'template'])->name('shopify_product_transfer.template');
    Route::get('shopify-products/export', [ShopifyProductController::class, 'export'])->name('shopify_product_transfer.export');
    Route::post('shopify-products/export-selected', [ShopifyProductController::class, 'exportSelected'])->name('shopify_product_transfer.export_selected');
});

Route::post('shopify-products/import', [ShopifyProductController::class, 'import'])
    ->middleware('can:products_create')
    ->name('shopify_product_transfer.import');
Route::post('shopify-products/imports/{importId}/process', [ShopifyProductController::class, 'processPending'])
    ->whereUuid('importId')
    ->middleware('can:products_create')
    ->name('shopify_product_transfer.process_pending');
