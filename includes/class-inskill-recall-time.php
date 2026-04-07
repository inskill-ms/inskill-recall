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

        // Important :
        // current_datetime() renvoie directement la date/heure WordPress
        // sans double conversion de fuseau.
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

    public static function now_timestamp() {
        return self::get_now_datetime_object()->getTimestamp();
    }

    public static function now_mysql() {
        return self::get_now_datetime_object()->format('Y-m-d H:i:s');
    }

    public static function today_date() {
        return self::get_now_datetime_object()->format('Y-m-d');
    }

    public static function now_label() {
        return self::get_now_datetime_object()->format('d/m/Y H:i:s');
    }

    public static function get_datetime_local_input_value() {
        $forced = self::get_forced_datetime();
        if ($forced === '') {
            return '';
        }

        $dt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $forced,
            self::get_wp_timezone()
        );

        if (!($dt instanceof DateTimeImmutable)) {
            return '';
        }

        return $dt->format('Y-m-d\TH:i');
    }

    public static function parse_datetime_local_input($value) {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }

        $value = str_replace('T', ' ', $value);

        $dt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            $value,
            self::get_wp_timezone()
        );

        if (!($dt instanceof DateTimeImmutable)) {
            return '';
        }

        return $dt->format('Y-m-d H:i:s');
    }
}