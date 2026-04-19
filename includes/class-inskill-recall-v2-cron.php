<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_V2_Cron extends InSkill_Recall_V2_Cron_Notifications {

    public function __construct() {
        add_action(self::EVENT_HOOK, [__CLASS__, 'handle_event']);
        add_action('init', [__CLASS__, 'maybe_realign_schedule'], 20);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
    }

    public static function activate() {
        parent::activate();
    }

    public static function deactivate() {
        parent::deactivate();
    }

    public static function handle_event() {
        parent::run(self::CRON_MODE_WP, [
            'trigger' => 'scheduled_event',
        ]);
    }

    public static function run($source = self::CRON_MODE_WP, array $context = []) {
        parent::run($source, $context);
    }

    public static function maybe_realign_schedule() {
        parent::maybe_realign_schedule();
    }

    public static function register_rest_routes() {
        parent::register_rest_routes();
    }

    public static function handle_external_cron_request(WP_REST_Request $request) {
        return parent::handle_external_cron_request($request);
    }

    public static function sanitize_cron_mode($mode) {
        return parent::sanitize_cron_mode($mode);
    }

    public static function get_cron_mode() {
        return parent::get_cron_mode();
    }

    public static function generate_external_cron_token() {
        return parent::generate_external_cron_token();
    }

    public static function get_external_cron_token() {
        return parent::get_external_cron_token();
    }

    public static function sanitize_external_cron_token($token) {
        return parent::sanitize_external_cron_token($token);
    }

    public static function get_external_cron_endpoint_url() {
        return parent::get_external_cron_endpoint_url();
    }
}