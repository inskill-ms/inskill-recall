<?php
if (!defined('ABSPATH')) {
    exit;
}

abstract class InSkill_Recall_Admin_Repository_Users extends InSkill_Recall_Admin_Repository_Dashboard {
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

        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return false;
        }

        $user = $this->get_user($user_id);
        if (!$user) {
            return false;
        }

        $notification_logs_table = $this->table('notification_logs');
        $push_subscriptions_table = $this->table('push_subscriptions');
        $user_group_stats_table = $this->table('user_group_stats');
        $question_occurrences_table = $this->table('question_occurrences');
        $user_question_progress_table = $this->table('user_question_progress');
        $group_memberships_table = $this->table('group_memberships');
        $users_table = $this->table('users');

        $wpdb->query('START TRANSACTION');

        try {
            $ok = true;

            $result = $wpdb->delete($notification_logs_table, ['recall_user_id' => $user_id], ['%d']);
            if ($result === false) {
                $ok = false;
            }

            if ($ok) {
                $result = $wpdb->delete($push_subscriptions_table, ['recall_user_id' => $user_id], ['%d']);
                if ($result === false) {
                    $ok = false;
                }
            }

            if ($ok) {
                $result = $wpdb->delete($user_group_stats_table, ['recall_user_id' => $user_id], ['%d']);
                if ($result === false) {
                    $ok = false;
                }
            }

            if ($ok) {
                $result = $wpdb->delete($question_occurrences_table, ['recall_user_id' => $user_id], ['%d']);
                if ($result === false) {
                    $ok = false;
                }
            }

            if ($ok) {
                $result = $wpdb->delete($user_question_progress_table, ['recall_user_id' => $user_id], ['%d']);
                if ($result === false) {
                    $ok = false;
                }
            }

            if ($ok) {
                $result = $wpdb->delete($group_memberships_table, ['recall_user_id' => $user_id], ['%d']);
                if ($result === false) {
                    $ok = false;
                }
            }

            if ($ok) {
                $result = $wpdb->delete($users_table, ['id' => $user_id], ['%d']);
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
}