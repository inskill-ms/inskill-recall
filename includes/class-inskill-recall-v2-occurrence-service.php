<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_V2_Occurrence_Service {
    public static function get_table() {
        return InSkill_Recall_DB::table('question_occurrences');
    }

    public static function now_mysql() {
        return InSkill_Recall_Time::now_mysql();
    }

    public static function today_date() {
        return InSkill_Recall_Time::today_date();
    }

    public static function get_occurrence($occurrence_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::get_table() . " WHERE id = %d LIMIT 1",
            (int) $occurrence_id
        ));
    }

    public static function get_occurrence_for_progress_and_date($progress_id, $scheduled_date) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::get_table() . " WHERE progress_id = %d AND scheduled_date = %s LIMIT 1",
            (int) $progress_id,
            $scheduled_date
        ));
    }

    public static function ensure_occurrence_exists($progress, $scheduled_date = null, $occurrence_type = 'review') {
        global $wpdb;

        if (!$progress) {
            return null;
        }

        if (!$scheduled_date) {
            $scheduled_date = InSkill_Recall_V2_Progress_Service::today_date();
        }

        $existing = self::get_occurrence_for_progress_and_date((int) $progress->id, $scheduled_date);
        if ($existing) {
            return $existing;
        }

        $now = self::now_mysql();

        $wpdb->insert(self::get_table(), [
            'group_id'                => (int) $progress->group_id,
            'recall_user_id'          => (int) $progress->recall_user_id,
            'question_id'             => (int) $progress->question_id,
            'progress_id'             => (int) $progress->id,
            'scheduled_date'          => (string) $scheduled_date,
            'scheduled_at'            => InSkill_Recall_V2_Progress_Service::due_datetime_for_date($scheduled_date),
            'display_level'           => (string) $progress->current_level,
            'effective_level'         => null,
            'occurrence_type'         => (string) $occurrence_type,
            'status'                  => 'pending',
            'answered_at'             => null,
            'selected_choice_ids_json'=> null,
            'correct_choice_ids_json' => null,
            'points_awarded'          => 0,
            'speed_bonus_awarded'     => 0,
            'penalty_applied'         => 0,
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);

        InSkill_Recall_V2_Progress_Service::mark_presented((int) $progress->id, $scheduled_date);

        return self::get_occurrence((int) $wpdb->insert_id);
    }

    public static function get_pending_occurrences_for_date($group_id, $recall_user_id, $scheduled_date) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT *
             FROM " . self::get_table() . "
             WHERE group_id = %d
               AND recall_user_id = %d
               AND scheduled_date = %s
               AND status = 'pending'
             ORDER BY scheduled_at ASC, id ASC",
            (int) $group_id,
            (int) $recall_user_id,
            (string) $scheduled_date
        ));
    }

    public static function get_pending_occurrence_for_progress_and_date($progress_id, $scheduled_date) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT *
             FROM " . self::get_table() . "
             WHERE progress_id = %d
               AND scheduled_date = %s
               AND status = 'pending'
             LIMIT 1",
            (int) $progress_id,
            (string) $scheduled_date
        ));
    }

    public static function get_user_occurrences_until_today($group_id, $recall_user_id, $today = null) {
        global $wpdb;

        if (!$today) {
            $today = self::today_date();
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT o.*, q.question_text
             FROM " . self::get_table() . " o
             INNER JOIN " . InSkill_Recall_DB::table('questions') . " q ON q.id = o.question_id
             WHERE o.group_id = %d
               AND o.recall_user_id = %d
               AND o.scheduled_date <= %s
             ORDER BY o.scheduled_date ASC, o.scheduled_at ASC, o.id ASC",
            (int) $group_id,
            (int) $recall_user_id,
            $today
        ));
    }

    public static function get_upcoming_occurrences($group_id, $recall_user_id, $from_date = null) {
        global $wpdb;

        if (!$from_date) {
            $from_date = self::today_date();
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT o.*, p.current_level, q.question_text
             FROM " . self::get_table() . " o
             INNER JOIN " . InSkill_Recall_DB::table('user_question_progress') . " p ON p.id = o.progress_id
             INNER JOIN " . InSkill_Recall_DB::table('questions') . " q ON q.id = o.question_id
             WHERE o.group_id = %d
               AND o.recall_user_id = %d
               AND o.scheduled_date >= %s
             ORDER BY o.scheduled_date ASC, o.scheduled_at ASC, o.id ASC",
            (int) $group_id,
            (int) $recall_user_id,
            $from_date
        ));
    }

    public static function mark_answered_correct($occurrence_id, array $selected_choice_ids, array $correct_choice_ids, $effective_level, $points_awarded, $speed_bonus_awarded) {
        global $wpdb;

        return false !== $wpdb->update(
            self::get_table(),
            [
                'status'                   => 'answered_correct',
                'answered_at'              => self::now_mysql(),
                'effective_level'          => $effective_level,
                'selected_choice_ids_json' => wp_json_encode(array_values($selected_choice_ids)),
                'correct_choice_ids_json'  => wp_json_encode(array_values($correct_choice_ids)),
                'points_awarded'           => (int) $points_awarded,
                'speed_bonus_awarded'      => (int) $speed_bonus_awarded,
                'updated_at'               => self::now_mysql(),
            ],
            ['id' => (int) $occurrence_id]
        );
    }

    public static function mark_answered_incorrect($occurrence_id, array $selected_choice_ids, array $correct_choice_ids, $effective_level) {
        global $wpdb;

        return false !== $wpdb->update(
            self::get_table(),
            [
                'status'                   => 'answered_incorrect',
                'answered_at'              => self::now_mysql(),
                'effective_level'          => $effective_level,
                'selected_choice_ids_json' => wp_json_encode(array_values($selected_choice_ids)),
                'correct_choice_ids_json'  => wp_json_encode(array_values($correct_choice_ids)),
                'points_awarded'           => 0,
                'speed_bonus_awarded'      => 0,
                'updated_at'               => self::now_mysql(),
            ],
            ['id' => (int) $occurrence_id]
        );
    }

    public static function mark_unanswered($occurrence_id, $effective_level, $penalty_applied = 1) {
        global $wpdb;

        return false !== $wpdb->update(
            self::get_table(),
            [
                'status'          => 'unanswered',
                'effective_level' => $effective_level,
                'penalty_applied' => (int) $penalty_applied,
                'updated_at'      => self::now_mysql(),
            ],
            ['id' => (int) $occurrence_id]
        );
    }

    public static function get_today_front_queue($group_id, $recall_user_id, $today = null) {
        global $wpdb;

        if (!$today) {
            $today = self::today_date();
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                o.*,
                p.current_level,
                p.current_state,
                p.question_order_index,
                p.downgrade_on_date,
                q.question_text
             FROM " . self::get_table() . " o
             INNER JOIN " . InSkill_Recall_DB::table('user_question_progress') . " p ON p.id = o.progress_id
             INNER JOIN " . InSkill_Recall_DB::table('questions') . " q ON q.id = o.question_id
             WHERE o.group_id = %d
               AND o.recall_user_id = %d
               AND o.scheduled_date <= %s
             ORDER BY o.scheduled_date ASC, o.scheduled_at ASC, o.id ASC",
            (int) $group_id,
            (int) $recall_user_id,
            $today
        ));

        usort($rows, function ($a, $b) use ($today) {
            $priorityA = self::compute_front_priority($a, $today);
            $priorityB = self::compute_front_priority($b, $today);

            if ($priorityA !== $priorityB) {
                return $priorityA <=> $priorityB;
            }

            $levelRankA = InSkill_Recall_V2_Progress_Service::get_level_rank($a->current_level);
            $levelRankB = InSkill_Recall_V2_Progress_Service::get_level_rank($b->current_level);

            if ($levelRankA !== $levelRankB) {
                return $levelRankB <=> $levelRankA;
            }

            if ($a->scheduled_date !== $b->scheduled_date) {
                return strcmp($a->scheduled_date, $b->scheduled_date);
            }

            return ((int) $a->question_order_index) <=> ((int) $b->question_order_index);
        });

        return $rows;
    }

    public static function compute_front_priority($row, $today) {
        $tomorrow = InSkill_Recall_V2_Progress_Service::add_days($today, 1);

        if ((string) $row->scheduled_date < $today) {
            return 1;
        }

        if (!empty($row->downgrade_on_date) && (string) $row->downgrade_on_date === $today) {
            return 1;
        }

        if (!empty($row->downgrade_on_date) && (string) $row->downgrade_on_date === $tomorrow) {
            return 2;
        }

        return 3;
    }
}