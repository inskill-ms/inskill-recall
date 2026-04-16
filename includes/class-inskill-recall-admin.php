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

    public function __construct() {
        $this->repository         = new InSkill_Recall_Admin_Repository();
        $this->actions            = new InSkill_Recall_Admin_Actions($this->repository);
        $this->page_dashboard     = new InSkill_Recall_Admin_Page_Dashboard($this->repository);
        $this->page_users         = new InSkill_Recall_Admin_Page_Users($this->repository);
        $this->page_groups        = new InSkill_Recall_Admin_Page_Groups($this->repository);
        $this->page_questions     = new InSkill_Recall_Admin_Page_Questions($this->repository);
        $this->page_notifications = new InSkill_Recall_Admin_Page_Notifications($this->repository);
        $this->page_stats         = new InSkill_Recall_Admin_Page_Stats($this->repository);

        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('admin_menu', [$this, 'add_test_push_page']);
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
    }

    public function add_test_push_page() {
        add_submenu_page(
            null,
            'Test Push',
            'Test Push',
            'manage_options',
            'inskill-test-push',
            [$this, 'render_test_push_page']
        );
    }

    public function handle_actions() {
        if (!current_user_can('manage_options') || empty($_POST['inskill_recall_action'])) {
            return;
        }

        check_admin_referer('inskill_recall_admin_action');

        $action = sanitize_text_field(wp_unslash($_POST['inskill_recall_action']));
        $this->actions->dispatch($action);
    }

    public function render_test_push_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Accès refusé.');
        }

        $sent = null;
        $error_message = '';
        $target_user_id = isset($_GET['recall_user_id']) ? (int) $_GET['recall_user_id'] : 2;

        if (isset($_GET['send_test'])) {
            check_admin_referer('inskill_test_push_send');

            $payload = [
                'title' => 'TEST PUSH',
                'body'  => 'Si vous voyez cette notification, le push fonctionne.',
                'url'   => home_url('/'),
                'tag'   => 'inskill-recall-manual-test-' . $target_user_id,
            ];

            $sent = InSkill_Recall_Push::send_test_to_user($target_user_id, $payload);

            if (!$sent) {
                $error_message = 'Aucune notification envoyée. Vérifiez les abonnements push actifs, la configuration VAPID et l’état des subscriptions de cet utilisateur.';
            }
        }

        $test_url = wp_nonce_url(
            admin_url('admin.php?page=inskill-test-push&send_test=1&recall_user_id=' . $target_user_id),
            'inskill_test_push_send'
        );

        echo '<div class="wrap">';
        echo '<h1>Test Push</h1>';
        echo '<p>Cette page permet d’envoyer manuellement une notification push de test à un utilisateur InSkill Recall.</p>';
        echo '<p><strong>Utilisateur ciblé :</strong> ID ' . esc_html((string) $target_user_id) . '</p>';

        if ($sent === true) {
            echo '<div class="notice notice-success"><p>Push envoyé avec succès.</p></div>';
        } elseif ($sent === false && isset($_GET['send_test'])) {
            echo '<div class="notice notice-error"><p>' . esc_html($error_message) . '</p></div>';
        }

        echo '<p><a href="' . esc_url($test_url) . '" class="button button-primary">Envoyer test push</a></p>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=inskill-recall-notifications')) . '" class="button">Retour aux notifications</a></p>';
        echo '</div>';
    }
}