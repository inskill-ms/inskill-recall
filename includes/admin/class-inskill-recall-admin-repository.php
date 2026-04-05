<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_Admin_Repository {
    public function table($name) {
        return InSkill_Recall_DB::table($name);
    }

    public function now() {
        return current_time('mysql');
    }

    public function get_dashboard_counts() {
        global $wpdb;

        return [
            'users' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('users')),
            'groups' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('groups')),
            'questions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('questions')),
            'active_progress' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM " . $this->table('user_question_progress') . " WHERE current_level != 'mastered'"
            ),
        ];
    }

    public function get_users() {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT *
             FROM " . $this->table('users') . "
             ORDER BY id DESC"
        );
    }

    public function get_user($user_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . $this->table('users') . " WHERE id = %d LIMIT 1",
            (int) $user_id
        ));
    }

    public function create_user($data) {
        global $wpdb;

        $token = InSkill_Recall_Auth::generate_token();

        $result = $wpdb->insert(
            $this->table('users'),
            [
                'email' => sanitize_email($data['email']),
                'first_name' => sanitize_text_field($data['first_name']),
                'last_name' => sanitize_text_field($data['last_name']),
                'role' => 'participant',
                'access_token' => $token,
                'status' => !empty($data['status']) ? sanitize_text_field($data['status']) : 'active',
                'notification_hour' => isset($data['notification_hour']) ? (int) $data['notification_hour'] : 9,
                'notification_minute' => isset($data['notification_minute']) ? (int) $data['notification_minute'] : 0,
                'notification_timezone' => !empty($data['notification_timezone']) ? sanitize_text_field($data['notification_timezone']) : InSkill_Recall_Auth::DEFAULT_NOTIFICATION_TIMEZONE,
                'notifications_weekend' => !empty($data['notifications_weekend']) ? 1 : 0,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]
        );

        return $result ? (int) $wpdb->insert_id : 0;
    }

    public function update_user($user_id, $data) {
        global $wpdb;

        return false !== $wpdb->update(
            $this->table('users'),
            [
                'email' => sanitize_email($data['email']),
                'first_name' => sanitize_text_field($data['first_name']),
                'last_name' => sanitize_text_field($data['last_name']),
                'status' => !empty($data['status']) ? sanitize_text_field($data['status']) : 'active',
                'notification_hour' => isset($data['notification_hour']) ? (int) $data['notification_hour'] : 9,
                'notification_minute' => isset($data['notification_minute']) ? (int) $data['notification_minute'] : 0,
                'notification_timezone' => !empty($data['notification_timezone']) ? sanitize_text_field($data['notification_timezone']) : InSkill_Recall_Auth::DEFAULT_NOTIFICATION_TIMEZONE,
                'notifications_weekend' => !empty($data['notifications_weekend']) ? 1 : 0,
                'updated_at' => $this->now(),
            ],
            ['id' => (int) $user_id]
        );
    }

    public function delete_user($user_id) {
        global $wpdb;

        return false !== $wpdb->delete($this->table('users'), ['id' => (int) $user_id]);
    }

    public function get_groups() {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT g.*,
                    (SELECT COUNT(*) FROM " . $this->table('group_memberships') . " gm WHERE gm.group_id = g.id AND gm.status = 'active') AS participants_count,
                    (SELECT COUNT(*) FROM " . $this->table('questions') . " q WHERE q.group_id = g.id AND q.status = 'active') AS questions_count
             FROM " . $this->table('groups') . " g
             ORDER BY g.id DESC"
        );
    }

    public function get_group($group_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . $this->table('groups') . " WHERE id = %d LIMIT 1",
            (int) $group_id
        ));
    }

    public function create_group($data) {
        global $wpdb;

        $result = $wpdb->insert(
            $this->table('groups'),
            [
                'name' => sanitize_text_field($data['name']),
                'description' => wp_kses_post($data['description']),
                'start_date' => sanitize_text_field($data['start_date']),
                'status' => !empty($data['status']) ? sanitize_text_field($data['status']) : 'active',
                'leaderboard_mode' => !empty($data['leaderboard_mode']) ? sanitize_text_field($data['leaderboard_mode']) : 'B',
                'question_order_mode' => !empty($data['question_order_mode']) ? sanitize_text_field($data['question_order_mode']) : 'ordered',
                'created_by' => get_current_user_id(),
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]
        );

        return $result ? (int) $wpdb->insert_id : 0;
    }

    public function update_group($group_id, $data) {
        global $wpdb;

        return false !== $wpdb->update(
            $this->table('groups'),
            [
                'name' => sanitize_text_field($data['name']),
                'description' => wp_kses_post($data['description']),
                'start_date' => sanitize_text_field($data['start_date']),
                'status' => !empty($data['status']) ? sanitize_text_field($data['status']) : 'active',
                'leaderboard_mode' => !empty($data['leaderboard_mode']) ? sanitize_text_field($data['leaderboard_mode']) : 'B',
                'question_order_mode' => !empty($data['question_order_mode']) ? sanitize_text_field($data['question_order_mode']) : 'ordered',
                'updated_at' => $this->now(),
            ],
            ['id' => (int) $group_id]
        );
    }

    public function delete_group($group_id) {
        global $wpdb;

        return false !== $wpdb->delete($this->table('groups'), ['id' => (int) $group_id]);
    }

    public function get_group_memberships($group_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT gm.*, u.first_name, u.last_name, u.email
             FROM " . $this->table('group_memberships') . " gm
             INNER JOIN " . $this->table('users') . " u ON u.id = gm.recall_user_id
             WHERE gm.group_id = %d
             ORDER BY u.first_name ASC, u.last_name ASC, u.email ASC",
            (int) $group_id
        ));
    }

    public function replace_group_members($group_id, array $user_ids) {
        global $wpdb;

        $group_id = (int) $group_id;
        $user_ids = array_values(array_unique(array_map('intval', $user_ids)));

        $wpdb->delete($this->table('group_memberships'), ['group_id' => $group_id]);

        foreach ($user_ids as $user_id) {
            if ($user_id <= 0) {
                continue;
            }

            $wpdb->insert(
                $this->table('group_memberships'),
                [
                    'group_id' => $group_id,
                    'recall_user_id' => $user_id,
                    'status' => 'active',
                    'joined_at' => $this->now(),
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]
            );
        }
    }

    public function get_questions($group_id = 0) {
        global $wpdb;

        if ($group_id > 0) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT q.*, g.name AS group_name,
                        (SELECT COUNT(*) FROM " . $this->table('question_choices') . " qc WHERE qc.question_id = q.id) AS choices_count
                 FROM " . $this->table('questions') . " q
                 INNER JOIN " . $this->table('groups') . " g ON g.id = q.group_id
                 WHERE q.group_id = %d
                 ORDER BY q.group_id ASC, q.sort_order ASC, q.id ASC",
                (int) $group_id
            ));
        }

        return $wpdb->get_results(
            "SELECT q.*, g.name AS group_name,
                    (SELECT COUNT(*) FROM " . $this->table('question_choices') . " qc WHERE qc.question_id = q.id) AS choices_count
             FROM " . $this->table('questions') . " q
             INNER JOIN " . $this->table('groups') . " g ON g.id = q.group_id
             ORDER BY q.group_id ASC, q.sort_order ASC, q.id ASC"
        );
    }

    public function get_question($question_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . $this->table('questions') . " WHERE id = %d LIMIT 1",
            (int) $question_id
        ));
    }

    public function get_question_choices($question_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT *
             FROM " . $this->table('question_choices') . "
             WHERE question_id = %d
             ORDER BY sort_order ASC, id ASC",
            (int) $question_id
        ));
    }

    public function question_has_activity($question_id) {
        global $wpdb;

        $question_id = (int) $question_id;
        if ($question_id <= 0) {
            return false;
        }

        $progress_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . $this->table('user_question_progress') . " WHERE question_id = %d",
            $question_id
        ));

        if ($progress_count > 0) {
            return true;
        }

        $occurrence_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . $this->table('question_occurrences') . " WHERE question_id = %d",
            $question_id
        ));

        return $occurrence_count > 0;
    }

    public function create_question($data, array $choices) {
        global $wpdb;

        $result = $wpdb->insert(
            $this->table('questions'),
            [
                'group_id' => (int) $data['group_id'],
                'internal_label' => sanitize_text_field($data['internal_label']),
                'question_text' => wp_kses_post($data['question_text']),
                'explanation' => wp_kses_post($data['explanation']),
                'image_id' => !empty($data['image_id']) ? (int) $data['image_id'] : null,
                'image_url' => !empty($data['image_url']) ? esc_url_raw($data['image_url']) : null,
                'sort_order' => isset($data['sort_order']) ? (int) $data['sort_order'] : 0,
                'status' => !empty($data['status']) ? sanitize_text_field($data['status']) : 'active',
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]
        );

        if (!$result) {
            return 0;
        }

        $question_id = (int) $wpdb->insert_id;
        $this->replace_question_choices($question_id, $choices);

        return $question_id;
    }

    public function update_question($question_id, $data, array $choices) {
        global $wpdb;

        if ($this->question_has_activity($question_id)) {
            return false;
        }

        $updated = $wpdb->update(
            $this->table('questions'),
            [
                'group_id' => (int) $data['group_id'],
                'internal_label' => sanitize_text_field($data['internal_label']),
                'question_text' => wp_kses_post($data['question_text']),
                'explanation' => wp_kses_post($data['explanation']),
                'image_id' => !empty($data['image_id']) ? (int) $data['image_id'] : null,
                'image_url' => !empty($data['image_url']) ? esc_url_raw($data['image_url']) : null,
                'sort_order' => isset($data['sort_order']) ? (int) $data['sort_order'] : 0,
                'status' => !empty($data['status']) ? sanitize_text_field($data['status']) : 'active',
                'updated_at' => $this->now(),
            ],
            ['id' => (int) $question_id]
        );

        if ($updated === false) {
            return false;
        }

        $this->replace_question_choices((int) $question_id, $choices);

        return true;
    }

    public function replace_question_choices($question_id, array $choices) {
        global $wpdb;

        $question_id = (int) $question_id;
        $wpdb->delete($this->table('question_choices'), ['question_id' => $question_id]);

        foreach ($choices as $index => $choice) {
            $text = isset($choice['choice_text']) ? trim((string) $choice['choice_text']) : '';
            if ($text === '') {
                continue;
            }

            $wpdb->insert(
                $this->table('question_choices'),
                [
                    'question_id' => $question_id,
                    'choice_text' => wp_kses_post($text),
                    'image_id' => !empty($choice['image_id']) ? (int) $choice['image_id'] : null,
                    'image_url' => !empty($choice['image_url']) ? esc_url_raw($choice['image_url']) : null,
                    'is_correct' => !empty($choice['is_correct']) ? 1 : 0,
                    'sort_order' => $index,
                    'created_at' => $this->now(),
                    'updated_at' => $this->now(),
                ]
            );
        }
    }

    public function delete_question($question_id) {
        global $wpdb;

        if ($this->question_has_activity($question_id)) {
            return false;
        }

        return false !== $wpdb->delete($this->table('questions'), ['id' => (int) $question_id]);
    }

    public function get_notification_summary() {
        global $wpdb;

        return [
            'subscriptions_total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('push_subscriptions')),
            'subscriptions_active' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('push_subscriptions') . " WHERE status = 'active'"),
            'logs_total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('notification_logs')),
            'logs_sent' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . $this->table('notification_logs') . " WHERE status = 'sent'"),
        ];
    }

    public function get_notification_logs($limit = 100) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, u.first_name, u.last_name, u.email, g.name AS group_name
             FROM " . $this->table('notification_logs') . " l
             LEFT JOIN " . $this->table('users') . " u ON u.id = l.recall_user_id
             LEFT JOIN " . $this->table('groups') . " g ON g.id = l.group_id
             ORDER BY l.id DESC
             LIMIT %d",
            (int) $limit
        ));
    }

    public function get_group_stats() {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT s.*, u.first_name, u.last_name, u.email, g.name AS group_name
             FROM " . $this->table('user_group_stats') . " s
             INNER JOIN " . $this->table('users') . " u ON u.id = s.recall_user_id
             INNER JOIN " . $this->table('groups') . " g ON g.id = s.group_id
             ORDER BY g.name ASC, s.score_total DESC, s.cached_rank ASC, s.id ASC"
        );
    }

    public function get_progress_overview($limit = 200) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, u.first_name, u.last_name, u.email, g.name AS group_name, q.internal_label, q.question_text
             FROM " . $this->table('user_question_progress') . " p
             INNER JOIN " . $this->table('users') . " u ON u.id = p.recall_user_id
             INNER JOIN " . $this->table('groups') . " g ON g.id = p.group_id
             INNER JOIN " . $this->table('questions') . " q ON q.id = p.question_id
             ORDER BY p.updated_at DESC, p.id DESC
             LIMIT %d",
            (int) $limit
        ));
    }
}