<?php
if (!defined('ABSPATH')) {
    exit;
}

abstract class InSkill_Recall_V2_Cron_Base {
    const EVENT_HOOK = 'inskill_recall_v2_hourly_event';
    const DAILY_PREP_OPTION = 'inskill_recall_v2_last_daily_prepare_date';
    const DAILY_CLOSE_OPTION = 'inskill_recall_v2_last_daily_close_date';
    const MIDDAY_OPTION = 'inskill_recall_v2_last_midday_downgrade_date';
    const LOCK_OPTION = 'inskill_recall_v2_cron_lock';

    const DAILY_WINDOW_EARLY_MINUTES = 5;
    const DAILY_WINDOW_LATE_MINUTES = 59;

    const DOWNGRADE_ALERT_HOUR = 18;
    const DOWNGRADE_ALERT_MINUTE = 0;
    const DOWNGRADE_WINDOW_EARLY_MINUTES = 5;
    const DOWNGRADE_WINDOW_LATE_MINUTES = 59;

    const CRON_MODE_OPTION = 'inskill_recall_cron_mode';
    const CRON_TOKEN_OPTION = 'inskill_recall_cron_token';

    const CRON_MODE_WP = 'wp_cron';
    const CRON_MODE_EXTERNAL_VPS = 'external_vps';

    const REST_NAMESPACE = 'inskill-recall/v1';
    const REST_ROUTE_CRON = '/cron';

    const DEBUG_LOG_MAX_BYTES = 5242880;
    const DEBUG_LOG_MAX_ROTATED_FILES = 3;

    protected static $debug_log_context = [];

    public static function activate() {
        self::schedule_at_top_of_hour(true);

        if (get_option(self::CRON_MODE_OPTION, null) === null) {
            update_option(self::CRON_MODE_OPTION, self::CRON_MODE_WP, false);
        }

        if (get_option(self::CRON_TOKEN_OPTION, null) === null) {
            update_option(self::CRON_TOKEN_OPTION, wp_generate_password(64, false, false), false);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::EVENT_HOOK);
        delete_option(self::LOCK_OPTION);
    }

    public static function maybe_realign_schedule() {
        $next = wp_next_scheduled(self::EVENT_HOOK);

        if (!$next) {
            self::schedule_at_top_of_hour(true);
            return;
        }

        try {
            $wpTz = wp_timezone();
            $dt = (new DateTimeImmutable('@' . (int) $next))->setTimezone($wpTz);
            $minute = (int) $dt->format('i');
            $second = (int) $dt->format('s');

            if ($minute !== 0 || $second !== 0) {
                self::schedule_at_top_of_hour(true);
            }
        } catch (Exception $e) {
            self::schedule_at_top_of_hour(true);
        }
    }

    protected static function schedule_at_top_of_hour($force_reschedule = false) {
        if ($force_reschedule) {
            wp_clear_scheduled_hook(self::EVENT_HOOK);
        } elseif (wp_next_scheduled(self::EVENT_HOOK)) {
            return;
        }

        $timestamp = self::get_next_top_of_hour_timestamp();
        wp_schedule_event($timestamp, 'hourly', self::EVENT_HOOK);
    }

    protected static function get_next_top_of_hour_timestamp() {
        try {
            $now = current_datetime();
            $next = $now->setTime((int) $now->format('H'), 0, 0);

            if ((int) $now->format('i') !== 0 || (int) $now->format('s') !== 0) {
                $next = $next->modify('+1 hour');
            } else {
                $next = $next->modify('+1 hour');
            }

            return $next->getTimestamp();
        } catch (Exception $e) {
            $fallback = time();
            $remainder = $fallback % HOUR_IN_SECONDS;
            return $fallback + (HOUR_IN_SECONDS - $remainder);
        }
    }

    public static function get_available_cron_modes() {
        return [
            self::CRON_MODE_WP => 'wp_cron',
            self::CRON_MODE_EXTERNAL_VPS => 'external_vps',
        ];
    }

    public static function get_cron_mode() {
        $mode = (string) get_option(self::CRON_MODE_OPTION, self::CRON_MODE_WP);

        if (!isset(self::get_available_cron_modes()[$mode])) {
            return self::CRON_MODE_WP;
        }

        return $mode;
    }

    public static function sanitize_cron_mode($mode) {
        $mode = sanitize_key((string) $mode);

        if (!isset(self::get_available_cron_modes()[$mode])) {
            return self::CRON_MODE_WP;
        }

        return $mode;
    }

    public static function generate_external_cron_token() {
        return wp_generate_password(64, false, false);
    }

    public static function get_external_cron_token() {
        $token = (string) get_option(self::CRON_TOKEN_OPTION, '');

        if ($token === '') {
            $token = self::generate_external_cron_token();
            update_option(self::CRON_TOKEN_OPTION, $token, false);
        }

        return $token;
    }

    public static function sanitize_external_cron_token($token) {
        $token = preg_replace('/[^a-zA-Z0-9]/', '', (string) $token);
        $token = (string) $token;

        if ($token === '') {
            return self::get_external_cron_token();
        }

        return $token;
    }

