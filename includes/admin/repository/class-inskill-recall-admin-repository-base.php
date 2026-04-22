<?php
if (!defined('ABSPATH')) {
    exit;
}

abstract class InSkill_Recall_Admin_Repository_Base {
    public function table($name) {
        return InSkill_Recall_DB::table($name);
    }

    public function now() {
        return current_time('mysql');
    }
}