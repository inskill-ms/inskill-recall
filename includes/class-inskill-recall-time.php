<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_Time {
    const OPTION_FORCED_DATETIME = 'inskill_recall_forced_datetime';

    protected static function get_wp_timezone() {
        try {
            return wp_timezone();
        } catch (Exception $e) {
            return new DateTimeZone('UTC');
        }
    }

    protected static function get_now_datetime_object() {
        $forced = self::get_forced_datetime();

        if ($forced !== '') {
            $dt = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $forced,
                self::get_wp_timezone()
            );

            if ($dt instanceof DateTimeImmutable) {
                return $dt;
            }
        }

        return current_datetime();
    }

    public static function get_forced_datetime() {
        $value = get_option(self::OPTION_FORCED_DATETIME, '');
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return '';
        }

        $dt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $value,
            self::get_wp_timezone()
        );

        if (!($dt instanceof DateTimeImmutable)) {
            return '';
        }

        return $dt->format('Y-m-d H:i:s');
    }

    public static function set_forced_datetime($datetime_string) {
        $datetime_string = is_string($datetime_string) ? trim($datetime_string) : '';

        if ($datetime_string === '') {
            delete_option(self::OPTION_FORCED_DATETIME);
            return true;
        }

        $dt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $datetime_string,
            self::get_wp_timezone()
        );

        if (!($dt instanceof DateTimeImmutable)) {
            return false;
        }

        return update_option(
            self::OPTION_FORCED_DATETIME,
            $dt->format('Y-m-d H:i:s'),
            false
        );
    }

    public static function clear_forced_datetime() {
        delete_option(self::OPTION_FORCED_DATETIME);
    }

    public static function is_test_mode_enabled() {
        return self::get_forced_datetime() !== '';
    }

    public static function now_mysql() {
        return self::get_now_datetime_object()->format('Y-m-d H:i:s');
    }

    public static function now_timestamp() {
        return self::get_now_datetime_object()->getTimestamp();
    }

    public static function today_date() {
        return self::get_now_datetime_object()->format('Y-m-d');
    }

    public static function now($timezone = null) {
        $dt = self::get_now_datetime_object();

        if ($timezone instanceof DateTimeZone) {
            return $dt->setTimezone($timezone);
        }

        if (is_string($timezone) && trim($timezone) !== '') {
            try {
                return $dt->setTimezone(new DateTimeZone(trim($timezone)));
            } catch (Exception $e) {
                return $dt;
            }
        }

        return $dt;
    }

    public static function parse_datetime_local_input($raw) {
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            return '';
        }

        $timezone = self::get_wp_timezone();
        $formats = [
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
        ];

        foreach ($formats as $format) {
            $dt = DateTimeImmutable::createFromFormat($format, $raw, $timezone);
            if ($dt instanceof DateTimeImmutable) {
                return $dt->format('Y-m-d H:i:s');
            }
        }

        try {
            $dt = new DateTimeImmutable($raw, $timezone);
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return '';
        }
    }

    public static function get_datetime_local_input_value() {
        $forced = self::get_forced_datetime();

        if ($forced === '') {
            return '';
        }

        try {
            $dt = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $forced,
                self::get_wp_timezone()
            );

            if (!($dt instanceof DateTimeImmutable)) {
                return '';
            }

            return $dt->format('Y-m-d\TH:i');
        } catch (Exception $e) {
            return '';
        }
    }

    public static function now_label() {
        try {
            return self::now()->format('d/m/Y H:i:s');
        } catch (Exception $e) {
            return self::now_mysql();
        }
    }
}