    public static function get_external_cron_endpoint_url() {
        return rest_url(self::REST_NAMESPACE . self::REST_ROUTE_CRON);
    }

    public static function register_rest_routes() {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE_CRON, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [static::class, 'handle_external_cron_request'],
            'permission_callback' => '__return_true',
        ]);
    }

    protected static function make_no_cache_rest_response(array $data, $status = 200) {
        $response = new WP_REST_Response($data, (int) $status);

        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('Expires', '0');

        return $response;
    }

    protected static function normalize_trigger_source($source) {
        $source = sanitize_key((string) $source);

        if ($source === self::CRON_MODE_EXTERNAL_VPS) {
            return self::CRON_MODE_EXTERNAL_VPS;
        }

        return self::CRON_MODE_WP;
    }

    protected static function is_trigger_source_allowed($source) {
        return self::get_cron_mode() === self::normalize_trigger_source($source);
    }

    protected static function set_debug_log_context(array $context = []) {
        self::$debug_log_context = $context;
    }

    protected static function clear_debug_log_context() {
        self::$debug_log_context = [];
    }

    protected static function log_trigger_decision($source, $allowed, array $context = []) {
        self::debug_log('cron_trigger', array_merge([
            'source'  => self::normalize_trigger_source($source),
            'allowed' => (bool) $allowed,
            'mode'    => self::get_cron_mode(),
        ], $context));
    }

    public static function handle_external_cron_request(WP_REST_Request $request) {
        $providedToken = (string) $request->get_param('token');
        $expectedToken = self::get_external_cron_token();
        $mode = self::get_cron_mode();

        if ($mode !== self::CRON_MODE_EXTERNAL_VPS) {
            self::log_trigger_decision(self::CRON_MODE_EXTERNAL_VPS, false, [
                'reason' => 'mode_mismatch',
                'mode'   => $mode,
            ]);

            return self::make_no_cache_rest_response([
                'ok'      => true,
                'status'  => 'ignored',
                'message' => 'Cron externe ignoré : mode inactif.',
                'mode'    => $mode,
            ], 200);
        }

        if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            self::log_trigger_decision(self::CRON_MODE_EXTERNAL_VPS, false, [
                'reason' => 'invalid_token',
            ]);

            return self::make_no_cache_rest_response([
                'ok'      => false,
                'status'  => 'forbidden',
                'message' => 'Token invalide.',
            ], 403);
        }

        static::run(self::CRON_MODE_EXTERNAL_VPS, [
            'trigger' => 'rest_endpoint',
            'ts'      => (string) $request->get_param('ts'),
        ]);

        return self::make_no_cache_rest_response([
            'ok'      => true,
            'status'  => 'executed',
            'message' => 'Cron externe exécuté.',
            'mode'    => $mode,
            'now'     => InSkill_Recall_Time::now_mysql(),
        ], 200);
    }

    protected static function get_debug_log_path() {
        $upload_dir = wp_upload_dir();
        $base_dir = !empty($upload_dir['basedir']) ? $upload_dir['basedir'] : WP_CONTENT_DIR . '/uploads';

        if (!is_dir($base_dir)) {
            wp_mkdir_p($base_dir);
        }

        return trailingslashit($base_dir) . 'inskill-recall-debug.log';
    }

    protected static function rotate_debug_log_if_needed($path) {
        if (!is_string($path) || $path === '' || !file_exists($path)) {
            return;
        }

        $size = @filesize($path);
        if ($size === false || $size < self::DEBUG_LOG_MAX_BYTES) {
            return;
        }

        $max = (int) self::DEBUG_LOG_MAX_ROTATED_FILES;

        if ($max < 1) {
            @unlink($path);
            return;
        }

        $oldest = $path . '.' . $max;
        if (file_exists($oldest)) {
            @unlink($oldest);
        }

        for ($i = $max - 1; $i >= 1; $i--) {
            $source = $path . '.' . $i;
            $target = $path . '.' . ($i + 1);

            if (file_exists($source)) {
                @rename($source, $target);
            }
        }

        @rename($path, $path . '.1');
    }

    protected static function debug_log($channel, array $payload = []) {
        if (!empty(self::$debug_log_context)) {
            $payload = array_merge(self::$debug_log_context, $payload);
        }

        $testModeEnabled = class_exists('InSkill_Recall_Time')
            ? InSkill_Recall_Time::is_test_mode_enabled()
            : false;

        $forcedDatetime = class_exists('InSkill_Recall_Time')
            ? InSkill_Recall_Time::get_forced_datetime()
            : '';

        $line = wp_json_encode([
            'time'              => class_exists('InSkill_Recall_Time') ? InSkill_Recall_Time::now_mysql() : current_time('mysql'),
            'real_time'         => current_time('mysql'),
            'simulated_time'    => $testModeEnabled ? $forcedDatetime : null,
            'test_time_enabled' => (bool) $testModeEnabled,
            'channel'           => (string) $channel,
            'payload'           => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!$line) {
            return;
        }

        $path = self::get_debug_log_path();

        self::rotate_debug_log_if_needed($path);

        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND);
    }
}