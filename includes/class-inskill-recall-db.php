<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_DB {
    const DB_VERSION = '0.6.3';

    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'inskill_recall_' . $name;
    }

    public static function get_tables() {
        return [
            'users',
            'push_subscriptions',
            'groups',
            'group_memberships',
            'questions',
            'question_choices',
            'user_question_progress',
            'question_occurrences',
            'user_group_stats',
            'notification_logs',
        ];
    }

    public static function activate() {
        self::create_tables();
        self::run_data_migrations();
        self::seed_defaults();
        update_option('inskill_recall_db_version', self::DB_VERSION);
    }

    public static function maybe_upgrade() {
        $installed = (string) get_option('inskill_recall_db_version', '');

        if ($installed !== self::DB_VERSION) {
            self::create_tables();
            self::run_data_migrations();
            self::seed_defaults();
            update_option('inskill_recall_db_version', self::DB_VERSION);
        }
    }

    public static function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $users_table                  = self::table('users');
        $push_subscriptions_table     = self::table('push_subscriptions');
        $groups_table                 = self::table('groups');
        $group_memberships_table      = self::table('group_memberships');
        $questions_table              = self::table('questions');
        $question_choices_table       = self::table('question_choices');
        $user_question_progress_table = self::table('user_question_progress');
        $question_occurrences_table   = self::table('question_occurrences');
        $user_group_stats_table       = self::table('user_group_stats');
        $notification_logs_table      = self::table('notification_logs');

        $sql = [];

        $sql[] = "CREATE TABLE {$users_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(191) NULL,
            first_name VARCHAR(100) NULL,
            last_name VARCHAR(100) NULL,
            role VARCHAR(30) NOT NULL DEFAULT 'participant',
            access_token VARCHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            notification_hour TINYINT UNSIGNED NOT NULL DEFAULT 9,
            notification_minute TINYINT UNSIGNED NOT NULL DEFAULT 0,
            notification_timezone VARCHAR(100) NOT NULL DEFAULT 'Africa/Casablanca',
            notifications_weekend TINYINT(1) NOT NULL DEFAULT 0,
            last_access_at DATETIME NULL,
            last_notified_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_access_token (access_token),
            KEY idx_email (email),
            KEY idx_status (status)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$push_subscriptions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            recall_user_id BIGINT UNSIGNED NOT NULL,
            endpoint_hash CHAR(64) NOT NULL,
            endpoint TEXT NOT NULL,
            subscription_json LONGTEXT NOT NULL,
            user_agent TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            last_success_at DATETIME NULL,
            last_error_at DATETIME NULL,
            last_error_message TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_endpoint_hash (endpoint_hash),
            KEY idx_user_status (recall_user_id, status)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$groups_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            description TEXT NULL,
            start_date DATE NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            leaderboard_mode CHAR(1) NOT NULL DEFAULT 'B',
            question_order_mode VARCHAR(20) NOT NULL DEFAULT 'ordered',
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_status_start (status, start_date)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$group_memberships_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id BIGINT UNSIGNED NOT NULL,
            recall_user_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            joined_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_group_user (group_id, recall_user_id),
            KEY idx_user_status (recall_user_id, status)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$questions_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id BIGINT UNSIGNED NOT NULL,
            internal_label VARCHAR(191) NULL,
            question_type VARCHAR(10) NOT NULL DEFAULT 'qcu',
            question_text LONGTEXT NOT NULL,
            explanation LONGTEXT NULL,
            image_id BIGINT UNSIGNED NULL,
            image_url TEXT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_group_status_order (group_id, status, internal_label(32), id)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$question_choices_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            question_id BIGINT UNSIGNED NOT NULL,
            choice_text LONGTEXT NOT NULL,
            image_id BIGINT UNSIGNED NULL,
            image_url TEXT NULL,
            is_correct TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_question_order (question_id, sort_order)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$user_question_progress_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id BIGINT UNSIGNED NOT NULL,
            recall_user_id BIGINT UNSIGNED NOT NULL,
            question_id BIGINT UNSIGNED NOT NULL,
            question_order_index INT NOT NULL DEFAULT 0,
            is_initially_assigned TINYINT(1) NOT NULL DEFAULT 0,
            injection_chain_number TINYINT UNSIGNED NULL,
            parent_progress_id BIGINT UNSIGNED NULL,
            current_level VARCHAR(20) NOT NULL DEFAULT 'nv0',
            current_state VARCHAR(20) NOT NULL DEFAULT 'active',
            first_presented_at DATETIME NULL,
            first_answered_at DATETIME NULL,
            last_presented_at DATETIME NULL,
            last_answered_at DATETIME NULL,
            last_result VARCHAR(20) NULL,
            next_due_date DATE NULL,
            next_due_at DATETIME NULL,
            downgrade_on_date DATE NULL,
            downgrade_at DATETIME NULL,
            consecutive_unanswered_days INT NOT NULL DEFAULT 0,
            total_presentations_count INT NOT NULL DEFAULT 0,
            total_answers_count INT NOT NULL DEFAULT 0,
            total_correct_count INT NOT NULL DEFAULT 0,
            total_incorrect_count INT NOT NULL DEFAULT 0,
            total_unanswered_count INT NOT NULL DEFAULT 0,
            awarded_nv1_points TINYINT(1) NOT NULL DEFAULT 0,
            awarded_nv2_points TINYINT(1) NOT NULL DEFAULT 0,
            awarded_nv3_points TINYINT(1) NOT NULL DEFAULT 0,
            awarded_nv4_points TINYINT(1) NOT NULL DEFAULT 0,
            awarded_nv5_points TINYINT(1) NOT NULL DEFAULT 0,
            speed_bonus_count INT NOT NULL DEFAULT 0,
            penalty_points_total INT NOT NULL DEFAULT 0,
            mastered_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_user_question (group_id, recall_user_id, question_id),
            KEY idx_user_group_due (recall_user_id, group_id, next_due_at),
            KEY idx_user_group_state (recall_user_id, group_id, current_state),
            KEY idx_group_level (group_id, current_level),
            KEY idx_chain_lookup (group_id, recall_user_id, injection_chain_number, created_at, id),
            KEY idx_parent_progress (parent_progress_id)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$question_occurrences_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id BIGINT UNSIGNED NOT NULL,
            recall_user_id BIGINT UNSIGNED NOT NULL,
            question_id BIGINT UNSIGNED NOT NULL,
            progress_id BIGINT UNSIGNED NOT NULL,
            scheduled_date DATE NOT NULL,
            scheduled_at DATETIME NOT NULL,
            display_level VARCHAR(20) NOT NULL,
            effective_level VARCHAR(20) NULL,
            occurrence_type VARCHAR(20) NOT NULL DEFAULT 'review',
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            answered_at DATETIME NULL,
            selected_choice_ids_json LONGTEXT NULL,
            correct_choice_ids_json LONGTEXT NULL,
            points_awarded INT NOT NULL DEFAULT 0,
            speed_bonus_awarded INT NOT NULL DEFAULT 0,
            penalty_applied INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_daily_occurrence (progress_id, scheduled_date),
            KEY idx_user_date (recall_user_id, scheduled_date),
            KEY idx_group_user_status (group_id, recall_user_id, status),
            KEY idx_progress_date (progress_id, scheduled_date)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$user_group_stats_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id BIGINT UNSIGNED NOT NULL,
            recall_user_id BIGINT UNSIGNED NOT NULL,
            participant_status VARCHAR(20) NOT NULL DEFAULT 'active',
            total_questions INT NOT NULL DEFAULT 0,
            introduced_questions INT NOT NULL DEFAULT 0,
            mastered_questions INT NOT NULL DEFAULT 0,
            score_total INT NOT NULL DEFAULT 0,
            speed_bonus_total INT NOT NULL DEFAULT 0,
            penalty_total INT NOT NULL DEFAULT 0,
            answers_total INT NOT NULL DEFAULT 0,
            correct_total INT NOT NULL DEFAULT 0,
            incorrect_total INT NOT NULL DEFAULT 0,
            unanswered_total INT NOT NULL DEFAULT 0,
            last_answer_at DATETIME NULL,
            last_activity_at DATETIME NULL,
            cached_rank INT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_group_user (group_id, recall_user_id),
            KEY idx_group_score (group_id, score_total),
            KEY idx_group_status (group_id, participant_status)
        ) {$charset_collate};";

        $sql[] = "CREATE TABLE {$notification_logs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            recall_user_id BIGINT UNSIGNED NOT NULL,
            group_id BIGINT UNSIGNED NULL,
            notification_type VARCHAR(30) NOT NULL,
            title VARCHAR(255) NOT NULL,
            body LONGTEXT NOT NULL,
            payload_json LONGTEXT NULL,
            sent_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'sent',
            error_message TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_user_type_sent (recall_user_id, notification_type, sent_at),
            KEY idx_group_type_sent (group_id, notification_type, sent_at)
        ) {$charset_collate};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        self::drop_legacy_tables();
    }

    public static function run_data_migrations() {
        global $wpdb;

        $questions_table = self::table('questions');
        $users_table = self::table('users');

        $wpdb->query("UPDATE {$questions_table} SET question_type = 'qcu' WHERE question_type IS NULL OR question_type = ''");

        $rows = $wpdb->get_results("SELECT id, internal_label FROM {$questions_table} ORDER BY id ASC");
        foreach ($rows as $row) {
            $label = trim((string) $row->internal_label);
            if ($label === '') {
                $wpdb->update(
                    $questions_table,
                    [
                        'internal_label' => sprintf('Q%07d', (int) $row->id),
                        'updated_at'     => current_time('mysql'),
                    ],
                    ['id' => (int) $row->id]
                );
            }
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE {$users_table} SET notification_timezone = %s WHERE notification_timezone IS NULL OR notification_timezone = '' OR notification_timezone = 'Europe/Paris'",
            InSkill_Recall_Auth::DEFAULT_NOTIFICATION_TIMEZONE
        ));
    }

    public static function drop_legacy_tables() {
        global $wpdb;

        $legacy_tables = [
            self::table('sessions'),
            self::table('session_items'),
            self::table('attempts'),
            self::table('question_progress'),
            self::table('group_participant_stats'),
        ];

        foreach ($legacy_tables as $table_name) {
            $wpdb->query("DROP TABLE IF EXISTS `{$table_name}`");
        }
    }

    public static function drop_tables() {
        global $wpdb;

        $tables = array_map([__CLASS__, 'table'], self::get_tables());

        $legacy_tables = [
            self::table('sessions'),
            self::table('session_items'),
            self::table('attempts'),
            self::table('question_progress'),
            self::table('group_participant_stats'),
        ];

        $tables = array_merge($tables, $legacy_tables);

        foreach ($tables as $table_name) {
            $wpdb->query("DROP TABLE IF EXISTS `{$table_name}`");
        }
    }

    public static function seed_defaults() {
        $defaults = [
            'dashboard_page_id' => 0,
            'vapid_subject' => 'mailto:contact@example.com',
            'vapid_public_key' => '',
            'vapid_private_key' => '',
            'allowed_timezones' => self::get_default_allowed_timezones_raw(),
        ];

        foreach ($defaults as $key => $value) {
            $option_name = 'inskill_recall_' . $key;

            if (get_option($option_name, null) === null) {
                add_option($option_name, $value);
            }
        }
    }

    private static function get_default_allowed_timezones_raw() {
        return implode("
", [
            'Maroc - Casablanca|Africa/Casablanca',
            'France - Paris|Europe/Paris',
        ]);
    }
}
