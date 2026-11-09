<?php

// Source-level regression checks for truthful, recoverable manual catalog
// scheduling. No WordPress bootstrap, database writes, or network calls.
$source = file_get_contents(dirname(__DIR__) . '/includes/class-replynextai-plugin.php');
$checks = array(
    'manual sync reports scheduling outcome' => strpos($source, '$scheduled = $this->queue_catalog_sync(1, true)') !== false,
    'manual sync does not claim success on scheduling failure' => strpos($source, 'Product sync could not be scheduled.') !== false,
    'existing event is recognized' => strpos($source, "wp_next_scheduled('replynextai_deferred_product_sync')") !== false,
    'stale marker recovery is manual-retry-only' => strpos($source, 'if (!$recover_stale_queue || get_option(self::CATALOG_LOCK_OPTION, false)) return false;') !== false,
    'cron scheduling failure clears queue marker' => strpos($source, 'delete_option(self::CATALOG_QUEUED_OPTION);') !== false,
);

foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
}

echo "PASS: WooCommerce catalog queue checks\n";
