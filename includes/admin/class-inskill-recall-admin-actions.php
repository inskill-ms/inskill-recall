<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_Admin_Actions {
    private $repository;

    public function __construct(InSkill_Recall_Admin_Repository $repository) {
        $this->repository = $repository;
    }

    public function dispatch($action) {
        switch ($action) {
            case 'create_dashboard_page':
                $this->create_dashboard_page();
                break;
            case 'save_test_datetime':
                $this->save_test_datetime();
                break;
            case 'clear_test_datetime':
                $this->clear_test_datetime();
                break;
            case 'run_test_engine_now':
                $this->run_test_engine_now();
                break;
            case 'save_user':
                $this->save_user();
                break;
            case 'delete_user':
                $this->delete_user();
                break;
            case 'regenerate_user_token':
                $this->regenerate_user_token();
                break;
            case 'save_group':
                $this->save_group();
                break;
            case 'delete_group':
                $this->delete_group();
                break;
            case 'duplicate_group':
                $this->duplicate_group();
                break;
            case 'save_question':
                $this->save_question();
                break;
            case 'delete_question':
                $this->delete_question();
                break;
            case 'deactivate_question':
                $this->deactivate_question();
                break;
            case 'save_notification_settings':
                $this->save_notification_settings();
                break;
            case 'clear_notification_logs':
                $this->clear_notification_logs();
                break;
        }
    }

    private function redirect($page, array $args = []) {
        $url = add_query_arg(array_merge(['page' => $page], $args), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    private function create_dashboard_page() {
        $page_id = InSkill_Recall_Frontend::ensure_dashboard_page_exists();
        $this->redirect('inskill-recall', ['message' => $page_id > 0 ? 'dashboard_page_created' : 'dashboard_page_error']);
    }

    private function save_test_datetime() {
        $raw = isset($_POST['test_datetime']) ? wp_unslash($_POST['test_datetime']) : '';
        $normalized = InSkill_Recall_Time::parse_datetime_local_input($raw);

        if ($normalized === '') {
            $this->redirect('inskill-recall', ['message' => 'test_datetime_error']);
        }

        InSkill_Recall_Time::set_forced_datetime($normalized);
        $this->redirect('inskill-recall', ['message' => 'test_datetime_saved']);
    }

    private function clear_test_datetime() {
        InSkill_Recall_Time::clear_forced_datetime();
        $this->redirect('inskill-recall', ['message' => 'test_datetime_cleared']);
    }

    private function run_test_engine_now() {
        try {
            // Ordre logique d’un run manuel de test :
            // 1) clôturer les pending des jours précédents
            // 2) appliquer les rétrogradations de midi si la date simulée les déclenche
            // 3) préparer les occurrences dues pour "aujourd’hui"
            InSkill_Recall_V2_Engine::close_pending_occurrences_for_previous_days();
            InSkill_Recall_V2_Engine::run_midday_downgrades();
            InSkill_Recall_V2_Engine::prepare_all_due_occurrences_for_today();

            $this->redirect('inskill-recall', ['message' => 'test_engine_ran']);
        } catch (Throwable $e) {
            error_log('[InSkill Recall] run_test_engine_now failed: ' . $e->getMessage());
            $this->redirect('inskill-recall', ['message' => 'test_engine_error']);
        }
    }

    private function save_user() {
        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

        $data = [
            'email' => isset($_POST['email']) ? wp_unslash($_POST['email']) : '',
            'first_name' => isset($_POST['first_name']) ? wp_unslash($_POST['first_name']) : '',
            'last_name' => isset($_POST['last_name']) ? wp_unslash($_POST['last_name']) : '',
            'status' => isset($_POST['status']) ? wp_unslash($_POST['status']) : 'active',
            'notification_hour' => isset($_POST['notification_hour']) ? (int) $_POST['notification_hour'] : 9,
            'notification_minute' => isset($_POST['notification_minute']) ? (int) $_POST['notification_minute'] : 0,
            'notification_timezone' => isset($_POST['notification_timezone']) ? wp_unslash($_POST['notification_timezone']) : InSkill_Recall_Auth::DEFAULT_NOTIFICATION_TIMEZONE,
            'notifications_weekend' => !empty($_POST['notifications_weekend']) ? 1 : 0,
        ];

        if ($user_id > 0) {
            $updated = $this->repository->update_user($user_id, $data);
            $this->redirect('inskill-recall-users', ['message' => $updated ? 'user_updated' : 'user_update_error']);
        }

        $new_user_id = $this->repository->create_user($data);
        $this->redirect('inskill-recall-users', ['message' => $new_user_id ? 'user_created' : 'user_create_error']);
    }

    private function delete_user() {
        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

        if ($user_id > 0) {
            $deleted = $this->repository->delete_user($user_id);
            $this->redirect('inskill-recall-users', ['message' => $deleted ? 'user_deleted' : 'user_delete_error']);
        }

        $this->redirect('inskill-recall-users', ['message' => 'user_delete_error']);
    }

    private function regenerate_user_token() {
        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        if ($user_id > 0) {
            InSkill_Recall_Auth::regenerate_token($user_id);
        }
        $this->redirect('inskill-recall-users', ['message' => 'token_regenerated']);
    }

    private function save_group() {
        $group_id = isset($_POST['group_id']) ? (int) $_POST['group_id'] : 0;

        $data = [
            'name' => isset($_POST['name']) ? wp_unslash($_POST['name']) : '',
            'description' => isset($_POST['description']) ? wp_unslash($_POST['description']) : '',
            'start_date' => isset($_POST['start_date']) ? wp_unslash($_POST['start_date']) : '',
            'status' => isset($_POST['status']) ? wp_unslash($_POST['status']) : 'active',
            'leaderboard_mode' => isset($_POST['leaderboard_mode']) ? wp_unslash($_POST['leaderboard_mode']) : 'B',
            'question_order_mode' => isset($_POST['question_order_mode']) ? wp_unslash($_POST['question_order_mode']) : 'ordered',
        ];

        $member_ids = isset($_POST['member_ids']) ? (array) $_POST['member_ids'] : [];
        $member_ids = array_values(array_unique(array_map('intval', $member_ids)));

        if ($group_id > 0) {
            $updated = $this->repository->update_group($group_id, $data);
            if ($updated === false) {
                $this->redirect('inskill-recall-groups', ['message' => 'group_update_error']);
            }
            $this->repository->replace_group_members($group_id, $member_ids);
            $this->redirect('inskill-recall-groups', ['message' => 'group_updated']);
        }

        $new_group_id = $this->repository->create_group($data);
        if ($new_group_id > 0) {
            $this->repository->replace_group_members($new_group_id, $member_ids);
        }

        $this->redirect('inskill-recall-groups', ['message' => $new_group_id ? 'group_created' : 'group_create_error']);
    }

    private function delete_group() {
        $group_id = isset($_POST['group_id']) ? (int) $_POST['group_id'] : 0;

        if ($group_id > 0) {
            $deleted = $this->repository->delete_group($group_id);
            $this->redirect('inskill-recall-groups', ['message' => $deleted ? 'group_deleted' : 'group_delete_error']);
        }

        $this->redirect('inskill-recall-groups', ['message' => 'group_delete_error']);
    }

    private function duplicate_group() {
        $group_id = isset($_POST['group_id']) ? (int) $_POST['group_id'] : 0;

        if ($group_id <= 0) {
            $this->redirect('inskill-recall-groups', ['message' => 'group_duplicate_error']);
        }

        $new_group_id = $this->repository->duplicate_group($group_id);

        $this->redirect('inskill-recall-groups', [
            'message' => $new_group_id > 0 ? 'group_duplicated' : 'group_duplicate_error',
            'edit_group' => $new_group_id > 0 ? $new_group_id : 0,
        ]);
    }

    private function save_question() {
        $question_id = isset($_POST['question_id']) ? (int) $_POST['question_id'] : 0;
        $creation_mode = isset($_POST['question_creation_mode']) ? sanitize_key(wp_unslash($_POST['question_creation_mode'])) : 'new';

        $data = [
            'group_id' => isset($_POST['group_id']) ? (int) $_POST['group_id'] : 0,
            'internal_label' => '',
            'question_type' => isset($_POST['question_type']) ? wp_unslash($_POST['question_type']) : 'qcu',
            'question_text' => isset($_POST['question_text']) ? wp_unslash($_POST['question_text']) : '',
            'explanation' => isset($_POST['explanation']) ? wp_unslash($_POST['explanation']) : '',
            'image_id' => isset($_POST['image_id']) ? (int) $_POST['image_id'] : null,
            'image_url' => isset($_POST['image_url']) ? wp_unslash($_POST['image_url']) : '',
            'status' => isset($_POST['status']) ? wp_unslash($_POST['status']) : 'active',
        ];

        $choice_texts = isset($_POST['choice_text']) ? (array) $_POST['choice_text'] : [];
        $choice_correct = isset($_POST['choice_is_correct']) ? (array) $_POST['choice_is_correct'] : [];

        $choices = [];
        foreach ($choice_texts as $index => $text) {
            $choices[] = [
                'choice_text' => wp_unslash($text),
                'is_correct' => isset($choice_correct[$index]) ? 1 : 0,
            ];
        }

        $is_locked = $question_id > 0 ? $this->repository->question_has_activity($question_id) : false;
        $validation = $this->repository->validate_question_payload($data, $choices, $is_locked);
        if (is_wp_error($validation)) {
            $this->redirect('inskill-recall-questions', [
                'message' => 'question_validation_error',
                'error_detail' => rawurlencode($validation->get_error_message()),
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
                    'message' => 'question_validation_error',
                    'error_detail' => rawurlencode($updated->get_error_message()),
                    'edit_question' => $question_id,
                ]);
            }

            $this->redirect('inskill-recall-questions', ['message' => 'question_updated']);
        }

        $new_question_id = $this->repository->create_question($data, $choices);
        if (is_wp_error($new_question_id)) {
            $this->redirect('inskill-recall-questions', [
                'message' => 'question_validation_error',
                'error_detail' => rawurlencode($new_question_id->get_error_message()),
            ]);
        }

        $this->redirect('inskill-recall-questions', ['message' => 'question_created']);
    }

    private function delete_question() {
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

    private function deactivate_question() {
        $question_id = isset($_POST['question_id']) ? (int) $_POST['question_id'] : 0;

        if ($question_id > 0) {
            $updated = $this->repository->deactivate_question($question_id);
            $this->redirect('inskill-recall-questions', ['message' => $updated ? 'question_deactivated' : 'question_create_error']);
        }

        $this->redirect('inskill-recall-questions', ['message' => 'question_create_error']);
    }

    private function save_notification_settings() {
        $raw = isset($_POST['allowed_timezones']) ? wp_unslash($_POST['allowed_timezones']) : InSkill_Recall_Auth::get_default_allowed_timezones_raw();

        update_option(
            'inskill_recall_allowed_timezones',
            InSkill_Recall_Auth::sanitize_allowed_timezones_raw($raw)
        );

        if (isset($_POST['vapid_subject'])) {
            update_option('inskill_recall_vapid_subject', sanitize_text_field(wp_unslash($_POST['vapid_subject'])));
        }

        if (isset($_POST['vapid_public_key'])) {
            update_option('inskill_recall_vapid_public_key', sanitize_text_field(wp_unslash($_POST['vapid_public_key'])));
        }

        if (isset($_POST['vapid_private_key'])) {
            update_option('inskill_recall_vapid_private_key', sanitize_text_field(wp_unslash($_POST['vapid_private_key'])));
        }

        $this->redirect('inskill-recall-notifications', ['message' => 'notifications_saved']);
    }

    private function clear_notification_logs() {
        global $wpdb;

        $table = InSkill_Recall_DB::table('notification_logs');
        $deleted = $wpdb->query("TRUNCATE TABLE {$table}");

        if ($deleted === false) {
            $this->redirect('inskill-recall-notifications', ['message' => 'notification_logs_clear_error']);
        }

        $this->redirect('inskill-recall-notifications', ['message' => 'notification_logs_cleared']);
    }
}