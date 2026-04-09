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
    }

    public static function activate() {
        if (!wp_next_scheduled(self::EVENT_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::EVENT_HOOK);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::EVENT_HOOK);
    }

    public static function run() {
        if (get_option(self::LOCK_OPTION)) {
            return;
        }

        update_option(self::LOCK_OPTION, 1, false);

        try {
            self::run_daily_prepare_once();
            self::run_midday_downgrades_once();
            self::send_daily_notifications();
            self::send_downgrade_alert_notifications();
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }

    protected static function run_daily_prepare_once() {
        $today = InSkill_Recall_V2_Progress_Service::today_date();
        $alreadyRun = (string) get_option(self::DAILY_PREP_OPTION, '');

        if ($alreadyRun === $today) {
            return;
        }

        InSkill_Recall_V2_Engine::close_pending_occurrences_for_previous_days();
        InSkill_Recall_V2_Engine::prepare_all_due_occurrences_for_today();

        update_option(self::DAILY_PREP_OPTION, $today, false);
        update_option(self::DAILY_CLOSE_OPTION, $today, false);
    }

    protected static function run_midday_downgrades_once() {
        $today = InSkill_Recall_V2_Progress_Service::today_date();
        $alreadyRun = (string) get_option(self::MIDDAY_OPTION, '');

        $currentHourMinute = substr(InSkill_Recall_Time::now_mysql(), 11, 5);

        if ($currentHourMinute < '12:00') {
            return;
        }

        if ($alreadyRun === $today) {
            return;
        }

        InSkill_Recall_V2_Engine::run_midday_downgrades($today);
        update_option(self::MIDDAY_OPTION, $today, false);
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

    protected static function should_send_daily_notification_now($user) {
        if (!$user) {
            return false;
        }

        $prefs = InSkill_Recall_Auth::get_notification_preferences($user);
        $timezone = !empty($prefs['timezone']) ? (string) $prefs['timezone'] : InSkill_Recall_Auth::DEFAULT_NOTIFICATION_TIMEZONE;

        try {
            $tz = new DateTimeZone($timezone);
            $now = new DateTimeImmutable(InSkill_Recall_Time::now_mysql(), wp_timezone());
            $now = $now->setTimezone($tz);
        } catch (Exception $e) {
            return false;
        }

        $dayOfWeek = (int) $now->format('N');
        if (empty($prefs['allow_weekend']) && $dayOfWeek >= 6) {
            return false;
        }

        $nowMinutes = ((int) $now->format('H')) * 60 + ((int) $now->format('i'));
        $targetMinutes = ((int) $prefs['hour']) * 60 + ((int) $prefs['minute']);

        if ($nowMinutes < $targetMinutes || $nowMinutes > ($targetMinutes + 59)) {
            return false;
        }

        if (!empty($user->last_notified_at)) {
            $lastTs = InSkill_Recall_Auth::local_mysql_to_timestamp((string) $user->last_notified_at, $tz);
            if ($lastTs !== false) {
                $lastLocalDate = wp_date('Y-m-d', $lastTs, $tz);
                $currentLocalDate = $now->format('Y-m-d');

                if ($lastLocalDate === $currentLocalDate) {
                    return false;
                }
            }
        }

        return true;
    }

    protected static function get_next_program_alert_date_for_user($user, $today) {
        if (!$user) {
            return InSkill_Recall_V2_Progress_Service::add_days($today, 1);
        }

        return InSkill_Recall_V2_Progress_Service::get_next_program_date($today, $user, false);
    }

    protected static function send_daily_notifications() {
        if (!class_exists('InSkill_Recall_Push') || !class_exists('InSkill_Recall_Auth')) {
            return;
        }

        global $wpdb;

        $today = InSkill_Recall_V2_Progress_Service::today_date();
        $groups = InSkill_Recall_V2_Engine::get_active_groups();

        foreach ($groups as $group) {
            $members = InSkill_Recall_V2_Engine::get_group_members((int) $group->id);

            foreach ($members as $member) {
                $user = InSkill_Recall_Auth::get_user((int) $member->id);
                if (!$user) {
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

                if ($hasPendingToday <= 0) {
                    continue;
                }

                if (!self::should_send_daily_notification_now($user)) {
                    continue;
                }

                $payload = [
                    'title' => 'InSkill Recall',
                    'body'  => 'Vos questions du jour sont disponibles.',
                    'url'   => self::get_user_target_url($user),
                    'tag'   => 'inskill-recall-daily-' . (int) $group->id . '-' . (int) $member->id,
                ];

                $sent = InSkill_Recall_Push::send_to_user((int) $member->id, $payload);

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
    }

    protected static function send_downgrade_alert_notifications() {
        if (!class_exists('InSkill_Recall_Push') || !class_exists('InSkill_Recall_Auth')) {
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

        foreach ($rows as $row) {
            $user = InSkill_Recall_Auth::get_user((int) $row->recall_user_id);
            if (!$user) {
                continue;
            }

            $targetDate = self::get_next_program_alert_date_for_user($user, $today);
            if ((string) $row->downgrade_on_date !== (string) $targetDate) {
                continue;
            }

            $payload = [
                'title' => 'InSkill Recall',
                'body'  => 'Certaines questions risquent de rétrograder demain. Pensez à les revoir.',
                'url'   => self::get_user_target_url($user),
                'tag'   => 'inskill-recall-downgrade-' . (int) $row->group_id . '-' . (int) $row->recall_user_id,
            ];

            $sent = InSkill_Recall_Push::send_to_user((int) $row->recall_user_id, $payload);

            self::log_notification(
                (int) $row->recall_user_id,
                (int) $row->group_id,
                'downgrade_alert',
                $payload,
                $sent ? 'sent' : 'error',
                $sent ? null : 'push_send_failed'
            );
        }
    }

    protected static function log_notification($recall_user_id, $group_id, $type, array $payload, $status = 'sent', $error_message = null) {
        global $wpdb;

        $wpdb->insert(
            InSkill_Recall_DB::table('notification_logs'),
            [
                'recall_user_id'    => (int) $recall_user_id,
                'group_id'          => $group_id ? (int) $group_id : null,
                'notification_type' => (string) $type,
                'title'             => isset($payload['title']) ? (string) $payload['title'] : 'InSkill Recall',
                'body'              => isset($payload['body']) ? (string) $payload['body'] : '',
                'payload_json'      => wp_json_encode($payload),
                'sent_at'           => InSkill_Recall_Time::now_mysql(),
                'status'            => (string) $status,
                'error_message'     => $error_message,
                'created_at'        => InSkill_Recall_Time::now_mysql(),
            ]
        );
    }
}