<?php

namespace Plugin\ShopifyProductTransfer;

class Bootstrap
{
    public function boot(): void
    {
        add_hook_blade('admin.product.list.content.footer', function ($callback, $output, $data) {
            return $output . view('ShopifyProductTransfer::admin.actions')->render();
        });
    }
}
