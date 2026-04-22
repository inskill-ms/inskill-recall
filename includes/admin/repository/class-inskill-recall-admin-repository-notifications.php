<?php
if (!defined('ABSPATH')) {
    exit;
}

abstract class InSkill_Recall_Admin_Repository_Notifications extends InSkill_Recall_Admin_Repository_Questions {
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