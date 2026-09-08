<?php

$source = file_get_contents(__DIR__ . '/../includes/class-replynextai-plugin.php');

function expect_woo_source($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

expect_woo_source(false !== strpos($source, "'/api/wordpress/catalog'"), 'WooCommerce sends a compact discovery index through the authenticated WordPress connection');
expect_woo_source(false !== strpos($source, "register_rest_route('replynextai/v1', '/product-verify'"), 'the plugin exposes the signed live verification endpoint');
expect_woo_source(false !== strpos($source, "hash_hmac('sha256'"), 'live verification authenticates the ReplyNext server with HMAC');
expect_woo_source(false !== strpos($source, "hash('sha256', \$o['api_token'])"), 'the signing key is derived from the existing connection token and never exposed');
expect_woo_source(false !== strpos($source, "wc_get_product(\$product_id)"), 'live verification resolves exact WooCommerce products server-side');
expect_woo_source(false !== strpos($source, "array_slice(\$product->get_children(), 0, 100)"), 'variation results are bounded');
expect_woo_source(false === strpos($source, 'get_stock_quantity'), 'exact inventory quantities are not returned');
expect_woo_source(false !== strpos($source, "'/api/wordpress/products'"), 'legacy product-copy sync remains intact during the transition');

echo "PASS: WooCommerce compact index and live verification source checks\n";
