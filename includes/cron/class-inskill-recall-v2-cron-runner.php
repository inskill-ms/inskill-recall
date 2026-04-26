<?php
if (!defined('ABSPATH')) {
    exit;
}

abstract class InSkill_Recall_V2_Cron_Runner extends InSkill_Recall_V2_Cron_Decisions {
    public static function run($source = self::CRON_MODE_WP, array $context = []) {
        $source = self::normalize_trigger_source($source);

        $runId = isset($context['ts']) ? trim((string) $context['ts']) : '';
        if ($runId === '') {
            $runId = (string) time();
        }

        $context['ts'] = $runId;

        self::set_debug_log_context([
            'ts' => $runId,
        ]);

        try {
            if (!self::is_trigger_source_allowed($source)) {
                self::log_trigger_decision($source, false, array_merge([
                    'reason' => 'mode_mismatch',
                ], $context));
                return;
            }

            self::log_trigger_decision($source, true, $context);

            if (get_option(self::LOCK_OPTION)) {
                self::debug_log('cron_run_skipped_locked', [
                    'lock_option' => self::LOCK_OPTION,
                    'source'      => $source,
                ]);
                return;
            }

            update_option(self::LOCK_OPTION, 1, false);

            self::debug_log('cron_run_start', array_merge([
                'source'          => $source,
                'mode'            => self::get_cron_mode(),
                'forced_datetime' => InSkill_Recall_Time::get_forced_datetime(),
                'wp_now'          => InSkill_Recall_Time::now_mysql(),
                'wp_today'        => InSkill_Recall_V2_Progress_Service::today_date(),
            ], $context));

            try {
                self::run_daily_prepare_once();
                self::run_midday_downgrades_once();

                static::send_daily_notifications();
                static::send_downgrade_alert_notifications();

                self::debug_log('cron_run_end', [
                    'status' => 'ok',
                    'source' => $source,
                    'mode'   => self::get_cron_mode(),
                ]);
            } catch (Throwable $e) {
                self::debug_log('cron_run_exception', [
                    'source'  => $source,
                    'mode'    => self::get_cron_mode(),
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'trace'   => $e->getTraceAsString(),
                ]);

                throw $e;
            } finally {
                delete_option(self::LOCK_OPTION);
            }
        } finally {
            self::clear_debug_log_context();
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

        InSkill_Recall_V2_Engine::run_midday_downgrades();
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

    abstract protected static function send_daily_notifications();

    abstract protected static function send_downgrade_alert_notifications();
}