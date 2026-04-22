<?php
if (!defined('ABSPATH')) {
    exit;
}

abstract class InSkill_Recall_Admin_Repository_Dashboard extends InSkill_Recall_Admin_Repository_Base {
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
}