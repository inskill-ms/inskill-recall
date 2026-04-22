<?php
if (!defined('ABSPATH')) {
    exit;
}

abstract class InSkill_Recall_Admin_Actions_Groups extends InSkill_Recall_Admin_Actions_Users {
    protected function save_group() {
        $group_id = isset($_POST['group_id']) ? (int) $_POST['group_id'] : 0;

        $data = [
            'name'                => isset($_POST['name']) ? wp_unslash($_POST['name']) : '',
            'description'         => isset($_POST['description']) ? wp_unslash($_POST['description']) : '',
            'start_date'          => isset($_POST['start_date']) ? wp_unslash($_POST['start_date']) : '',
            'status'              => isset($_POST['status']) ? wp_unslash($_POST['status']) : 'active',
            'leaderboard_mode'    => isset($_POST['leaderboard_mode']) ? wp_unslash($_POST['leaderboard_mode']) : 'B',
            'question_order_mode' => isset($_POST['question_order_mode']) ? wp_unslash($_POST['question_order_mode']) : 'ordered',
        ];

        $member_ids = isset($_POST['member_ids']) ? (array) $_POST['member_ids'] : [];
        $member_ids = array_values(array_unique(array_map('intval', $member_ids)));

        if ($group_id > 0) {
            $updated = $this->repository->update_group($group_id, $data);
            if ($updated === false) {
                $this->redirect('inskill-recall-groups', ['message' => 'group_update_error']);
            }

            $this->repository->replace_group_members($group_id, $member_ids);
            $this->redirect('inskill-recall-groups', ['message' => 'group_updated']);
        }

        $new_group_id = $this->repository->create_group($data);
        if ($new_group_id > 0) {
            $this->repository->replace_group_members($new_group_id, $member_ids);
        }

        $this->redirect('inskill-recall-groups', ['message' => $new_group_id ? 'group_created' : 'group_create_error']);
    }

    protected function delete_group() {
        $group_id = isset($_POST['group_id']) ? (int) $_POST['group_id'] : 0;

        if ($group_id > 0) {
            $deleted = $this->repository->delete_group($group_id);
            $this->redirect('inskill-recall-groups', ['message' => $deleted ? 'group_deleted' : 'group_delete_error']);
        }

        $this->redirect('inskill-recall-groups', ['message' => 'group_delete_error']);
    }

    protected function duplicate_group() {
        $group_id = isset($_POST['group_id']) ? (int) $_POST['group_id'] : 0;

        if ($group_id <= 0) {
            $this->redirect('inskill-recall-groups', ['message' => 'group_duplicate_error']);
        }

        $new_group_id = $this->repository->duplicate_group($group_id);

        $this->redirect('inskill-recall-groups', [
            'message'    => $new_group_id > 0 ? 'group_duplicated' : 'group_duplicate_error',
            'edit_group' => $new_group_id > 0 ? $new_group_id : 0,
        ]);
    }
}