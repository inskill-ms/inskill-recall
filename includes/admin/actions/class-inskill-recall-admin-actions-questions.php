<?php
if (!defined('ABSPATH')) {
    exit;
}

abstract class InSkill_Recall_Admin_Actions_Questions extends InSkill_Recall_Admin_Actions_Groups {
    protected function save_question() {
        $question_id = isset($_POST['question_id']) ? (int) $_POST['question_id'] : 0;
        $creation_mode = isset($_POST['question_creation_mode']) ? sanitize_key(wp_unslash($_POST['question_creation_mode'])) : 'new';

        $data = [
            'group_id'       => isset($_POST['group_id']) ? (int) $_POST['group_id'] : 0,
            'internal_label' => '',
            'question_type'  => isset($_POST['question_type']) ? wp_unslash($_POST['question_type']) : 'qcu',
            'question_text'  => isset($_POST['question_text']) ? wp_unslash($_POST['question_text']) : '',
            'explanation'    => isset($_POST['explanation']) ? wp_unslash($_POST['explanation']) : '',
            'image_id'       => isset($_POST['image_id']) ? (int) $_POST['image_id'] : null,
            'image_url'      => isset($_POST['image_url']) ? wp_unslash($_POST['image_url']) : '',
            'status'         => isset($_POST['status']) ? wp_unslash($_POST['status']) : 'active',
        ];

        $choice_texts = isset($_POST['choice_text']) ? (array) $_POST['choice_text'] : [];
        $choice_correct = isset($_POST['choice_is_correct']) ? (array) $_POST['choice_is_correct'] : [];

        $choices = [];
        foreach ($choice_texts as $index => $text) {
            $choices[] = [
                'choice_text' => wp_unslash($text),
                'is_correct'  => isset($choice_correct[$index]) ? 1 : 0,
            ];
        }

        $is_locked = $question_id > 0 ? $this->repository->question_has_activity($question_id) : false;
        $validation = $this->repository->validate_question_payload($data, $choices, $is_locked);
        if (is_wp_error($validation)) {
            $this->redirect('inskill-recall-questions', [
                'message'       => 'question_validation_error',
                'error_detail'  => rawurlencode($validation->get_error_message()),
                'edit_question' => $question_id > 0 ? $question_id : 0,
            ]);
        }

        if ($question_id > 0) {
            $existing = $this->repository->get_question($question_id);
            if ($existing) {
                $data['internal_label'] = (string) $existing->internal_label;
                $data['group_id'] = (int) $existing->group_id;
                $data['question_type'] = (string) $existing->question_type;

                if (!$this->repository->question_has_activity($question_id)) {
                    $data['group_id'] = isset($_POST['group_id']) ? (int) $_POST['group_id'] : $data['group_id'];
                    $data['question_type'] = isset($_POST['question_type']) ? wp_unslash($_POST['question_type']) : $data['question_type'];
                    $data['internal_label'] = isset($_POST['internal_label']) ? wp_unslash($_POST['internal_label']) : $data['internal_label'];
                }
            }

            $updated = $this->repository->update_question($question_id, $data, $choices);
            if (is_wp_error($updated)) {
                $this->redirect('inskill-recall-questions', [
                    'message'       => 'question_validation_error',
                    'error_detail'  => rawurlencode($updated->get_error_message()),
                    'edit_question' => $question_id,
                ]);
            }

            $this->redirect('inskill-recall-questions', ['message' => 'question_updated']);
        }

        $new_question_id = $this->repository->create_question($data, $choices);
        if (is_wp_error($new_question_id)) {
            $this->redirect('inskill-recall-questions', [
                'message'      => 'question_validation_error',
                'error_detail' => rawurlencode($new_question_id->get_error_message()),
            ]);
        }

        $this->redirect('inskill-recall-questions', ['message' => 'question_created']);
    }

    protected function delete_question() {
        $question_id = isset($_POST['question_id']) ? (int) $_POST['question_id'] : 0;

        if ($question_id > 0) {
            $deleted = $this->repository->delete_question($question_id);
            if ($deleted === false) {
                $this->redirect('inskill-recall-questions', ['message' => 'question_locked']);
            }

            $this->redirect('inskill-recall-questions', ['message' => 'question_deleted']);
        }

        $this->redirect('inskill-recall-questions', ['message' => 'question_create_error']);
    }

    protected function deactivate_question() {
        $question_id = isset($_POST['question_id']) ? (int) $_POST['question_id'] : 0;

        if ($question_id > 0) {
            $updated = $this->repository->deactivate_question($question_id);
            $this->redirect('inskill-recall-questions', ['message' => $updated ? 'question_deactivated' : 'question_create_error']);
        }

        $this->redirect('inskill-recall-questions', ['message' => 'question_create_error']);
    }
}