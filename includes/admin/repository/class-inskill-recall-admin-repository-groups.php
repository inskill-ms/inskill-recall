<?php
if (!defined('ABSPATH')) {
    exit;
}

abstract class InSkill_Recall_Admin_Repository_Groups extends InSkill_Recall_Admin_Repository_Users {
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

        $group_id = (int) $group_id;
        if ($group_id <= 0) {
            return false;
        }

        $group = $this->get_group($group_id);
        if (!$group) {
            return false;
        }

        $questions_table = $this->table('questions');
        $question_choices_table = $this->table('question_choices');
        $user_question_progress_table = $this->table('user_question_progress');
        $question_occurrences_table = $this->table('question_occurrences');
        $user_group_stats_table = $this->table('user_group_stats');
        $notification_logs_table = $this->table('notification_logs');
        $group_memberships_table = $this->table('group_memberships');
        $groups_table = $this->table('groups');

        $question_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$questions_table} WHERE group_id = %d",
            $group_id
        ));
        $question_ids = array_values(array_filter(array_map('intval', (array) $question_ids)));

        $wpdb->query('START TRANSACTION');

        try {
            $ok = true;

            $result = $wpdb->delete($notification_logs_table, ['group_id' => $group_id], ['%d']);
            if ($result === false) {
                $ok = false;
            }

            if ($ok) {
                $result = $wpdb->delete($user_group_stats_table, ['group_id' => $group_id], ['%d']);
                if ($result === false) {
                    $ok = false;
                }
            }

            if ($ok) {
                $result = $wpdb->delete($question_occurrences_table, ['group_id' => $group_id], ['%d']);
                if ($result === false) {
                    $ok = false;
                }
            }

            if ($ok) {
                $result = $wpdb->delete($user_question_progress_table, ['group_id' => $group_id], ['%d']);
                if ($result === false) {
                    $ok = false;
                }
            }

            if ($ok && !empty($question_ids)) {
                $placeholders = implode(',', array_fill(0, count($question_ids), '%d'));
                $sql = $wpdb->prepare(
                    "DELETE FROM {$question_choices_table} WHERE question_id IN ({$placeholders})",
                    $question_ids
                );
                $result = $wpdb->query($sql);
                if ($result === false) {
                    $ok = false;
                }
            }

            if ($ok) {
                $result = $wpdb->delete($questions_table, ['group_id' => $group_id], ['%d']);
                if ($result === false) {
                    $ok = false;
                }
            }

            if ($ok) {
                $result = $wpdb->delete($group_memberships_table, ['group_id' => $group_id], ['%d']);
                if ($result === false) {
                    $ok = false;
                }
            }

            if ($ok) {
                $result = $wpdb->delete($groups_table, ['id' => $group_id], ['%d']);
                if ($result === false) {
                    $ok = false;
                }
            }

            if (!$ok) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            $wpdb->query('COMMIT');
            return true;
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
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
}