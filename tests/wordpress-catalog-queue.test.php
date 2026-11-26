<?php

// Source-level regression checks for truthful, recoverable manual catalog
// scheduling. No WordPress bootstrap, database writes, or network calls.
$source = file_get_contents(dirname(__DIR__) . '/includes/class-replynextai-plugin.php');
$checks = array(
    'manual AJAX executes the shared worker' => strpos($source, '$this->sync_products(true, $marker)') !== false,
    'automatic sync still uses cron' => strpos($source, 'if (!$manual && !$this->queue_catalog_sync(5))') !== false,
    'existing event is recognized' => strpos($source, "wp_next_scheduled('replynextai_deferred_product_sync')") !== false,
    'only inactive orphaned markers can be recovered manually' => strpos($source, 'if (!$recover_stale_queue || $this->catalog_worker_running() || $this->catalog_queue_claim_pending()) return false;') !== false,
    'cron scheduling failure clears queue marker' => strpos($source, 'delete_option(self::CATALOG_QUEUED_OPTION);') !== false,
);

foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
}

echo "PASS: WooCommerce catalog queue checks\n";
