<?php

$source = file_get_contents(__DIR__ . '/../includes/class-replynextai-plugin.php');

function expect_source($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

expect_source(false !== strpos($source, '$widget_id = $this->provision_widget($options);'), 'test connection provisions a widget after authentication');
expect_source(false !== strpos($source, "if (\$widget_id) update_option('replynextai_widget_id', \$widget_id, false);"), 'successful provisioning stores the opaque widget ID');
expect_source(false !== strpos($source, "} else {\n                \$success = false;"), 'provisioning failure blocks connection success');
expect_source(false !== strpos($source, "'widget_error'"), 'provisioning failures surface a sanitized connection notice');
expect_source(false !== strpos($source, "'WORDPRESS_WIDGET_REVOKED'"), 'reported revoked widget registrations fail closed');
expect_source(false !== strpos($source, "if (!\$widget_id) \$widget_id = \$this->provision_widget(\$config);"), 'frontend recovery provisioning remains available');
expect_source(false !== strpos($source, "if ('1' === \$o['ai_enabled'] && '1' === \$o['ai_sitewide']) \$this->enqueue_ai(\$o);"), 'AI chat remains separately gated by existing display settings');
expect_source(false !== strpos($source, 'data-widget-id'), 'frontend emits only the opaque widget ID');
expect_source(false === strpos($source, 'data-company-id'), 'no browser company ID authority is emitted');
expect_source(false !== strpos($source, "\$this->field('server_url'"), 'AI Website Chat settings expose the configured server URL');
expect_source(false !== strpos($source, 'WordPress connection token'), 'AI Website Chat settings expose the secure connection token field');
expect_source(false === strpos($source, "\$this->field('company_id'"), 'Company ID is no longer a merchant-facing AI Website Chat setting');
expect_source(false !== strpos($source, "\$output['company_id'] = \$current['company_id'];"), 'Company mapping is preserved internally and is not writable through settings forms');
expect_source(false !== strpos($source, '$ai_configured = !empty($o[\'api_token\']) && !empty($widget_id);'), 'Help page derives AI chat state from the secure connection rather than Company ID');
expect_source(false !== strpos($source, '$contact_configured ='), 'Help page derives Contact Channels examples from saved channel configuration');
expect_source(false !== strpos($source, 'Demo example'), 'Help page provides clearly labelled examples before configuration');

echo "PASS: WordPress connection provisioning lifecycle source checks\n";
