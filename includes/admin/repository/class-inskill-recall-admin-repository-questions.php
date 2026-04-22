<?php
if (!defined('ABSPATH')) {
    exit;
}

abstract class InSkill_Recall_Admin_Repository_Questions extends InSkill_Recall_Admin_Repository_Groups {
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
}