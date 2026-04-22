<?php
if (!defined('ABSPATH')) {
    exit;
}

abstract class InSkill_Recall_Admin_Actions_Test_Time extends InSkill_Recall_Admin_Actions_Dashboard {
    protected function save_test_datetime() {
        $raw = isset($_POST['test_datetime']) ? wp_unslash($_POST['test_datetime']) : '';
        $normalized = InSkill_Recall_Time::parse_datetime_local_input($raw);

        if ($normalized === '') {
            $this->redirect('inskill-recall', ['message' => 'test_datetime_error']);
        }

        InSkill_Recall_Time::set_forced_datetime($normalized);
        $this->redirect('inskill-recall', ['message' => 'test_datetime_saved']);
    }

    protected function clear_test_datetime() {
        InSkill_Recall_Time::clear_forced_datetime();
        $this->redirect('inskill-recall', ['message' => 'test_datetime_cleared']);
    }

    protected function run_test_engine_now() {
        try {
            InSkill_Recall_V2_Engine::close_pending_occurrences_for_previous_days();
            InSkill_Recall_V2_Engine::run_midday_downgrades();
            InSkill_Recall_V2_Engine::prepare_all_due_occurrences_for_today();

            $this->redirect('inskill-recall', ['message' => 'test_engine_ran']);
        } catch (Throwable $e) {
            error_log('[InSkill Recall] run_test_engine_now failed: ' . $e->getMessage());
            $this->redirect('inskill-recall', ['message' => 'test_engine_error']);
        }
    }
}