<?php
if (!defined('ABSPATH')) {
    exit;
}

abstract class InSkill_Recall_V2_Cron_Decisions extends InSkill_Recall_V2_Cron_Base {
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

    protected static function clamp_minutes_of_day($minutes) {
        $minutes = (int) $minutes;

        if ($minutes < 0) {
            return 0;
        }

        if ($minutes > 1439) {
            return 1439;
        }

        return $minutes;
    }

    protected static function has_sent_notification_type_on_local_date($user, $notification_type, $group_id, $local_date) {
        global $wpdb;

        if (!$user) {
            return false;
        }

        $notification_type = sanitize_key((string) $notification_type);
        $local_date = trim((string) $local_date);
        $group_id = (int) $group_id;

        if ($notification_type === '' || $local_date === '') {
            return false;
        }

        $prefs = InSkill_Recall_Auth::get_notification_preferences($user);
        $timezone = !empty($prefs['timezone']) ? (string) $prefs['timezone'] : InSkill_Recall_Auth::DEFAULT_NOTIFICATION_TIMEZONE;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT sent_at
             FROM " . InSkill_Recall_DB::table('notification_logs') . "
             WHERE recall_user_id = %d
               AND group_id = %d
               AND notification_type = %s
               AND status = 'sent'",
            (int) $user->id,
            $group_id,
            $notification_type
        ));

        if (empty($rows)) {
            return false;
        }

        try {
            $tz = new DateTimeZone($timezone);
        } catch (Exception $e) {
            self::debug_log('cron_notification_logs_timezone_exception', [
                'user_id'  => (int) $user->id,
                'group_id' => $group_id,
                'type'     => $notification_type,
                'timezone' => $timezone,
                'message'  => $e->getMessage(),
            ]);

            return false;
        }

        foreach ($rows as $row) {
            if (empty($row->sent_at)) {
                continue;
            }

            $sentTs = InSkill_Recall_Auth::local_mysql_to_timestamp((string) $row->sent_at, wp_timezone());
            if ($sentTs === false) {
                continue;
            }

            $sentLocalDate = wp_date('Y-m-d', $sentTs, $tz);
            if ($sentLocalDate === $local_date) {
                return true;
            }
        }

        return false;
    }

    protected static function get_downgrade_notification_decision($user, $group_id) {
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
        $targetMinutes = (self::DOWNGRADE_ALERT_HOUR * 60) + self::DOWNGRADE_ALERT_MINUTE;
        $windowStartMinutes = self::clamp_minutes_of_day($targetMinutes - self::DOWNGRADE_WINDOW_EARLY_MINUTES);
        $windowEndMinutes = self::clamp_minutes_of_day($targetMinutes + self::DOWNGRADE_WINDOW_LATE_MINUTES);

        $result = [
            'ok'                   => true,
            'reason'               => 'ok',
            'prefs'                => $prefs,
            'group_id'             => (int) $group_id,
            'local_now'            => $now->format('Y-m-d H:i:s'),
            'local_date'           => $now->format('Y-m-d'),
            'day_of_week'          => $dayOfWeek,
            'now_minutes'          => $nowMinutes,
            'target_minutes'       => $targetMinutes,
            'window_start_minutes' => $windowStartMinutes,
            'window_end_minutes'   => $windowEndMinutes,
        ];

        if (empty($prefs['allow_weekend']) && $dayOfWeek >= 6) {
            $result['ok'] = false;
            $result['reason'] = 'weekend_blocked';
            return $result;
        }

        if ($nowMinutes < $windowStartMinutes) {
            $result['ok'] = false;
            $result['reason'] = 'before_target_time';
            return $result;
        }

        if ($nowMinutes > $windowEndMinutes) {
            $result['ok'] = false;
            $result['reason'] = 'outside_alert_window';
            return $result;
        }

        if (self::has_sent_notification_type_on_local_date($user, 'downgrade_alert', (int) $group_id, $now->format('Y-m-d'))) {
            $result['ok'] = false;
            $result['reason'] = 'already_sent_today';
            return $result;
        }

        return $result;
    }

    protected static function get_contextual_last_notified_column() {
        if (InSkill_Recall_Time::is_test_mode_enabled()) {
            return 'last_notified_at_simulated';
        }

        return 'last_notified_at';
    }

    protected static function get_contextual_last_notified_value($user) {
        if (!$user) {
            return null;
        }

        $column = self::get_contextual_last_notified_column();

        if (!isset($user->{$column}) || empty($user->{$column})) {
            return null;
        }

        return (string) $user->{$column};
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
        $windowStartMinutes = self::clamp_minutes_of_day($targetMinutes - self::DAILY_WINDOW_EARLY_MINUTES);
        $windowEndMinutes = self::clamp_minutes_of_day($targetMinutes + self::DAILY_WINDOW_LATE_MINUTES);

        $lastNotifiedColumn = self::get_contextual_last_notified_column();
        $lastNotifiedAt = self::get_contextual_last_notified_value($user);

        $result = [
            'ok'                   => true,
            'reason'               => 'ok',
            'prefs'                => $prefs,
            'local_now'            => $now->format('Y-m-d H:i:s'),
            'local_date'           => $now->format('Y-m-d'),
            'local_time'           => $now->format('H:i:s'),
            'day_of_week'          => $dayOfWeek,
            'now_minutes'          => $nowMinutes,
            'target_minutes'       => $targetMinutes,
            'window_start_minutes' => $windowStartMinutes,
            'window_end_minutes'   => $windowEndMinutes,
            'last_notified_column' => $lastNotifiedColumn,
            'last_notified_at'     => $lastNotifiedAt,
            'forced_datetime'      => InSkill_Recall_Time::get_forced_datetime(),
            'wp_now'               => InSkill_Recall_Time::now_mysql(),
            'wp_today'             => InSkill_Recall_V2_Progress_Service::today_date(),
        ];

        if (empty($prefs['allow_weekend']) && $dayOfWeek >= 6) {
            $result['ok'] = false;
            $result['reason'] = 'weekend_blocked';
            return $result;
        }

        if ($nowMinutes < $windowStartMinutes) {
            $result['ok'] = false;
            $result['reason'] = 'before_target_time';
            return $result;
        }

        if ($nowMinutes > $windowEndMinutes) {
            $result['ok'] = false;
            $result['reason'] = 'outside_notification_window';
            return $result;
        }

        if (!empty($lastNotifiedAt)) {
            try {
                $tz = new DateTimeZone((string) $prefs['timezone']);
                $lastTs = InSkill_Recall_Auth::local_mysql_to_timestamp((string) $lastNotifiedAt, $tz);

                if ($lastTs !== false) {
                    $lastLocalDate = wp_date('Y-m-d', $lastTs, $tz);
                    $currentLocalDate = $now->format('Y-m-d');
                    $currentLocalTs = $now->getTimestamp();

                    $result['last_local_date'] = $lastLocalDate;
                    $result['current_local_date'] = $currentLocalDate;

                    if ($lastTs > $currentLocalTs) {
                        $result['last_notified_in_future'] = true;
                        $result['last_notified_ignored'] = true;
                    } elseif ($lastLocalDate === $currentLocalDate) {
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
}