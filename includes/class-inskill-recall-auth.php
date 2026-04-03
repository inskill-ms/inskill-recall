<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_Auth {
    const DEFAULT_NOTIFICATION_HOUR = 9;
    const DEFAULT_NOTIFICATION_MINUTE = 0;
    const DEFAULT_NOTIFICATIONS_WEEKEND = 0;
    const DEFAULT_NOTIFICATION_TIMEZONE = 'Europe/Paris';

    public static function generate_token() {
        return bin2hex(random_bytes(32));
    }

    public static function sanitize_token($token) {
        $token = strtolower((string) $token);
        return preg_replace('/[^a-f0-9]/', '', $token);
    }

    public static function get_user_by_token($token) {
        global $wpdb;

        $token = self::sanitize_token($token);
        if ($token === '' || strlen($token) < 32) {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . InSkill_Recall_DB::table('users') . " WHERE access_token = %s AND status = 'active' LIMIT 1",
            $token
        ));
    }

    public static function get_user($recall_user_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . InSkill_Recall_DB::table('users') . " WHERE id = %d LIMIT 1",
            (int) $recall_user_id
        ));
    }

    public static function get_current_user_from_request() {
        $token = '';

        if (isset($_POST['token'])) {
            $token = wp_unslash($_POST['token']);
        } elseif (isset($_GET['token'])) {
            $token = wp_unslash($_GET['token']);
        }

        return self::get_user_by_token($token);
    }

    public static function touch_last_access($recall_user_id) {
        global $wpdb;

        $wpdb->update(
            InSkill_Recall_DB::table('users'),
            [
                'last_access_at' => current_time('mysql'),
                'updated_at'     => current_time('mysql'),
            ],
            ['id' => (int) $recall_user_id]
        );
    }

    public static function regenerate_token($recall_user_id) {
        global $wpdb;

        $token = self::generate_token();

        $wpdb->update(
            InSkill_Recall_DB::table('users'),
            [
                'access_token' => $token,
                'updated_at'   => current_time('mysql'),
            ],
            ['id' => (int) $recall_user_id]
        );

        return $token;
    }

    public static function get_display_name($user) {
        $first = trim((string) ($user->first_name ?? ''));
        $last  = trim((string) ($user->last_name ?? ''));
        $name  = trim($first . ' ' . $last);

        if ($name !== '') {
            return $name;
        }

        if (!empty($user->email)) {
            return (string) $user->email;
        }

        return 'Participant';
    }

    public static function get_default_allowed_timezones_raw() {
        return implode("\n", [
            'France — Paris|Europe/Paris',
            'Maroc — Casablanca|Africa/Casablanca',
        ]);
    }

    public static function sanitize_allowed_timezones_raw($raw) {
        $choices = self::parse_allowed_timezones_raw($raw);

        $normalized = [];
        foreach ($choices as $timezone => $label) {
            $normalized[] = $label . '|' . $timezone;
        }

        return implode("\n", $normalized);
    }

    public static function parse_allowed_timezones_raw($raw) {
        $raw = is_string($raw) ? $raw : '';
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $choices = [];

        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }

                $parts = array_map('trim', explode('|', $line, 2));
                if (count($parts) !== 2) {
                    continue;
                }

                $label = sanitize_text_field($parts[0]);
                $timezone = sanitize_text_field($parts[1]);

                if ($label === '' || $timezone === '') {
                    continue;
                }

                if (!self::is_valid_timezone_identifier($timezone)) {
                    continue;
                }

                $choices[$timezone] = $label;
            }
        }

        if (empty($choices)) {
            $choices = self::parse_allowed_timezones_raw(self::get_default_allowed_timezones_raw());
        }

        if (!isset($choices[self::DEFAULT_NOTIFICATION_TIMEZONE])) {
            $choices = [self::DEFAULT_NOTIFICATION_TIMEZONE => 'France — Paris'] + $choices;
        }

        return $choices;
    }

    public static function get_allowed_timezones() {
        $raw = (string) get_option('inskill_recall_allowed_timezones', self::get_default_allowed_timezones_raw());
        return self::parse_allowed_timezones_raw($raw);
    }

    public static function is_valid_timezone_identifier($timezone) {
        if (!is_string($timezone) || trim($timezone) === '') {
            return false;
        }

        try {
            new DateTimeZone($timezone);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function get_valid_timezone_identifier($timezone) {
        $timezone = is_string($timezone) ? trim($timezone) : '';
        $allowed = self::get_allowed_timezones();

        if ($timezone !== '' && isset($allowed[$timezone])) {
            return $timezone;
        }

        if ($timezone !== '' && self::is_valid_timezone_identifier($timezone)) {
            return $timezone;
        }

        return self::DEFAULT_NOTIFICATION_TIMEZONE;
    }

    public static function get_timezone_label($timezone) {
        $timezone = self::get_valid_timezone_identifier($timezone);
        $allowed = self::get_allowed_timezones();

        if (isset($allowed[$timezone])) {
            return $allowed[$timezone];
        }

        return $timezone;
    }

    public static function get_timezone_options_payload() {
        $options = [];

        foreach (self::get_allowed_timezones() as $timezone => $label) {
            $options[] = [
                'value' => $timezone,
                'label' => $label,
            ];
        }

        return $options;
    }

    public static function get_notification_preferences($user) {
        $hour = isset($user->notification_hour) ? (int) $user->notification_hour : self::DEFAULT_NOTIFICATION_HOUR;
        $minute = isset($user->notification_minute) ? (int) $user->notification_minute : self::DEFAULT_NOTIFICATION_MINUTE;
        $allow_weekend = isset($user->notifications_weekend) ? (int) $user->notifications_weekend : self::DEFAULT_NOTIFICATIONS_WEEKEND;
        $timezone = isset($user->notification_timezone) ? (string) $user->notification_timezone : self::DEFAULT_NOTIFICATION_TIMEZONE;

        if ($hour < 0 || $hour > 23) {
            $hour = self::DEFAULT_NOTIFICATION_HOUR;
        }

        if ($minute < 0 || $minute > 59) {
            $minute = self::DEFAULT_NOTIFICATION_MINUTE;
        }

        $allow_weekend = $allow_weekend ? 1 : 0;
        $timezone = self::get_valid_timezone_identifier($timezone);

        return [
            'hour'                   => $hour,
            'minute'                 => $minute,
            'timezone'               => $timezone,
            'timezone_label'         => self::get_timezone_label($timezone),
            'timezone_options'       => self::get_timezone_options_payload(),
            'allow_weekend'          => $allow_weekend,
            'time_label'             => sprintf('%02d:%02d', $hour, $minute),
            'weekend_label'          => $allow_weekend ? 'Oui' : 'Non',
            'default_hour'           => self::DEFAULT_NOTIFICATION_HOUR,
            'default_minute'         => self::DEFAULT_NOTIFICATION_MINUTE,
            'default_timezone'       => self::DEFAULT_NOTIFICATION_TIMEZONE,
            'default_timezone_label' => self::get_timezone_label(self::DEFAULT_NOTIFICATION_TIMEZONE),
            'default_allow_weekend'  => self::DEFAULT_NOTIFICATIONS_WEEKEND,
        ];
    }

    public static function update_notification_preferences($recall_user_id, $hour, $minute, $allow_weekend, $timezone = null) {
        global $wpdb;

        $hour = (int) $hour;
        $minute = (int) $minute;
        $allow_weekend = $allow_weekend ? 1 : 0;
        $timezone = self::get_valid_timezone_identifier($timezone);

        if ($hour < 0 || $hour > 23) {
            return false;
        }

        if ($minute < 0 || $minute > 59) {
            return false;
        }

        return false !== $wpdb->update(
            InSkill_Recall_DB::table('users'),
            [
                'notification_hour'      => $hour,
                'notification_minute'    => $minute,
                'notification_timezone'  => $timezone,
                'notifications_weekend'  => $allow_weekend,
                'updated_at'             => current_time('mysql'),
            ],
            ['id' => (int) $recall_user_id]
        );
    }

    public static function local_mysql_to_timestamp($mysql_datetime, $timezone = null) {
        $mysql_datetime = trim((string) $mysql_datetime);
        if ($mysql_datetime === '') {
            return false;
        }

        try {
            $tz = $timezone instanceof DateTimeZone ? $timezone : ($timezone ? new DateTimeZone((string) $timezone) : wp_timezone());
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysql_datetime, $tz);
            if (!$dt) {
                return false;
            }

            return $dt->getTimestamp();
        } catch (Exception $e) {
            return false;
        }
    }

    public static function get_next_allowed_notification_timestamp($user, $not_before_timestamp = null) {
        if ($not_before_timestamp === null) {
            $not_before_timestamp = current_time('timestamp');
        }

        $prefs = self::get_notification_preferences($user);
        $timezone = $prefs['timezone'];

        try {
            $tz = new DateTimeZone($timezone);
            $dt = new DateTimeImmutable('@' . (int) $not_before_timestamp);
            $dt = $dt->setTimezone($tz);
        } catch (Exception $e) {
            return (int) $not_before_timestamp;
        }

        for ($i = 0; $i < 14; $i++) {
            $candidate = $dt->setTime((int) $prefs['hour'], (int) $prefs['minute'], 0);

            if ($candidate->getTimestamp() < (int) $not_before_timestamp) {
                $candidate = $candidate->modify('+1 day')->setTime((int) $prefs['hour'], (int) $prefs['minute'], 0);
            }

            $day_of_week = (int) $candidate->format('N');
            if (!$prefs['allow_weekend'] && $day_of_week >= 6) {
                $dt = $candidate->modify('+1 day')->setTime((int) $prefs['hour'], (int) $prefs['minute'], 0);
                continue;
            }

            return $candidate->getTimestamp();
        }

        return (int) $not_before_timestamp;
    }

    public static function can_send_notification_now($user, $not_before_timestamp) {
        $next_allowed = self::get_next_allowed_notification_timestamp($user, $not_before_timestamp);
        $now = current_time('timestamp');

        return $now >= $next_allowed;
    }
}