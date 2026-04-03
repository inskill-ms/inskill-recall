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

            case 'save_question':
                $this->save_question();
                break;

            case 'delete_question':
                $this->delete_question();
                break;

            case 'save_notification_settings':
                $this->save_notification_settings();
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

        if ($page_id > 0) {
            $this->redirect('inskill-recall', ['message' => 'dashboard_page_created']);
        }

        $this->redirect('inskill-recall', ['message' => 'dashboard_page_error']);
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
            $this->repository->update_user($user_id, $data);
            $this->redirect('inskill-recall-users', ['message' => 'user_updated']);
        }

        $new_user_id = $this->repository->create_user($data);
        $this->redirect('inskill-recall-users', ['message' => $new_user_id ? 'user_created' : 'user_create_error']);
    }

    private function delete_user() {
        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

        if ($user_id > 0) {
            $this->repository->delete_user($user_id);
        }

        $this->redirect('inskill-recall-users', ['message' => 'user_deleted']);
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
            $this->repository->update_group($group_id, $data);
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
            $this->repository->delete_group($group_id);
        }

        $this->redirect('inskill-recall-groups', ['message' => 'group_deleted']);
    }

    private function save_question() {
        $question_id = isset($_POST['question_id']) ? (int) $_POST['question_id'] : 0;

        $data = [
            'group_id' => isset($_POST['group_id']) ? (int) $_POST['group_id'] : 0,
            'internal_label' => isset($_POST['internal_label']) ? wp_unslash($_POST['internal_label']) : '',
            'question_text' => isset($_POST['question_text']) ? wp_unslash($_POST['question_text']) : '',
            'explanation' => isset($_POST['explanation']) ? wp_unslash($_POST['explanation']) : '',
            'image_id' => isset($_POST['image_id']) ? (int) $_POST['image_id'] : null,
            'image_url' => isset($_POST['image_url']) ? wp_unslash($_POST['image_url']) : '',
            'sort_order' => isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 0,
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

        if ($question_id > 0) {
            $this->repository->update_question($question_id, $data, $choices);
            $this->redirect('inskill-recall-questions', ['message' => 'question_updated']);
        }

        $new_question_id = $this->repository->create_question($data, $choices);
        $this->redirect('inskill-recall-questions', ['message' => $new_question_id ? 'question_created' : 'question_create_error']);
    }

    private function delete_question() {
        $question_id = isset($_POST['question_id']) ? (int) $_POST['question_id'] : 0;

        if ($question_id > 0) {
            $this->repository->delete_question($question_id);
        }

        $this->redirect('inskill-recall-questions', ['message' => 'question_deleted']);
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
}