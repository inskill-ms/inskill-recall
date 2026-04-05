<?php
/**
 * Uninstall InSkill Recall
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/class-inskill-recall-db.php';

$options = [
    'inskill_recall_db_version',
    'inskill_recall_dashboard_page_id',
    'inskill_recall_vapid_subject',
    'inskill_recall_vapid_public_key',
    'inskill_recall_vapid_private_key',
    'inskill_recall_allowed_timezones',
];

foreach ($options as $option_name) {
    delete_option($option_name);
    delete_site_option($option_name);
}

$page_id = (int) get_option('inskill_recall_dashboard_page_id', 0);
if ($page_id > 0) {
    wp_delete_post($page_id, true);
}

InSkill_Recall_DB::drop_tables();