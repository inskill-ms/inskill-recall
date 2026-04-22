<?php
if (!defined('ABSPATH')) {
    exit;
}

abstract class InSkill_Recall_Admin_Actions_Users extends InSkill_Recall_Admin_Actions_Test_Time {
    protected function save_user() {
        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

        $data = [
            'email'                 => isset($_POST['email']) ? wp_unslash($_POST['email']) : '',
            'first_name'            => isset($_POST['first_name']) ? wp_unslash($_POST['first_name']) : '',
            'last_name'             => isset($_POST['last_name']) ? wp_unslash($_POST['last_name']) : '',
            'status'                => isset($_POST['status']) ? wp_unslash($_POST['status']) : 'active',
            'notification_hour'     => isset($_POST['notification_hour']) ? (int) $_POST['notification_hour'] : 9,
            'notification_minute'   => isset($_POST['notification_minute']) ? (int) $_POST['notification_minute'] : 0,
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

    protected function delete_user() {
        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

        if ($user_id > 0) {
            $deleted = $this->repository->delete_user($user_id);
            $this->redirect('inskill-recall-users', ['message' => $deleted ? 'user_deleted' : 'user_delete_error']);
        }

        $this->redirect('inskill-recall-users', ['message' => 'user_delete_error']);
    }

    protected function regenerate_user_token() {
        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

        if ($user_id > 0) {
            InSkill_Recall_Auth::regenerate_token($user_id);
        }

        $this->redirect('inskill-recall-users', ['message' => 'token_regenerated']);
    }
}