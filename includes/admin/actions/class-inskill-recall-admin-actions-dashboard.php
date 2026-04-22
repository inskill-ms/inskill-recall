<?php
if (!defined('ABSPATH')) {
    exit;
}

abstract class InSkill_Recall_Admin_Actions_Dashboard extends InSkill_Recall_Admin_Actions_Base {
    protected function create_dashboard_page() {
        $page_id = InSkill_Recall_Frontend::ensure_dashboard_page_exists();
        $this->redirect('inskill-recall', ['message' => $page_id > 0 ? 'dashboard_page_created' : 'dashboard_page_error']);
    }
}