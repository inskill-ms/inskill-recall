<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_V2_Cron {
    const EVENT_HOOK = 'inskill_recall_v2_hourly_event';
    const DAILY_PREP_OPTION = 'inskill_recall_v2_last_daily_prepare_date';
    const DAILY_CLOSE_OPTION = 'inskill_recall_v2_last_daily_close_date';
    const MIDDAY_OPTION = 'inskill_recall_v2_last_midday_downgrade_date';
    const LOCK_OPTION = 'inskill_recall_v2_cron_lock';

    public function __construct() {
        add_action(self::EVENT_HOOK, [__CLASS__, 'run']);
        add_action('init', [__CLASS__, 'maybe_realign_schedule'], 20);
    }

    public static function activate() {
        self::schedule_at_top_of_hour(true);
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::EVENT_HOOK);
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

    protected static function get_debug_log_path() {
        $upload_dir = wp_upload_dir();
        $base_dir = !empty($upload_dir['basedir']) ? $upload_dir['basedir'] : WP_CONTENT_DIR . '/uploads';

        if (!is_dir($base_dir)) {
            wp_mkdir_p($base_dir);
        }

        return trailingslashit($base_dir) . 'inskill-recall-debug.log';
    }

    protected static function debug_log($channel, array $payload = []) {
        $line = wp_json_encode([
            'time'    => class_exists('InSkill_Recall_Time') ? InSkill_Recall_Time::now_mysql() : current_time('mysql'),
            'channel' => $channel,
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!$line) {
            return;
        }

        @file_put_contents(self::get_debug_log_path(), $line . PHP_EOL, FILE_APPEND);
    }

    protected static function get_user_local_now($user) {
        if (!$user) {
            return null;
        }

        $prefs = InSkill_Recall_Auth::get_notification_preferences($user);
        $timezone = !empty($prefs['timezone']) ? (string) $prefs['timezone'] : InSkill_Recall_Auth::DEFAULT_NOTIFICATION_TIMEZONE;

        try {
            $tz = new DateTimeZone($timezone);
            $now = new DateTimeImmutable(InSkill_Recall_Time::now_mysql(), wp_timezone());

            return $now->setTimezone($tz);
        } catch (Exception $e) {
            self::debug_log('cron_user_local_now_exception', [
                'user_id'  => isset($user->id) ? (int) $user->id : 0,
                'timezone' => $timezone,
                'message'  => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected static function get_daily_notification_decision($user) {
        if (!$user) {
            return [
                'ok'     => false,
                'reason' => 'missing_user',
            ];
        }

        $prefs = InSkill_Recall_Auth::get_notification_preferences($user);
        $now = self::get_user_local_now($user);

        if (!$now) {
            return [
                'ok'     => false,
                'reason' => 'invalid_timezone_or_now',
                'prefs'  => $prefs,
            ];
        }

        $dayOfWeek = (int) $now->format('N');
        $nowMinutes = ((int) $now->format('H')) * 60 + ((int) $now->format('i'));
        $targetMinutes = ((int) $prefs['hour']) * 60 + ((int) $prefs['minute']);

        $result = [
            'ok'               => true,
            'reason'           => 'ok',
            'prefs'            => $prefs,
            'local_now'        => $now->format('Y-m-d H:i:s'),
            'local_date'       => $now->format('Y-m-d'),
            'local_time'       => $now->format('H:i:s'),
            'day_of_week'      => $dayOfWeek,
            'now_minutes'      => $nowMinutes,
            'target_minutes'   => $targetMinutes,
            'last_notified_at' => !empty($user->last_notified_at) ? (string) $user->last_notified_at : null,
            'forced_datetime'  => InSkill_Recall_Time::get_forced_datetime(),
            'wp_now'           => InSkill_Recall_Time::now_mysql(),
            'wp_today'         => InSkill_Recall_V2_Progress_Service::today_date(),
        ];

        if (empty($prefs['allow_weekend']) && $dayOfWeek >= 6) {
            $result['ok'] = false;
            $result['reason'] = 'weekend_blocked';
            return $result;
        }

        if ($nowMinutes < $targetMinutes) {
            $result['ok'] = false;
            $result['reason'] = 'before_target_time';
            return $result;
        }

        if ($nowMinutes > ($targetMinutes + 59)) {
            $result['ok'] = false;
            $result['reason'] = 'outside_notification_window';
            return $result;
        }

        if (!empty($user->last_notified_at)) {
            try {
                $tz = new DateTimeZone((string) $prefs['timezone']);
                $lastTs = InSkill_Recall_Auth::local_mysql_to_timestamp((string) $user->last_notified_at, $tz);

                if ($lastTs !== false) {
                    $lastLocalDate = wp_date('Y-m-d', $lastTs, $tz);
                    $currentLocalDate = $now->format('Y-m-d');

                    $result['last_local_date'] = $lastLocalDate;
                    $result['current_local_date'] = $currentLocalDate;

                    if ($lastLocalDate === $currentLocalDate) {
                        $result['ok'] = false;
                        $result['reason'] = 'already_notified_today';
                        return $result;
                    }
                } else {
                    $result['last_local_date'] = null;
                    $result['current_local_date'] = $now->format('Y-m-d');
                    $result['last_notified_parse_error'] = true;
                }
            } catch (Exception $e) {
                $result['ok'] = false;
                $result['reason'] = 'last_notified_parse_exception';
                $result['exception_message'] = $e->getMessage();
                return $result;
            }
        }

        return $result;
    }

    protected static function should_send_daily_notification_now($user) {
        $decision = self::get_daily_notification_decision($user);
        return !empty($decision['ok']);
    }

    public static function run() {
        if (get_option(self::LOCK_OPTION)) {
            self::debug_log('cron_run_skipped_locked', [
                'lock_option' => self::LOCK_OPTION,
            ]);
            return;
        }

        update_option(self::LOCK_OPTION, 1, false);

        self::debug_log('cron_run_start', [
            'forced_datetime' => InSkill_Recall_Time::get_forced_datetime(),
            'wp_now'          => InSkill_Recall_Time::now_mysql(),
            'wp_today'        => InSkill_Recall_V2_Progress_Service::today_date(),
        ]);

        try {
            self::run_daily_prepare_once();
            self::run_midday_downgrades_once();
            self::send_daily_notifications();
            self::send_downgrade_alert_notifications();

            self::debug_log('cron_run_end', [
                'status' => 'ok',
            ]);
        } catch (Throwable $e) {
            self::debug_log('cron_run_exception', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);

            throw $e;
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }

    protected static function run_daily_prepare_once() {
        $today = InSkill_Recall_V2_Progress_Service::today_date();
        $alreadyRun = (string) get_option(self::DAILY_PREP_OPTION, '');

        if ($alreadyRun === $today) {
            self::debug_log('cron_daily_prepare_skipped', [
                'today'       => $today,
                'already_run' => $alreadyRun,
            ]);
            return;
        }

        self::debug_log('cron_daily_prepare_start', [
            'today' => $today,
        ]);

        InSkill_Recall_V2_Engine::close_pending_occurrences_for_previous_days();
        InSkill_Recall_V2_Engine::prepare_all_due_occurrences_for_today();

        update_option(self::DAILY_PREP_OPTION, $today, false);
        update_option(self::DAILY_CLOSE_OPTION, $today, false);

        self::debug_log('cron_daily_prepare_end', [
            'today' => $today,
        ]);
    }

    protected static function run_midday_downgrades_once() {
        $today = InSkill_Recall_V2_Progress_Service::today_date();
        $alreadyRun = (string) get_option(self::MIDDAY_OPTION, '');

        $currentHourMinute = substr(InSkill_Recall_Time::now_mysql(), 11, 5);

        if ($currentHourMinute < '12:00') {
            self::debug_log('cron_midday_skipped_before_noon', [
                'today'               => $today,
                'current_hour_minute' => $currentHourMinute,
            ]);
            return;
        }

        if ($alreadyRun === $today) {
            self::debug_log('cron_midday_skipped_already_run', [
                'today'       => $today,
                'already_run' => $alreadyRun,
            ]);
            return;
        }

        self::debug_log('cron_midday_start', [
            'today'               => $today,
            'current_hour_minute' => $currentHourMinute,
        ]);

        InSkill_Recall_V2_Engine::run_midday_downgrades($today);
        update_option(self::MIDDAY_OPTION, $today, false);

        self::debug_log('cron_midday_end', [
            'today' => $today,
        ]);
    }

    protected static function get_user_target_url($user) {
        if (!$user) {
            return home_url('/');
        }

        $dashboardUrl = InSkill_Recall_Frontend::get_user_dashboard_url($user);
        if ($dashboardUrl !== '') {
            return $dashboardUrl;
        }

        return home_url('/');
    }

    protected static function get_next_program_alert_date_for_user($user, $today) {
        if (!$user) {
            return InSkill_Recall_V2_Progress_Service::add_days($today, 1);
        }

        return InSkill_Recall_V2_Progress_Service::get_next_program_date($today, $user, false);
    }

    protected static function send_daily_notifications() {
        if (!class_exists('InSkill_Recall_Push') || !class_exists('InSkill_Recall_Auth')) {
            self::debug_log('cron_daily_notifications_skipped_missing_classes', [
                'has_push' => class_exists('InSkill_Recall_Push'),
                'has_auth' => class_exists('InSkill_Recall_Auth'),
            ]);
            return;
        }

        global $wpdb;

        $today = InSkill_Recall_V2_Progress_Service::today_date();
        $groups = InSkill_Recall_V2_Engine::get_active_groups();

        self::debug_log('cron_daily_notifications_start', [
            'today'        => $today,
            'groups_count' => is_array($groups) ? count($groups) : 0,
        ]);

        foreach ($groups as $group) {
            $members = InSkill_Recall_V2_Engine::get_group_members((int) $group->id);

            self::debug_log('cron_daily_notifications_group', [
                'group_id'      => (int) $group->id,
                'group_name'    => isset($group->name) ? (string) $group->name : '',
                'members_count' => is_array($members) ? count($members) : 0,
            ]);

            foreach ($members as $member) {
                $user = InSkill_Recall_Auth::get_user((int) $member->id);
                if (!$user) {
                    self::debug_log('cron_daily_notifications_member_skipped_missing_user', [
                        'group_id'  => (int) $group->id,
                        'member_id' => isset($member->id) ? (int) $member->id : 0,
                    ]);
                    continue;
                }

                $hasPendingToday = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM " . InSkill_Recall_DB::table('question_occurrences') . "
                     WHERE group_id = %d
                       AND recall_user_id = %d
                       AND scheduled_date = %s
                       AND status = 'pending'",
                    (int) $group->id,
                    (int) $member->id,
                    $today
                ));

                $decision = self::get_daily_notification_decision($user);

                self::debug_log('cron_daily_notifications_member_check', [
                    'group_id'          => (int) $group->id,
                    'group_name'        => isset($group->name) ? (string) $group->name : '',
                    'user_id'           => (int) $member->id,
                    'user_email'        => !empty($user->email) ? (string) $user->email : '',
                    'has_pending_today' => $hasPendingToday,
                    'decision'          => $decision,
                ]);

                if ($hasPendingToday <= 0) {
                    self::debug_log('cron_daily_notifications_member_skipped', [
                        'group_id' => (int) $group->id,
                        'user_id'  => (int) $member->id,
                        'reason'   => 'no_pending_today',
                    ]);
                    continue;
                }

                if (empty($decision['ok'])) {
                    self::debug_log('cron_daily_notifications_member_skipped', [
                        'group_id' => (int) $group->id,
                        'user_id'  => (int) $member->id,
                        'reason'   => isset($decision['reason']) ? (string) $decision['reason'] : 'unknown',
                        'decision' => $decision,
                    ]);
                    continue;
                }

                $payload = [
                    'title' => 'InSkill Recall',
                    'body'  => 'Vos questions du jour sont disponibles.',
                    'url'   => self::get_user_target_url($user),
                    'tag'   => 'inskill-recall-daily-' . (int) $group->id . '-' . (int) $member->id,
                ];

                $sent = InSkill_Recall_Push::send_to_user((int) $member->id, $payload);

                self::debug_log('cron_daily_notifications_member_send', [
                    'group_id' => (int) $group->id,
                    'user_id'  => (int) $member->id,
                    'sent'     => (bool) $sent,
                    'payload'  => $payload,
                ]);

                self::log_notification(
                    (int) $member->id,
                    (int) $group->id,
                    'daily_routine',
                    $payload,
                    $sent ? 'sent' : 'error',
                    $sent ? null : 'push_send_failed'
                );
            }
        }

        self::debug_log('cron_daily_notifications_end', [
            'today' => $today,
        ]);
    }

    protected static function send_downgrade_alert_notifications() {
        if (!class_exists('InSkill_Recall_Push') || !class_exists('InSkill_Recall_Auth')) {
            self::debug_log('cron_downgrade_notifications_skipped_missing_classes', [
                'has_push' => class_exists('InSkill_Recall_Push'),
                'has_auth' => class_exists('InSkill_Recall_Auth'),
            ]);
            return;
        }

        global $wpdb;

        $today = InSkill_Recall_V2_Progress_Service::today_date();

        $rows = $wpdb->get_results(
            "SELECT DISTINCT group_id, recall_user_id, downgrade_on_date
             FROM " . InSkill_Recall_DB::table('user_question_progress') . "
             WHERE downgrade_on_date IS NOT NULL
               AND current_level NOT IN ('nv0', 'mastered')"
        );

        self::debug_log('cron_downgrade_notifications_start', [
            'today'      => $today,
            'rows_count' => is_array($rows) ? count($rows) : 0,
        ]);

        foreach ($rows as $row) {
            $user = InSkill_Recall_Auth::get_user((int) $row->recall_user_id);
            if (!$user) {
                self::debug_log('cron_downgrade_notifications_row_skipped_missing_user', [
                    'group_id' => (int) $row->group_id,
                    'user_id'  => (int) $row->recall_user_id,
                ]);
                continue;
            }

            $targetDate = self::get_next_program_alert_date_for_user($user, $today);

            if ((string) $row->downgrade_on_date !== (string) $targetDate) {
                self::debug_log('cron_downgrade_notifications_row_skipped', [
                    'group_id'          => (int) $row->group_id,
                    'user_id'           => (int) $row->recall_user_id,
                    'downgrade_on_date' => (string) $row->downgrade_on_date,
                    'target_date'       => (string) $targetDate,
                    'reason'            => 'target_date_mismatch',
                ]);
                continue;
            }

            $payload = [
                'title' => 'InSkill Recall',
                'body'  => 'Certaines questions risquent de rétrograder demain. Pensez à les revoir.',
                'url'   => self::get_user_target_url($user),
                'tag'   => 'inskill-recall-downgrade-' . (int) $row->group_id . '-' . (int) $row->recall_user_id,
            ];

            $sent = InSkill_Recall_Push::send_to_user((int) $row->recall_user_id, $payload);

            self::debug_log('cron_downgrade_notifications_row_send', [
                'group_id' => (int) $row->group_id,
                'user_id'  => (int) $row->recall_user_id,
                'sent'     => (bool) $sent,
                'payload'  => $payload,
            ]);

            self::log_notification(
                (int) $row->recall_user_id,
                (int) $row->group_id,
                'downgrade_alert',
                $payload,
                $sent ? 'sent' : 'error',
                $sent ? null : 'push_send_failed'
            );
        }

        self::debug_log('cron_downgrade_notifications_end', [
            'today' => $today,
        ]);
    }

    protected static function log_notification($recall_user_id, $group_id, $type, array $payload, $status = 'sent', $error_message = null) {
        global $wpdb;

        $wpdb->insert(
            InSkill_Recall_DB::table('notification_logs'),
            [
                'recall_user_id'    => (int) $recall_user_id,
                'group_id'          => $group_id ? (int) $group_id : null,
                'notification_type' => sanitize_key($type),
                'title'             => isset($payload['title']) ? sanitize_text_field((string) $payload['title']) : '',
                'body'              => isset($payload['body']) ? sanitize_textarea_field((string) $payload['body']) : '',
                'payload_json'      => wp_json_encode($payload),
                'sent_at'           => current_time('mysql'),
                'status'            => $status === 'error' ? 'error' : 'sent',
                'error_message'     => $error_message ? sanitize_textarea_field((string) $error_message) : null,
                'created_at'        => current_time('mysql'),
            ]
        );
    }
}