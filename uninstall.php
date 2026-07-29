<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('replynextai_options');
delete_option('replynextai_chat_options');
