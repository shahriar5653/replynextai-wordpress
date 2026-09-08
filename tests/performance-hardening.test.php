<?php

// Focused source regression checks for the bounded WooCommerce sync and
// widget-provisioning contention safeguards. These checks intentionally avoid
// bootstrapping WordPress or making network/database calls.
$source = file_get_contents(dirname(__DIR__) . '/includes/class-replynextai-plugin.php');
$checks = array(
    'catalog execution lock' => strpos($source, "CATALOG_LOCK_OPTION") !== false,
    'catalog lock release' => strpos($source, "release_lock(self::CATALOG_LOCK_OPTION") !== false,
    'atomic queue marker' => strpos($source, "CATALOG_QUEUED_OPTION") !== false && strpos($source, "add_option(self::CATALOG_QUEUED_OPTION") !== false,
    'dirty flag' => strpos($source, "CATALOG_DIRTY_TRANSIENT") !== false,
    'bounded product page' => strpos($source, "'limit' => 100") !== false,
    'continuation queue' => strpos($source, "queue_catalog_sync(5)") !== false,
    'manual sync is queued' => strpos($source, "Product synchronization scheduled in the background.") !== false,
    'short catalog timeout' => strpos($source, "'timeout' => 15") !== false,
    'legacy products endpoint retained' => strpos($source, "'/api/wordpress/products'") !== false,
    'catalog endpoint retained' => strpos($source, "'/api/wordpress/catalog'") !== false,
    'provisioning lock' => strpos($source, "PROVISION_LOCK_OPTION") !== false,
    'provisioning lock release' => strpos($source, "release_lock(self::PROVISION_LOCK_OPTION") !== false,
);

foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
}

echo "PASS: performance hardening checks\n";
