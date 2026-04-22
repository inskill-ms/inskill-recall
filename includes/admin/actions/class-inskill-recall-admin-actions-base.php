<?php
if (!defined('ABSPATH')) {
    exit;
}

abstract class InSkill_Recall_Admin_Actions_Base {
    protected $repository;

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
            case 'send_test_push_notification':
                $this->send_test_push_notification();
                break;
        }
    }

    protected function redirect($page, array $args = []) {
        $url = add_query_arg(array_merge(['page' => $page], $args), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    abstract protected function create_dashboard_page();
    abstract protected function save_test_datetime();
    abstract protected function clear_test_datetime();
    abstract protected function run_test_engine_now();
    abstract protected function save_user();
    abstract protected function delete_user();
    abstract protected function regenerate_user_token();
    abstract protected function save_group();
    abstract protected function delete_group();
    abstract protected function duplicate_group();
    abstract protected function save_question();
    abstract protected function delete_question();
    abstract protected function deactivate_question();
    abstract protected function save_notification_settings();
    abstract protected function clear_notification_logs();
    abstract protected function send_test_push_notification();
}