<?php
if (!defined('ABSPATH')) {
    exit;
}

abstract class InSkill_Recall_Admin_Actions_Notifications extends InSkill_Recall_Admin_Actions_Questions {
    protected function save_notification_settings() {
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

        $cron_mode = isset($_POST['cron_mode'])
            ? InSkill_Recall_V2_Cron::sanitize_cron_mode(wp_unslash($_POST['cron_mode']))
            : InSkill_Recall_V2_Cron::CRON_MODE_WP;

        $cron_token = isset($_POST['cron_token'])
            ? InSkill_Recall_V2_Cron::sanitize_external_cron_token(wp_unslash($_POST['cron_token']))
            : InSkill_Recall_V2_Cron::get_external_cron_token();

        update_option(InSkill_Recall_V2_Cron::CRON_MODE_OPTION, $cron_mode, false);
        update_option(InSkill_Recall_V2_Cron::CRON_TOKEN_OPTION, $cron_token, false);

        $this->redirect('inskill-recall-notifications', ['message' => 'notifications_saved']);
    }

    protected function clear_notification_logs() {
        global $wpdb;

        $table = InSkill_Recall_DB::table('notification_logs');
        $deleted = $wpdb->query("TRUNCATE TABLE {$table}");

        if ($deleted === false) {
            $this->redirect('inskill-recall-notifications', ['message' => 'notification_logs_clear_error']);
        }

        $this->redirect('inskill-recall-notifications', ['message' => 'notification_logs_cleared']);
    }

    protected function send_test_push_notification() {
        $target_user_id = isset($_POST['test_push_user_id']) ? (int) $_POST['test_push_user_id'] : 0;

        if ($target_user_id <= 0) {
            $this->redirect('inskill-recall-notifications', ['message' => 'test_push_invalid_user']);
        }

        $user = $this->repository->get_user($target_user_id);
        if (!$user) {
            $this->redirect('inskill-recall-notifications', ['message' => 'test_push_invalid_user']);
        }

        $target_url = InSkill_Recall_Frontend::get_user_dashboard_url($user);
        if ($target_url === '') {
            $target_url = home_url('/');
        }

        $payload = [
            'title' => 'TEST PUSH',
            'body'  => 'Si vous voyez cette notification, le push fonctionne.',
            'url'   => $target_url,
            'tag'   => 'inskill-recall-manual-test-' . $target_user_id,
        ];

        $sent = InSkill_Recall_Push::send_test_to_user($target_user_id, $payload);

        if ($sent) {
            $this->redirect('inskill-recall-notifications', [
                'message'           => 'test_push_sent',
                'test_push_user_id' => $target_user_id,
            ]);
        }

        $this->redirect('inskill-recall-notifications', [
            'message'           => 'test_push_error',
            'test_push_user_id' => $target_user_id,
        ]);
    }
}