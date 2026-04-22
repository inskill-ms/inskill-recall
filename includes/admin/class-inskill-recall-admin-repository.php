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

    private function normalize_group_status($status) {
        $status = sanitize_text_field((string) $status);

        if (!in_array($status, ['active', 'inactive'], true)) {
            return 'active';
        }

        return $status;
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
                'status' => $this->normalize_group_status($data['status'] ?? 'active'),
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
                'status' => $this->normalize_group_status($data['status'] ?? 'active'),
                'leaderboard_mode' => !empty($data['leaderboard_mode']) ? sanitize_text_field($data['leaderboard_mode']) : 'B',
                'question_order_mode' => !empty($data['question_order_mode']) ? sanitize_text_field($data['question_order_mode']) : 'ordered',
                'updated_at' => $this->now(),
            ],
            ['id' => (int) $group_id]
        );
    }

    public function delete_group($group_id) {
        global $wpdb;

        $group_id = (int) $group_id;
        if ($group_id <= 0) {
            return false;
        }

        $question_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM " . $this->table('questions') . " WHERE group_id = %d",
            $group_id
        ));

        $question_ids = array_values(array_filter(array_map('intval', (array) $question_ids)));

        $wpdb->query('START TRANSACTION');

        if (!empty($question_ids)) {
            $placeholders = implode(', ', array_fill(0, count($question_ids), '%d'));
            $sql = $wpdb->prepare(
                "DELETE FROM " . $this->table('question_choices') . " WHERE question_id IN ($placeholders)",
                $question_ids
            );

            if ($sql === false || $wpdb->query($sql) === false) {
                $wpdb->query('ROLLBACK');
                return false;
            }
        }

        $deletions = [
            [$this->table('question_occurrences'), ['group_id' => $group_id]],
            [$this->table('user_question_progress'), ['group_id' => $group_id]],
            [$this->table('user_group_stats'), ['group_id' => $group_id]],
            [$this->table('notification_logs'), ['group_id' => $group_id]],
            [$this->table('questions'), ['group_id' => $group_id]],
            [$this->table('group_memberships'), ['group_id' => $group_id]],
            [$this->table('groups'), ['id' => $group_id]],
        ];

        foreach ($deletions as [$table, $where]) {
            if ($wpdb->delete($table, $where) === false) {
                $wpdb->query('ROLLBACK');
                return false;
            }
        }

        $wpdb->query('COMMIT');

        return true;
    }

    public function duplicate_group($group_id) {
        global $wpdb;

        $group_id = (int) $group_id;
        if ($group_id <= 0) {
            return 0;
        }

        $source_group = $this->get_group($group_id);
        if (!$source_group) {
            return 0;
        }

        $today = current_time('Y-m-d');

        $new_group_id = $this->create_group([
            'name' => sprintf('%s — Copie', (string) $source_group->name),
            'description' => (string) $source_group->description,
            'start_date' => $today,
            'status' => 'inactive',
            'leaderboard_mode' => (string) $source_group->leaderboard_mode,
            'question_order_mode' => (string) $source_group->question_order_mode,
        ]);

        if ($new_group_id <= 0) {
            return 0;
        }

        $questions = $this->get_questions($group_id);
        $question_id_map = [];

        foreach ($questions as $question) {
            $created_question_id = $this->create_question([
                'group_id' => $new_group_id,
                'internal_label' => (string) $question->internal_label,
                'question_type' => (string) $question->question_type,
                'question_text' => (string) $question->question_text,
                'explanation' => (string) $question->explanation,
                'image_id' => !empty($question->image_id) ? (int) $question->image_id : null,
                'image_url' => !empty($question->image_url) ? (string) $question->image_url : '',
                'status' => (string) $question->status,
            ], $this->get_question_choices_payload((int) $question->id));

            if (is_wp_error($created_question_id) || (int) $created_question_id <= 0) {
                continue;
            }

            $question_id_map[(int) $question->id] = (int) $created_question_id;
        }

        return $new_group_id;
    }

    private function get_question_choices_payload($question_id) {
        $choices = $this->get_question_choices((int) $question_id);
        $payload = [];

        foreach ($choices as $choice) {
            $payload[] = [
                'choice_text' => (string) $choice->choice_text,
                'image_id' => !empty($choice->image_id) ? (int) $choice->image_id : null,
                'image_url' => !empty($choice->image_url) ? (string) $choice->image_url : '',
                'is_correct' => !empty($choice->is_correct) ? 1 : 0,
            ];
        }

        return $payload;
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

        $sql = "SELECT q.*, g.name AS group_name,
                    (SELECT COUNT(*) FROM " . $this->table('question_choices') . " qc WHERE qc.question_id = q.id) AS choices_count
             FROM " . $this->table('questions') . " q
             INNER JOIN " . $this->table('groups') . " g ON g.id = q.group_id";

        if ($group_id > 0) {
            $sql .= $wpdb->prepare(" WHERE q.group_id = %d", (int) $group_id);
        }

        $sql .= " ORDER BY q.group_id ASC, q.internal_label ASC, q.id ASC";

        return $wpdb->get_results($sql);
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

    public function validate_question_payload($data, array $choices, $is_locked = false) {
        $group_id = isset($data['group_id']) ? (int) $data['group_id'] : 0;
        $question_text = trim(wp_strip_all_tags((string) ($data['question_text'] ?? '')));
        $question_type = strtolower(trim((string) ($data['question_type'] ?? 'qcu')));
        $internal_label = trim((string) ($data['internal_label'] ?? ''));

        if ($group_id <= 0) {
            return new WP_Error('invalid_group', 'Veuillez sélectionner un groupe.');
        }

        if ($question_text === '') {
            return new WP_Error('invalid_question_text', 'Le texte de la question est obligatoire.');
        }

        if (!in_array($question_type, ['qcu', 'qcm'], true)) {
            return new WP_Error('invalid_question_type', 'Le type de question est invalide.');
        }

        $nonEmptyChoices = 0;
        $correctCount = 0;
        foreach ($choices as $choice) {
            $text = trim(wp_strip_all_tags((string) ($choice['choice_text'] ?? '')));
            if ($text === '') {
                continue;
            }
            $nonEmptyChoices++;
            if (!empty($choice['is_correct'])) {
                $correctCount++;
            }
        }

        if ($nonEmptyChoices < 2) {
            return new WP_Error('invalid_choices', 'Veuillez renseigner au moins deux choix de réponse.');
        }

        if (!$is_locked) {
            if ($correctCount <= 0) {
                return new WP_Error('missing_correct_choice', 'Veuillez sélectionner au moins une bonne réponse.');
            }

            if ($question_type === 'qcu' && $correctCount !== 1) {
                return new WP_Error('invalid_qcu_correct_count', 'Une question QCU doit avoir exactement une seule bonne réponse.');
            }
        }

        if (!$is_locked && $internal_label !== '' && !preg_match('/^Q\d{7}(?:-\d+)?$/', $internal_label)) {
            return new WP_Error('invalid_internal_label', 'Le libellé interne doit respecter le format Q0000001 ou Q0000001-1.');
        }

        return true;
    }

    public function get_next_auto_internal_label() {
        global $wpdb;

        $labels = (array) $wpdb->get_col("SELECT internal_label FROM " . $this->table('questions') . " WHERE internal_label IS NOT NULL AND internal_label != ''");
        $max = 0;

        foreach ($labels as $label) {
            if (preg_match('/^Q(\d{7})(?:-\d+)?$/', (string) $label, $matches)) {
                $max = max($max, (int) $matches[1]);
            }
        }

        if ($max <= 0) {
            $max = (int) $wpdb->get_var("SELECT MAX(id) FROM " . $this->table('questions'));
        }

        return sprintf('Q%07d', $max + 1);
    }

    public function is_internal_label_available($group_id, $internal_label, $exclude_question_id = 0) {
        global $wpdb;

        $sql = "SELECT COUNT(*)
                FROM " . $this->table('questions') . "
                WHERE group_id = %d AND internal_label = %s";
        $params = [(int) $group_id, (string) $internal_label];

        if ($exclude_question_id > 0) {
            $sql .= " AND id != %d";
            $params[] = (int) $exclude_question_id;
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params)) === 0;
    }

    public function create_question($data, array $choices) {
        global $wpdb;

        $question_type = strtolower(trim((string) ($data['question_type'] ?? 'qcu')));
        $internal_label = trim((string) ($data['internal_label'] ?? ''));
        if ($internal_label === '') {
            $internal_label = $this->get_next_auto_internal_label();
        }

        if (!$this->is_internal_label_available((int) $data['group_id'], $internal_label)) {
            return new WP_Error('duplicate_internal_label', 'Ce libellé interne existe déjà dans ce groupe.');
        }

        $result = $wpdb->insert(
            $this->table('questions'),
            [
                'group_id' => (int) $data['group_id'],
                'internal_label' => sanitize_text_field($internal_label),
                'question_type' => $question_type,
                'question_text' => wp_kses_post($data['question_text']),
                'explanation' => wp_kses_post($data['explanation']),
                'image_id' => !empty($data['image_id']) ? (int) $data['image_id'] : null,
                'image_url' => !empty($data['image_url']) ? esc_url_raw($data['image_url']) : null,
                'sort_order' => 0,
                'status' => !empty($data['status']) ? sanitize_text_field($data['status']) : 'active',
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]
        );

        if (!$result) {
            return new WP_Error('insert_failed', 'Erreur lors de la création de la question.');
        }

        $question_id = (int) $wpdb->insert_id;
        $this->replace_question_choices($question_id, $choices);

        return $question_id;
    }

    public function update_question($question_id, $data, array $choices) {
        global $wpdb;

        $question_id = (int) $question_id;
        $existing = $this->get_question($question_id);
        if (!$existing) {
            return new WP_Error('missing_question', 'Question introuvable.');
        }

        $is_locked = $this->question_has_activity($question_id);

        if ($is_locked) {
            $updated = $wpdb->update(
                $this->table('questions'),
                [
                    'question_text' => wp_kses_post($data['question_text']),
                    'explanation' => wp_kses_post($data['explanation']),
                    'image_id' => !empty($data['image_id']) ? (int) $data['image_id'] : null,
                    'image_url' => !empty($data['image_url']) ? esc_url_raw($data['image_url']) : null,
                    'status' => !empty($data['status']) ? sanitize_text_field($data['status']) : 'active',
                    'updated_at' => $this->now(),
                ],
                ['id' => $question_id]
            );

            if ($updated === false) {
                return new WP_Error('update_failed', 'Erreur lors de la mise à jour de la question.');
            }

            $this->update_locked_question_choices($question_id, $choices);
            return true;
        }

        $internal_label = trim((string) ($data['internal_label'] ?? ''));
        if ($internal_label === '') {
            $internal_label = $this->get_next_auto_internal_label();
        }

        if (!$this->is_internal_label_available((int) $data['group_id'], $internal_label, $question_id)) {
            return new WP_Error('duplicate_internal_label', 'Ce libellé interne existe déjà dans ce groupe.');
        }

        $updated = $wpdb->update(
            $this->table('questions'),
            [
                'group_id' => (int) $data['group_id'],
                'internal_label' => sanitize_text_field($internal_label),
                'question_type' => strtolower(trim((string) ($data['question_type'] ?? 'qcu'))),
                'question_text' => wp_kses_post($data['question_text']),
                'explanation' => wp_kses_post($data['explanation']),
                'image_id' => !empty($data['image_id']) ? (int) $data['image_id'] : null,
                'image_url' => !empty($data['image_url']) ? esc_url_raw($data['image_url']) : null,
                'sort_order' => 0,
                'status' => !empty($data['status']) ? sanitize_text_field($data['status']) : 'active',
                'updated_at' => $this->now(),
            ],
            ['id' => $question_id]
        );

        if ($updated === false) {
            return new WP_Error('update_failed', 'Erreur lors de la mise à jour de la question.');
        }

        $this->replace_question_choices($question_id, $choices);
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

    public function update_locked_question_choices($question_id, array $choices) {
        global $wpdb;

        $existing_choices = $this->get_question_choices($question_id);
        foreach ($existing_choices as $index => $existing_choice) {
            if (!array_key_exists($index, $choices)) {
                continue;
            }

            $new_text = trim((string) ($choices[$index]['choice_text'] ?? ''));
            if ($new_text === '') {
                continue;
            }

            $wpdb->update(
                $this->table('question_choices'),
                [
                    'choice_text' => wp_kses_post($new_text),
                    'updated_at'  => $this->now(),
                ],
                ['id' => (int) $existing_choice->id]
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

    public function deactivate_question($question_id) {
        global $wpdb;

        return false !== $wpdb->update(
            $this->table('questions'),
            [
                'status' => 'inactive',
                'updated_at' => $this->now(),
            ],
            ['id' => (int) $question_id]
        );
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
