<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_Admin {
    private $repository;
    private $actions;
    private $page_dashboard;
    private $page_users;
    private $page_groups;
    private $page_questions;
    private $page_notifications;
    private $page_stats;
    private $page_debug_log;

    public function __construct() {
        $this->repository         = new InSkill_Recall_Admin_Repository();
        $this->actions            = new InSkill_Recall_Admin_Actions($this->repository);
        $this->page_dashboard     = new InSkill_Recall_Admin_Page_Dashboard($this->repository);
        $this->page_users         = new InSkill_Recall_Admin_Page_Users($this->repository);
        $this->page_groups        = new InSkill_Recall_Admin_Page_Groups($this->repository);
        $this->page_questions     = new InSkill_Recall_Admin_Page_Questions($this->repository);
        $this->page_notifications = new InSkill_Recall_Admin_Page_Notifications($this->repository);
        $this->page_stats         = new InSkill_Recall_Admin_Page_Stats($this->repository);
        $this->page_debug_log     = new InSkill_Recall_Admin_Page_Debug_Log($this->repository);

        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'handle_actions']);
    }

    public function register_menu() {
        add_menu_page(
            'InSkill Recall',
            'InSkill Recall',
            'manage_options',
            'inskill-recall',
            [$this->page_dashboard, 'render'],
            'dashicons-welcome-learn-more',
            58
        );

        add_submenu_page('inskill-recall', 'Tableau de bord', 'Tableau de bord', 'manage_options', 'inskill-recall', [$this->page_dashboard, 'render']);
        add_submenu_page('inskill-recall', 'Utilisateurs', 'Utilisateurs', 'manage_options', 'inskill-recall-users', [$this->page_users, 'render']);
        add_submenu_page('inskill-recall', 'Groupes', 'Groupes', 'manage_options', 'inskill-recall-groups', [$this->page_groups, 'render']);
        add_submenu_page('inskill-recall', 'Questions', 'Questions', 'manage_options', 'inskill-recall-questions', [$this->page_questions, 'render']);
        add_submenu_page('inskill-recall', 'Notifications', 'Notifications', 'manage_options', 'inskill-recall-notifications', [$this->page_notifications, 'render']);
        add_submenu_page('inskill-recall', 'Statistiques', 'Statistiques', 'manage_options', 'inskill-recall-stats', [$this->page_stats, 'render']);
        add_submenu_page('inskill-recall', 'Debug log', 'Debug log', 'manage_options', 'inskill-recall-debug-log', [$this->page_debug_log, 'render']);
    }

    public function handle_actions() {
        if (!current_user_can('manage_options') || empty($_POST['inskill_recall_action'])) {
            return;
        }

        check_admin_referer('inskill_recall_admin_action');

        $action = sanitize_text_field(wp_unslash($_POST['inskill_recall_action']));
        $this->actions->dispatch($action);
    }
}