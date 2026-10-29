<?php

use Beike\Admin\Http\Controllers;
use Beike\Admin\Http\Controllers\ForgottenController;
use Illuminate\Support\Facades\Route;

$adminName = admin_name();
Route::prefix($adminName)
    ->middleware(['admin'])
    ->name("{$adminName}.")
    ->group(function () {
        Route::get('login', [Controllers\LoginController::class, 'show'])->name('login.show');
        Route::post('login', [Controllers\LoginController::class, 'store'])->middleware('throttle:auth-login')->name('login.store');

        Route::get('forgotten', [ForgottenController::class, 'index'])->name('forgotten.index');
        Route::post('forgotten/send_code', [ForgottenController::class, 'sendVerifyCode'])->middleware('throttle:auth-recovery')->name('forgotten.send_code');
        Route::post('forgotten/password', [ForgottenController::class, 'changePassword'])->middleware('throttle:auth-recovery')->name('forgotten.password');

        Route::middleware('admin_auth:' . \Beike\Models\AdminUser::AUTH_GUARD)
            ->group(function () {
                Route::get('/', [Controllers\HomeController::class, 'index'])->name('home.index');
                Route::get('/menus', [Controllers\HomeController::class, 'menus'])->name('home.menus');

                // SaaS control-plane API. Disabled by default and additionally
                // restricted to the root administrator on a control domain.
                Route::prefix('saas')->name('saas.')->middleware(['saas.control', 'throttle:saas-control'])->group(function () {
                    Route::get('manage', [Controllers\SaasSiteController::class, 'page'])->name('sites.page');
                    Route::get('sites', [Controllers\SaasSiteController::class, 'index'])->name('sites.index');
                    Route::post('sites/batch', [Controllers\SaasSiteController::class, 'batchStore'])->name('sites.batch_store');
                    Route::get('sites/{site}', [Controllers\SaasSiteController::class, 'show'])->name('sites.show');
                    Route::patch('sites/{site}/status', [Controllers\SaasSiteController::class, 'updateStatus'])->name('sites.status');
                    Route::delete('sites/{site}', [Controllers\SaasSiteController::class, 'destroy'])->name('sites.destroy');
                    Route::post('sites/{site}/domains', [Controllers\SaasSiteController::class, 'addDomain'])->name('domains.store');
                    Route::post('sites/{site}/domains/{domain}/verify', [Controllers\SaasSiteController::class, 'verifyDomain'])->name('domains.verify');
                    Route::delete('sites/{site}/domains/{domain}', [Controllers\SaasSiteController::class, 'removeDomain'])->name('domains.destroy');
                    Route::patch('sites/{site}/domains/{domain}/primary', [Controllers\SaasSiteController::class, 'makePrimaryDomain'])->name('domains.primary');
                    Route::patch('sites/{site}/domains/{domain}/routing', [Controllers\SaasSiteController::class, 'updateDomainRouting'])->name('domains.routing');
                    Route::post('sites/{site}/sender-domains', [Controllers\SaasSiteController::class, 'assignSenderDomain'])->name('sender_domains.store');
                    Route::delete('sites/{site}/sender-domains/{mailSenderDomain}', [Controllers\SaasSiteController::class, 'removeSenderDomain'])->name('sender_domains.destroy');
                    Route::post('sites/{site}/health', [Controllers\SaasSiteController::class, 'health'])->name('sites.health');
                });

                // Tenant root administrators can onboard domains only for the
                // currently resolved site. New domains stay unroutable until
                // their DNS TXT challenge has been verified.
                Route::prefix('saas/domains')->name('saas.tenant_domains.')->middleware(['saas.tenant-admin', 'throttle:saas-tenant-domains'])->group(function () {
                    Route::get('manage', [Controllers\SaasTenantDomainController::class, 'page'])->name('page');
                    Route::get('/', [Controllers\SaasTenantDomainController::class, 'index'])->name('index');
                    Route::post('/', [Controllers\SaasTenantDomainController::class, 'store'])->name('store');
                    Route::patch('subdomain', [Controllers\SaasTenantDomainController::class, 'updateSubdomain'])->name('subdomain');
                    Route::post('{domain}/verify', [Controllers\SaasTenantDomainController::class, 'verify'])->name('verify');
                    Route::post('{domain}/rotate', [Controllers\SaasTenantDomainController::class, 'rotate'])->name('rotate');
                    Route::patch('{domain}/primary', [Controllers\SaasTenantDomainController::class, 'makePrimary'])->name('primary');
                    Route::patch('{domain}/routing', [Controllers\SaasTenantDomainController::class, 'updateRouting'])->name('routing');
                    Route::delete('{domain}', [Controllers\SaasTenantDomainController::class, 'destroy'])->name('destroy');
                });

                // Dashboard API routes
                Route::prefix('dashboard')->group(function () {
                    Route::get('overview', [Controllers\DashboardController::class, 'getOverview'])->name('dashboard.overview');
                    Route::get('stats', [Controllers\DashboardController::class, 'getStats'])->name('dashboard.stats');
                    Route::get('trends', [Controllers\DashboardController::class, 'getTrends'])->name('dashboard.trends');
                    Route::get('mini-charts', [Controllers\DashboardController::class, 'getMiniChartData'])->name('dashboard.mini-charts');
                    Route::get('source', [Controllers\DashboardController::class, 'getSourceAnalysis'])->name('dashboard.source');
                    Route::get('funnel', [Controllers\DashboardController::class, 'getFunnelData'])->name('dashboard.funnel');
                    Route::get('visitor-countries', [Controllers\DashboardController::class, 'getVisitorCountryRanking'])->name('dashboard.visitor-countries');
                    Route::get('products/{type}', [Controllers\DashboardController::class, 'getProductRanking'])->name('dashboard.products');
                    Route::post('export', [Controllers\DashboardController::class, 'exportReport'])->name('dashboard.export');
                    Route::get('cache/status', [Controllers\DashboardController::class, 'getCacheStatus'])->name('dashboard.cache.status');
                    Route::post('cache/clear', [Controllers\DashboardController::class, 'clearCache'])->name('dashboard.cache.clear');
                });
                Route::post('files', [Controllers\FileController::class, 'store'])->name('file.store');
                Route::post('catch_images', [Controllers\FileController::class, 'catchImages'])->name('file.catch_images');
                Route::get('plugins/ticket_expired', [Controllers\PluginController::class, 'pluginTicketExpired'])->name('plugins.ticket_expired');

                // Keep this route name during the language-module retirement.
                // Previously compiled Blade views and persisted plugin-menu data
                // may still generate admin.plugins.language.  Resolving it to the
                // dashboard prevents that obsolete reference from making every
                // admin page fail before the cache is rebuilt, without restoring
                // a language settings screen.
                Route::get('plugins/language', [Controllers\HomeController::class, 'index'])->name('plugins.language');

                Route::middleware('can:plugins_index')->get('plugins/diagnostics', [Controllers\PluginController::class, 'diagnostics'])->name('plugins.diagnostics');
                Route::middleware(['can:plugins_update', 'throttle:10,1'])
                    ->post('plugins/longos-platform-pay/health', [Controllers\PluginController::class, 'platformPaymentHealth'])
                    ->name('plugins.platform_payment_health');
                Route::get('plugin-customization', [Controllers\PluginCustomizationController::class, 'index'])->name('plugin_customization.index');

                // 个人中心
                Route::middleware('can:account_index')->get('account', [Controllers\AccountController::class, 'index'])->name('account.index');
                Route::middleware('can:account_update')->put('account', [Controllers\AccountController::class, 'update'])->name('account.update');
                Route::middleware('can:account_update')->post('account/tokens', [Controllers\AccountController::class, 'storeToken'])->name('account.tokens.store');
                Route::middleware(['can:account_update', 'throttle:integration-token-reveal'])->post('account/tokens/{token}/reveal', [Controllers\AccountController::class, 'revealToken'])->name('account.tokens.reveal');
                Route::middleware('can:account_update')->delete('account/tokens/{token}', [Controllers\AccountController::class, 'destroyToken'])->name('account.tokens.destroy');

                // 属性
                Route::middleware('can:attributes_update')->post('attributes/{id}/values', [Controllers\AttributeController::class, 'storeValue'])->name('attributes.values.store');
                Route::middleware('can:attributes_show')->get('attributes/{id}/values/autocomplete', [Controllers\AttributeController::class, 'autocompleteValue'])->name('attributes.values.autocomplete');
                Route::middleware('can:attributes_update')->put('attributes/{id}/values/{value_id}', [Controllers\AttributeController::class, 'updateValue'])->name('attributes.values.update');
                Route::middleware('can:attributes_update')->delete('attributes/{id}/values/{value_id}', [Controllers\AttributeController::class, 'destroyValue'])->name('attributes.values.destroy');
                Route::middleware('can:attributes_index')->get('attributes', [Controllers\AttributeController::class, 'index'])->name('attributes.index');
                Route::middleware('can:attributes_show')->get('attributes/autocomplete', [Controllers\AttributeController::class, 'autocomplete'])->name('attributes.autocomplete');
                Route::middleware('can:attributes_show')->get('attributes/{id}', [Controllers\AttributeController::class, 'show'])->name('attributes.show');
                Route::middleware('can:attributes_create')->post('attributes', [Controllers\AttributeController::class, 'store'])->name('attributes.store');
                Route::middleware('can:attributes_update')->put('attributes/{id}', [Controllers\AttributeController::class, 'update'])->name('attributes.update');
                Route::middleware('can:attributes_delete')->delete('attributes/{id}', [Controllers\AttributeController::class, 'destroy'])->name('attributes.destroy');

                // 高级筛选
                Route::middleware('can:products_filter_index')->get('multi_filter', [Controllers\MultiFilterController::class, 'index'])->name('multi_filter.index');
                Route::middleware('can:products_filter_update')->post('multi_filter', [Controllers\MultiFilterController::class, 'store'])->name('multi_filter.store');

                // 属性组
                Route::middleware('can:attribute_groups_index')->get('attribute_groups', [Controllers\AttributeGroupController::class, 'index'])->name('attribute_groups.index');
                Route::middleware('can:attribute_groups_create')->post('attribute_groups', [Controllers\AttributeGroupController::class, 'store'])->name('attribute_groups.store');
                Route::middleware('can:attribute_groups_update')->put('attribute_groups/{id}', [Controllers\AttributeGroupController::class, 'update'])->name('attribute_groups.update');
                Route::middleware('can:attribute_groups_delete')->delete('attribute_groups/{id}', [Controllers\AttributeGroupController::class, 'destroy'])->name('attribute_groups.destroy');

                // 商品品牌
                Route::middleware('can:brands_index')->get('brands/names', [Controllers\BrandController::class, 'getNames'])->name('brands.names');
                Route::middleware('can:brands_index')->get('brands/autocomplete', [Controllers\BrandController::class, 'autocomplete'])->name('brands.autocomplete');
                Route::middleware('can:brands_show')->get('brands/{id}/name', [Controllers\BrandController::class, 'name'])->name('brands.name');
                Route::middleware('can:brands_index')->get('brands', [Controllers\BrandController::class, 'index'])->name('brands.index');
                Route::middleware('can:brands_create')->post('brands', [Controllers\BrandController::class, 'store'])->name('brands.store');
                Route::middleware('can:brands_update')->put('brands/{brand}', [Controllers\BrandController::class, 'update'])->name('brands.update');
                Route::middleware('can:brands_delete')->delete('brands/{brand}', [Controllers\BrandController::class, 'destroy'])->name('brands.destroy');

                // 商品分类
                Route::middleware('can:categories_index')->get('categories/autocomplete', [Controllers\CategoryController::class, 'autocomplete'])->name('categories.autocomplete');
                Route::middleware(['can:categories_update', 'can:products_update'])->get('categories/{category}/smart-products', [Controllers\CategoryController::class, 'smartProducts'])->name('categories.smart_products');
                Route::middleware(['can:categories_update', 'can:products_update'])->post('categories/{category}/smart-products', [Controllers\CategoryController::class, 'storeSmartProducts'])->name('categories.smart_products.store');
                Route::middleware(['can:categories_update', 'can:products_update'])->put('categories/{category}/smart-products/rule', [Controllers\CategoryController::class, 'updateSmartRule'])->name('categories.smart_products.rule');
                Route::middleware('can:categories_show')->get('categories/{category}/name', [Controllers\CategoryController::class, 'name'])->name('categories.name');
                Route::middleware('can:categories_show')->get('categories/{category}/products', [Controllers\CategoryController::class, 'getProducts'])->name('categories.products');
                Route::middleware('can:categories_index')->get('categories', [Controllers\CategoryController::class, 'index'])->name('categories.index');
                Route::middleware('can:categories_create')->get('categories/create', [Controllers\CategoryController::class, 'create'])->name('categories.create');
                Route::middleware('can:categories_create')->post('categories', [Controllers\CategoryController::class, 'store'])->name('categories.store');
                Route::middleware('can:categories_update')->get('categories/{category}/edit', [Controllers\CategoryController::class, 'edit'])->name('categories.edit');
                Route::middleware('can:categories_update')->put('categories/{category}', [Controllers\CategoryController::class, 'update'])->name('categories.update');
                Route::middleware('can:categories_delete')->delete('categories/{category}', [Controllers\CategoryController::class, 'destroy'])->name('categories.destroy');

                // 国家
                Route::middleware('can:countries_index')->get('countries', [Controllers\CountryController::class, 'index'])->name('countries.index');
                Route::middleware('can:countries_create')->post('countries', [Controllers\CountryController::class, 'store'])->name('countries.store');
                Route::middleware('can:countries_index')->post('countries/batch', [Controllers\CountryController::class, 'batch'])->name('countries.batch');
                Route::middleware('can:countries_update')->post('countries/sync', [Controllers\CountryController::class, 'sync'])->name('countries.sync');
                Route::middleware('can:countries_update')->put('countries/{id}', [Controllers\CountryController::class, 'update'])->name('countries.update');
                Route::middleware('can:countries_delete')->delete('countries/{id}', [Controllers\CountryController::class, 'destroy'])->name('countries.destroy');

                // 省份
                Route::middleware('can:zones_index')->get('zones', [Controllers\ZoneController::class, 'index'])->name('zones.index');
                Route::middleware('can:zones_create')->post('zones', [Controllers\ZoneController::class, 'store'])->name('zones.store');
                Route::middleware('can:zones_index')->post('zones/batch', [Controllers\ZoneController::class, 'batch'])->name('zones.batch');
                Route::middleware('can:zones_update')->post('zones/sync', [Controllers\ZoneController::class, 'sync'])->name('zones.sync');
                Route::middleware('can:zones_update')->put('zones/{id}', [Controllers\ZoneController::class, 'update'])->name('zones.update');
                Route::middleware('can:zones_delete')->delete('zones/{id}', [Controllers\ZoneController::class, 'destroy'])->name('zones.destroy');

                // 客户
                Route::middleware('can:customers_index')->get('customers/trashed', [Controllers\CustomerController::class, 'trashed'])->name('customers.trashed');
                Route::middleware('can:customers_index')->get('customers', [Controllers\CustomerController::class, 'index'])->name('customers.index');
                Route::middleware('can:customers_create')->post('customers', [Controllers\CustomerController::class, 'store'])->name('customers.store');
                Route::middleware('can:customers_show')->get('customers/{id}/edit', [Controllers\CustomerController::class, 'edit'])->name('customers.edit');
                Route::middleware('can:customers_show')->get('customers/{id}/login', [Controllers\CustomerController::class, 'login'])->name('customers.login');
                Route::middleware('can:customers_update')->put('customers/{id}', [Controllers\CustomerController::class, 'update'])->name('customers.update');
                Route::middleware('can:customers_update')->put('customers/{customer}/update_active', [Controllers\CustomerController::class, 'updateActive'])->name('customers.update_active');
                Route::middleware('can:customers_update')->put('customers/{customer}/update_status', [Controllers\CustomerController::class, 'updateStatus'])->name('customers.update_status');
                Route::middleware('can:customers_create')->delete('customers/{id}/restore', [Controllers\CustomerController::class, 'restore'])->name('customers.restore');
                Route::middleware('can:customers_delete')->delete('customers/{id}', [Controllers\CustomerController::class, 'destroy'])->name('customers.destroy');
                Route::middleware('can:customers_delete')->delete('customers/{id}/force', [Controllers\CustomerController::class, 'forceDelete'])->name('customers.force_delete');
                Route::middleware('can:customers_delete')->post('customers/force_delete_all', [Controllers\CustomerController::class, 'forceDeleteAll'])->name('customers.force_delete_all');

                // 客户地址
                Route::middleware('can:customers_show')->get('customers/{customer_id}/addresses', [Controllers\AddressController::class, 'index'])->name('customers.addresses.index');
                Route::middleware('can:customers_update')->post('customers/{customer_id}/addresses', [Controllers\AddressController::class, 'store'])->name('customers.addresses.store');
                Route::middleware('can:customers_update')->put('customers/{customer_id}/addresses/{id}', [Controllers\AddressController::class, 'update'])->name('customers.addresses.update');
                Route::middleware('can:customers_update')->delete('customers/{customer_id}/addresses/{id}', [Controllers\AddressController::class, 'destroy'])->name('customers.addresses.destroy');

                Route::get('countries/{country_id}/zones', [Controllers\ZoneController::class, 'listByCountry'])->name('countries.zones.index');

                // 客户组
                Route::middleware('can:customer_groups_index')->get('customer_groups', [Controllers\CustomerGroupController::class, 'index'])->name('customer_groups.index');
                Route::middleware('can:customer_groups_create')->post('customer_groups', [Controllers\CustomerGroupController::class, 'store'])->name('customer_groups.store');
                Route::middleware('can:customer_groups_update')->put('customer_groups/{id}', [Controllers\CustomerGroupController::class, 'update'])->name('customer_groups.update');
                Route::middleware('can:customer_groups_delete')->delete('customer_groups/{id}', [Controllers\CustomerGroupController::class, 'destroy'])->name('customer_groups.destroy');

                // 折扣
                Route::middleware('can:discounts_index')->get('discounts', [Controllers\DiscountController::class, 'index'])->name('discounts.index');
                Route::middleware('can:discounts_create')->post('discounts', [Controllers\DiscountController::class, 'store'])->name('discounts.store');
                Route::middleware('can:discounts_update')->put('discounts/{id}', [Controllers\DiscountController::class, 'update'])->name('discounts.update');
                Route::middleware('can:discounts_delete')->delete('discounts/{id}', [Controllers\DiscountController::class, 'destroy'])->name('discounts.destroy');

                // 页面装修
                Route::middleware('can:design_index')->get('design/builder', [Controllers\DesignController::class, 'index'])->name('design.index');
                // Compatibility endpoint for persisted sidebar/plugin menu
                // records from the retired design_app_home module. Keeping a
                // real named route prevents route generation from crashing
                // the whole admin page before an administrator can update
                // those records.
                Route::middleware('can:design_index')->get('design_app_home', [Controllers\DesignController::class, 'index'])->name('design_app_home.index');
                Route::middleware('can:design_update')->put('design/builder', [Controllers\DesignController::class, 'update'])->name('design.update');
                Route::middleware('can:design_update')->post('design/builder/preview-module', [Controllers\DesignController::class, 'previewModule'])->name('design.preview_module');
                Route::middleware('can:design_index')->get('design/builder/revisions', [Controllers\DesignController::class, 'revisions'])->name('design.revisions');
                Route::middleware('can:design_update')->post('design/builder/revisions/{revision}/restore', [Controllers\DesignController::class, 'restoreRevision'])->whereNumber('revision')->name('design.revisions.restore');
                Route::middleware('can:design_index')->get('design/builder/export', [Controllers\DesignController::class, 'export'])->name('design.export');
                Route::middleware('can:design_update')->post('design/builder/import', [Controllers\DesignController::class, 'import'])->name('design.import');
                Route::middleware('can:design_index')->get('design/navigation', [Controllers\DesignNavigationController::class, 'index'])->name('design.navigation.index');
                Route::middleware('can:design_index')->get('design/navigation/resources', [Controllers\DesignNavigationController::class, 'resources'])->name('design.navigation.resources');
                Route::middleware('can:design_update')->put('design/navigation', [Controllers\DesignNavigationController::class, 'update'])->name('design.navigation.update');

                Route::middleware('can:app_push_index')->get('app_push', [Controllers\AppPushController::class, 'index'])->name('app_push.index');
                Route::middleware('can:app_push_index')->post('/app_push/push', [Controllers\AppPushController::class, 'push'])->name('app_push.push');

                // 模板主题
                Route::middleware('can:theme_index')->get('themes', [Controllers\ThemeController::class, 'index'])->name('theme.index');
                Route::middleware('can:theme_update')->put('themes/{code}', [Controllers\ThemeController::class, 'update'])->name('theme.update');

                Route::put('edit', [Controllers\EditController::class, 'update'])->name('edit');

                // 图片库
                Route::middleware('can:file_manager_index')->get('file_manager', [Controllers\FileManagerController::class, 'index'])->name('file_manager.index');
                Route::middleware('can:file_manager_index')->get('file_manager/files', [Controllers\FileManagerController::class, 'getFiles'])->name('file_manager.get_files');
                Route::middleware('can:file_manager_index')->get('file_manager/directories', [Controllers\FileManagerController::class, 'getDirectories'])->name('file_manager.get_directories');
                Route::middleware('can:file_manager_create')->post('file_manager/directories', [Controllers\FileManagerController::class, 'createDirectory'])->name('file_manager.create_directory');
                Route::middleware('can:file_manager_create')->post('file_manager/upload', [Controllers\FileManagerController::class, 'uploadFiles'])->name('file_manager.upload');
                Route::middleware('can:file_manager_update')->post('file_manager/rename', [Controllers\FileManagerController::class, 'rename'])->name('file_manager.rename');
                Route::middleware('can:file_manager_delete')->delete('file_manager/files', [Controllers\FileManagerController::class, 'destroyFiles'])->name('file_manager.delete_files');
                Route::middleware('can:file_manager_delete')->delete('file_manager/directories', [Controllers\FileManagerController::class, 'destroyDirectories'])->name('file_manager.delete_directories');
                Route::middleware('can:file_manager_update')->post('file_manager/move_directories', [Controllers\FileManagerController::class, 'moveDirectories'])->name('file_manager.move_directories');
                Route::middleware('can:file_manager_update')->post('file_manager/move_files', [Controllers\FileManagerController::class, 'moveFiles'])->name('file_manager.move_files');
                Route::middleware('can:file_manager_index')->get('file_manager/export', [Controllers\FileManagerController::class, 'exportZip'])->name('file_manager.export');

                Route::get('logout', [Controllers\LogoutController::class, 'index'])->name('logout.index');

                // 订单
                Route::middleware('can:orders_restore')->put('orders/restore/{id}', [Controllers\OrderController::class, 'restore'])->name('orders.restore');
                Route::middleware('can:orders_trashed')->get('orders/trashed', [Controllers\OrderController::class, 'trashed'])->name('orders.trashed');
                Route::middleware('can:orders_index')->get('orders', [Controllers\OrderController::class, 'index'])->name('orders.index');
                Route::middleware('can:orders_export')->get('orders/export', [Controllers\OrderController::class, 'export'])->name('orders.export');
                Route::middleware('can:orders_show')->get('orders/shipping', [Controllers\OrderController::class, 'shipping'])->name('orders.shipping.get');
                Route::middleware('can:orders_show')->post('orders/shipping', [Controllers\OrderController::class, 'shipping'])->name('orders.shipping.post');
                Route::middleware('can:orders_show')->get('orders/{order}', [Controllers\OrderController::class, 'show'])->name('orders.show');
                Route::middleware('can:orders_delete')->delete('orders/{order}', [Controllers\OrderController::class, 'destroy'])->name('orders.destroy');
                Route::middleware('can:orders_update_status')->put('orders/{order}/status', [Controllers\OrderController::class, 'updateStatus'])->name('orders.update_status');
                Route::middleware('can:orders_update_status')->put('orders/{order}/shipments/{shipment}', [Controllers\OrderController::class, 'updateShipment'])->name('orders.update_shipment');
                Route::middleware('can:orders_update_status')->post('orders/{order}/shipments', [Controllers\OrderController::class, 'createShipment'])->name('orders.create_shipment');

                // Abandoned unpaid-order recovery
                Route::middleware('can:order_recoveries_index')->get('order-recoveries', [Controllers\AbandonedOrderRecoveryController::class, 'index'])->name('order_recoveries.index');
                Route::middleware('can:order_recoveries_update')->put('order-recoveries/settings', [Controllers\AbandonedOrderRecoveryController::class, 'updateSettings'])->name('order_recoveries.settings');
                Route::middleware('can:order_recoveries_index')->post('order-recoveries/preview', [Controllers\AbandonedOrderRecoveryController::class, 'preview'])->name('order_recoveries.preview');
                Route::middleware(['can:order_recoveries_send', 'throttle:20,1'])->post('order-recoveries/send', [Controllers\AbandonedOrderRecoveryController::class, 'send'])->name('order_recoveries.send');

                // 插件
                Route::middleware('can:plugins_index')->get('plugins', [Controllers\PluginController::class, 'index'])->name('plugins.index');
                Route::middleware('can:plugins_show')->get('plugins/{code}/edit', [Controllers\PluginController::class, 'edit'])->name('plugins.edit');
                Route::middleware('can:plugins_update')->put('plugins/{code}', [Controllers\PluginController::class, 'update'])->name('plugins.update');
                Route::middleware('can:plugins_update_status')->put('plugins/{code}/status', [Controllers\PluginController::class, 'updateStatus'])->name('plugins.update_status');
                Route::middleware('can:plugins_install')->post('plugins/{code}/install', [Controllers\PluginController::class, 'install'])->name('plugins.install');
                Route::middleware('can:plugins_uninstall')->post('plugins/{code}/uninstall', [Controllers\PluginController::class, 'uninstall'])->name('plugins.uninstall');

                // 插件分组
                Route::middleware('can:plugins_index')->get('plugins/shipping', [Controllers\PluginController::class, 'shipping'])->name('plugins.shipping');
                Route::middleware('can:plugins_index')->get('plugins/payment', [Controllers\PluginController::class, 'payment'])->name('plugins.payment');
                Route::middleware('can:plugins_index')->get('plugins/marketing', [Controllers\PluginController::class, 'marketing'])->name('plugins.marketing');
                Route::middleware('can:plugins_index')->get('plugins/analysis', [Controllers\PluginController::class, 'analysis'])->name('plugins.analysis');
                Route::middleware('can:plugins_index')->get('plugins/service', [Controllers\PluginController::class, 'service'])->name('plugins.service');
                Route::middleware('can:plugins_index')->get('plugins/feature', [Controllers\PluginController::class, 'feature'])->name('plugins.feature');
                Route::middleware('can:plugins_index')->get('plugins/theme', [Controllers\PluginController::class, 'theme'])->name('plugins.theme');

                // 报表
                Route::middleware('can:reports_sale')->get('reports/sale', [Controllers\ReportController::class, 'sale'])->name('reports_sale.index');
                Route::middleware('can:reports_view')->get('reports/view', [Controllers\ReportController::class, 'view'])->name('reports_view.index');
                Route::middleware('can:reports_view')->get('reports/product_view/{product_id}', [Controllers\ReportController::class, 'productView'])->name('reports_view.product');

                // Marketplace package source is shared by every SaaS site.
                // Tenants can configure approved local plugins, while only a
                // control-site root may purchase, download or update code.
                Route::middleware('saas.platform-admin')->group(function () {
                    Route::get('marketing/version_check', [Controllers\MarketingController::class, 'versionCheck'])->name('marketing.version_check');
                    Route::middleware('can:marketing_index')->post('marketing/check_domain', [Controllers\MarketingController::class, 'checkDomain'])->name('marketing.check_domain');
                    Route::middleware('can:marketing_index')->get('marketing/get_token', [Controllers\MarketingController::class, 'getToken'])->name('marketing.get_token');
                    Route::middleware('can:marketing_index')->get('marketing/website_check_domain', [Controllers\MarketingController::class, 'checkToken'])->name('marketing.website_check_domain');

                    Route::middleware('can:marketing_index')->get('marketing', [Controllers\MarketingController::class, 'index'])->name('marketing.index');
                    Route::middleware('can:marketing_show')->get('marketing/{code}', [Controllers\MarketingController::class, 'show'])->name('marketing.show');
                    Route::middleware('can:marketing_buy')->post('marketing/{code}/buy', [Controllers\MarketingController::class, 'buy'])->name('marketing.buy');
                    Route::middleware('can:marketing_buy')->post('marketing/{id}/buy_service', [Controllers\MarketingController::class, 'buyService'])->name('marketing.buy_service');
                    Route::middleware('can:marketing_download')->post('marketing/{code}/download', [Controllers\MarketingController::class, 'download'])->name('marketing.download');
                    Route::middleware('can:marketing_show')->get('marketing/service_orders/{id}', [Controllers\MarketingController::class, 'serviceOrder'])->name('marketing.service_order');
                });

                // 文章
                Route::middleware('can:pages_index')->get('pages', [Controllers\PagesController::class, 'index'])->name('pages.index');
                Route::middleware('can:pages_index')->get('pages/autocomplete', [Controllers\PagesController::class, 'autocomplete'])->name('pages.autocomplete');
                Route::middleware('can:pages_index')->get('pages/names', [Controllers\PagesController::class, 'getNames'])->name('pages.names');
                Route::middleware('can:pages_create')->get('pages/create', [Controllers\PagesController::class, 'create'])->name('pages.create');
                Route::middleware('can:pages_show')->get('pages/{page}/edit', [Controllers\PagesController::class, 'edit'])->name('pages.edit');
                Route::middleware('can:pages_show')->get('pages/{page}/name', [Controllers\PagesController::class, 'name'])->name('pages.name');
                Route::middleware('can:pages_create')->post('pages', [Controllers\PagesController::class, 'store'])->name('pages.store');
                Route::middleware('can:pages_update')->put('pages/{page}', [Controllers\PagesController::class, 'update'])->name('pages.update');
                Route::middleware('can:pages_delete')->delete('pages/{page}', [Controllers\PagesController::class, 'destroy'])->name('pages.destroy');

                // 文章分类
                Route::middleware('can:page_categories_index')->get('page_categories', [Controllers\PageCategoryController::class, 'index'])->name('page_categories.index');
                Route::middleware('can:page_categories_index')->get('page_categories/autocomplete', [Controllers\PageCategoryController::class, 'autocomplete'])->name('page_categories.autocomplete');
                Route::middleware('can:page_categories_create')->get('page_categories/create', [Controllers\PageCategoryController::class, 'create'])->name('page_categories.create');
                Route::middleware('can:page_categories_show')->get('page_categories/{page_category}/edit', [Controllers\PageCategoryController::class, 'edit'])->name('page_categories.edit');
                Route::middleware('can:page_categories_show')->get('page_categories/{page_category}/name', [Controllers\PageCategoryController::class, 'name'])->name('page_categories.name');
                Route::middleware('can:page_categories_create')->post('page_categories', [Controllers\PageCategoryController::class, 'store'])->name('page_categories.store');
                Route::middleware('can:page_categories_update')->put('page_categories/{page_category}', [Controllers\PageCategoryController::class, 'update'])->name('page_categories.update');
                Route::middleware('can:page_categories_delete')->delete('page_categories/{page_category}', [Controllers\PageCategoryController::class, 'destroy'])->name('page_categories.destroy');

                // 商品
                Route::middleware('can:products_restore')->put('products/restore', [Controllers\ProductController::class, 'restore'])->name('products.restore');
                Route::middleware('can:products_trashed')->get('products/trashed', [Controllers\ProductController::class, 'trashed'])->name('products.trashed');
                Route::middleware('can:products_trashed')->post('products/trashed/clear', [Controllers\ProductController::class, 'trashedClear'])->name('products.trashed.clear');
                Route::middleware('can:products_show')->get('products/{id}/name', [Controllers\ProductController::class, 'name'])->name('products.name');
                Route::middleware('can:products_index')->get('products/names', [Controllers\ProductController::class, 'getNames'])->name('products.names');
                Route::middleware('can:products_index')->get('products/autocomplete', [Controllers\ProductController::class, 'autocomplete'])->name('products.autocomplete');
                Route::middleware('can:products_index')->get('products/latest', [Controllers\ProductController::class, 'latest'])->name('products.latest');

                Route::middleware('can:products_update')->post('products/status', [Controllers\ProductController::class, 'updateStatus'])->name('products.update_status');
                Route::middleware('can:products_update')->post('products/categories', [Controllers\ProductController::class, 'batchCategories'])->name('products.batch_categories');
                Route::middleware('can:products_delete')->delete('products/delete', [Controllers\ProductController::class, 'destroyByIds'])->name('products.batch_delete');
                Route::middleware('can:products_index')->get('products', [Controllers\ProductController::class, 'index'])->name('products.index');
                Route::middleware('can:products_create')->get('products/create', [Controllers\ProductController::class, 'create'])->name('products.create');
                Route::middleware('can:products_create')->post('products', [Controllers\ProductController::class, 'store'])->name('products.store');
                Route::middleware('can:products_show')->get('products/{product}/edit', [Controllers\ProductController::class, 'edit'])->name('products.edit');
                Route::middleware('can:products_update')->put('products/{product}', [Controllers\ProductController::class, 'update'])->name('products.update');
                Route::middleware('can:products_delete')->delete('products/{product}', [Controllers\ProductController::class, 'destroy'])->name('products.destroy');

                // 区域组
                Route::middleware('can:regions_index')->get('regions', [Controllers\RegionController::class, 'index'])->name('regions.index');
                Route::middleware('can:regions_create')->post('regions', [Controllers\RegionController::class, 'store'])->name('regions.store');
                Route::middleware('can:regions_update')->put('regions/{id}', [Controllers\RegionController::class, 'update'])->name('regions.update');
                Route::middleware('can:regions_delete')->delete('regions/{id}', [Controllers\RegionController::class, 'destroy'])->name('regions.destroy');

                // RMA
                Route::middleware('can:rmas_update')->post('rmas/history/{id}', [Controllers\RmaController::class, 'addHistory'])->name('rmas.add_history');
                Route::middleware('can:rmas_index')->get('rmas', [Controllers\RmaController::class, 'index'])->name('rmas.index');
                Route::middleware('can:rmas_show')->get('rmas/{id}', [Controllers\RmaController::class, 'show'])->name('rmas.show');
                Route::middleware('can:rmas_delete')->delete('rmas/{id}', [Controllers\RmaController::class, 'destroy'])->name('rmas.destroy');

                Route::middleware('can:rma_reasons_index')->get('rma_reasons', [Controllers\RmaReasonController::class, 'index'])->name('rma_reasons.index');
                Route::middleware('can:rma_reasons_create')->post('rma_reasons', [Controllers\RmaReasonController::class, 'store'])->name('rma_reasons.store');
                Route::middleware('can:rma_reasons_update')->put('rma_reasons/{id}', [Controllers\RmaReasonController::class, 'update'])->name('rma_reasons.update');
                Route::middleware('can:rma_reasons_delete')->delete('rma_reasons/{id}', [Controllers\RmaReasonController::class, 'destroy'])->name('rma_reasons.destroy');

                Route::middleware('can:settings_index')->get('settings', [Controllers\SettingController::class, 'index'])->name('settings.index');
                Route::middleware('can:settings_index')->get('settings/basic', [Controllers\SettingController::class, 'basicSettings'])->name('settings.basic');
                Route::middleware('can:settings_index')->get('settings/store', [Controllers\SettingController::class, 'storeSettings'])->name('settings.store_settings');
                Route::middleware('can:settings_index')->get('settings/checkout', [Controllers\SettingController::class, 'checkoutSettings'])->name('settings.checkout');
                Route::middleware('can:settings_index')->get('settings/picture', [Controllers\SettingController::class, 'pictureSettings'])->name('settings.picture');
                Route::middleware('can:settings_index')->get('settings/policies', [Controllers\SettingController::class, 'policySettings'])->name('settings.policies');
                Route::middleware('can:settings_update')->post('settings/policies/generate', [Controllers\SettingController::class, 'generatePolicies'])->name('settings.policies.generate');
                Route::middleware('can:settings_index')->get('settings/seo', [Controllers\SettingController::class, 'seoSettings'])->name('settings.seo');
                Route::middleware('can:settings_index')->get('settings/express', [Controllers\SettingController::class, 'expressSettings'])->name('settings.express');
                Route::middleware('can:settings_index')->get('settings/mail', [Controllers\SettingController::class, 'mailSettings'])->name('settings.mail');
                Route::middleware(['can:settings_update', 'saas.platform-admin', 'throttle:10,1'])->post('settings/mail/relay', [Controllers\SettingController::class, 'storeMailRelaySettings'])->name('settings.mail.relay.store');
                Route::middleware('can:settings_update')->post('settings', [Controllers\SettingController::class, 'store'])->name('settings.store');
                Route::middleware('can:settings_update')->post('settings/currency/refresh', [Controllers\SettingController::class, 'refreshCurrencyRates'])->name('settings.currency.refresh');
                Route::middleware('can:settings_update')->post('settings/mail/test', [Controllers\SettingController::class, 'testMail'])->name('settings.mail.test');
                Route::middleware(['can:settings_update', 'throttle:10,1'])->post('settings/mail/brevo-verify', [Controllers\SettingController::class, 'verifyBrevoConnection'])->name('settings.mail.brevo-verify');
                Route::middleware(['can:settings_update', 'throttle:5,1'])->post('settings/mail/brevo-webhook', [Controllers\SettingController::class, 'syncBrevoWebhook'])->name('settings.mail.brevo-webhook');
                Route::middleware('can:settings_index')->post('settings/mail/diagnostics', [Controllers\SettingController::class, 'mailDiagnostics'])->name('settings.mail.diagnostics');
                Route::middleware('can:settings_update')->post('settings/mail/dns-sync', [Controllers\SettingController::class, 'syncMailDns'])->name('settings.mail.dns-sync');
                Route::middleware('can:settings_update')->put('settings/values', [Controllers\SettingController::class, 'updateValues'])->name('settings.update_values');
                Route::middleware('can:settings_update')->post('settings/store_token', [Controllers\SettingController::class, 'storeDeveloperToken'])->name('settings.store_token');
                Route::middleware('can:settings_update')->post('settings/fetch_signature_secret', [Controllers\SettingController::class, 'fetchSignatureSecret'])->name('settings.fetch_signature_secret');
                Route::middleware('can:settings_update')->get('settings/signature_secret_status', [Controllers\SettingController::class, 'getSignatureSecretStatus'])->name('settings.signature_secret_status');

                // LONGOS control-plane updater. Controller authorization also
                // requires the root administrator on the verified control host.
                Route::middleware('can:settings_index')->get('system-updates', [Controllers\SystemUpdateController::class, 'index'])->name('system_updates.index');
                Route::middleware(['can:settings_update', 'throttle:12,1'])->post('system-updates/settings', [Controllers\SystemUpdateController::class, 'store'])->name('system_updates.store');
                Route::middleware(['can:settings_index', 'throttle:12,1'])->post('system-updates/check', [Controllers\SystemUpdateController::class, 'check'])->name('system_updates.check');
                Route::middleware(['can:settings_update', 'throttle:3,1'])->post('system-updates/start', [Controllers\SystemUpdateController::class, 'start'])->name('system_updates.start');
                Route::middleware(['can:settings_update', 'throttle:3,1'])->post('system-updates/recover', [Controllers\SystemUpdateController::class, 'recover'])->name('system_updates.recover');
                Route::middleware(['can:settings_index', 'throttle:120,1'])->get('system-updates/status', [Controllers\SystemUpdateController::class, 'status'])->name('system_updates.status');

                // 税类
                Route::middleware('can:tax_classes_index')->get('tax_classes', [Controllers\TaxClassController::class, 'index'])->name('tax_classes.index');
                Route::middleware('can:tax_classes_create')->post('tax_classes', [Controllers\TaxClassController::class, 'store'])->name('tax_classes.store');
                Route::middleware('can:tax_classes_update')->put('tax_classes/{tax_class}', [Controllers\TaxClassController::class, 'update'])->name('tax_classes.update');
                Route::middleware('can:tax_classes_delete')->delete('tax_classes/{tax_class}', [Controllers\TaxClassController::class, 'destroy'])->name('tax_classes.destroy');

                // 税费
                Route::middleware('can:tax_rates_index')->get('tax_rates', [Controllers\TaxRateController::class, 'index'])->name('tax_rates.index');
                Route::middleware('can:tax_rates_create')->post('tax_rates', [Controllers\TaxRateController::class, 'store'])->name('tax_rates.store');
                Route::middleware('can:tax_rates_update')->put('tax_rates/{tax_rate}', [Controllers\TaxRateController::class, 'update'])->name('tax_rates.update');
                Route::middleware('can:tax_rates_delete')->delete('tax_rates/{tax_rate}', [Controllers\TaxRateController::class, 'destroy'])->name('tax_rates.destroy');

                // 后台用户
                Route::middleware('can:admin_users_index')->get('admin_users', [Controllers\AdminUserController::class, 'index'])->name('admin_users.index');
                Route::middleware('can:admin_users_create')->post('admin_users', [Controllers\AdminUserController::class, 'store'])->name('admin_users.store');
                Route::middleware('can:admin_users_update')->put('admin_users/{user_id}', [Controllers\AdminUserController::class, 'update'])->name('admin_users.update');
                Route::middleware('can:admin_users_delete')->delete('admin_users/{user_id}', [Controllers\AdminUserController::class, 'destroy'])->name('admin_users.destroy');

                // 邮件发送记录
                Route::middleware('can:mail_logs_index')->get('mail_logs', [Controllers\MailLogController::class, 'index'])->name('mail_logs.index');
                Route::middleware('can:mail_logs_index')->get('mail_logs/statistics', [Controllers\MailLogController::class, 'statistics'])->name('mail_logs.statistics');
                Route::middleware('can:mail_logs_show')->get('mail_logs/{mailLog}', [Controllers\MailLogController::class, 'show'])->whereNumber('mailLog')->name('mail_logs.show');
                Route::middleware('can:mail_logs_delete')->delete('mail_logs', [Controllers\MailLogController::class, 'destroy'])->name('mail_logs.destroy');
                Route::middleware('can:mail_logs_delete')->post('mail_logs/batch', [Controllers\MailLogController::class, 'batchAction'])->name('mail_logs.batch');
                Route::middleware('can:mail_logs_delete')->post('mail_logs/cleanup', [Controllers\MailLogController::class, 'cleanup'])->name('mail_logs.cleanup');
                Route::middleware('can:mail_logs_update')->post('mail_logs/{mailLog}/resend', [Controllers\MailLogController::class, 'resend'])->name('mail_logs.resend');

                // 后台用户组
                Route::middleware('can:admin_roles_index')->get('admin_roles', [Controllers\AdminRoleController::class, 'index'])->name('admin_roles.index');
                Route::middleware('can:admin_roles_index')->get('admin_roles/list', [Controllers\AdminRoleController::class, 'ajaxList'])->name('admin_roles.list');
                Route::middleware('can:admin_roles_create')->get('admin_roles/create', [Controllers\AdminRoleController::class, 'create'])->name('admin_roles.create');
                Route::middleware('can:admin_roles_create')->post('admin_roles', [Controllers\AdminRoleController::class, 'store'])->name('admin_roles.store');
                Route::middleware('can:admin_roles_show')->get('admin_roles/{role_id}/edit', [Controllers\AdminRoleController::class, 'edit'])->name('admin_roles.edit');
                Route::middleware('can:admin_roles_update')->put('admin_roles/{role_id}', [Controllers\AdminRoleController::class, 'update'])->name('admin_roles.update');
                Route::middleware('can:admin_roles_delete')->delete('admin_roles/{role_id}', [Controllers\AdminRoleController::class, 'destroy'])->name('admin_roles.destroy');
            });
    });
