<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_V2_Scoring_Service {
    public static function get_stats_table() {
        return InSkill_Recall_DB::table('user_group_stats');
    }

    public static function has_already_awarded_level_points($progress, $level) {
        if (!$progress) {
            return false;
        }

        switch ($level) {
            case InSkill_Recall_V2_Progress_Service::LEVEL_NV1:
                return !empty($progress->awarded_nv1_points);
            case InSkill_Recall_V2_Progress_Service::LEVEL_NV2:
                return !empty($progress->awarded_nv2_points);
            case InSkill_Recall_V2_Progress_Service::LEVEL_NV3:
                return !empty($progress->awarded_nv3_points);
            case InSkill_Recall_V2_Progress_Service::LEVEL_NV4:
                return !empty($progress->awarded_nv4_points);
            case InSkill_Recall_V2_Progress_Service::LEVEL_NV5:
                return !empty($progress->awarded_nv5_points);
        }

        return true;
    }

    public static function compute_level_points_to_award($progress, $new_level) {
        if (self::has_already_awarded_level_points($progress, $new_level)) {
            return 0;
        }

        return InSkill_Recall_V2_Progress_Service::get_level_points($new_level);
    }

    public static function compute_speed_bonus($occurrence) {
        if (!$occurrence) {
            return 0;
        }

        $today = InSkill_Recall_V2_Progress_Service::today_date();

        return ((string) $occurrence->scheduled_date === $today) ? 5 : 0;
    }

    public static function get_or_create_stats_row($group_id, $recall_user_id) {
        global $wpdb;

        $table = self::get_stats_table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE group_id = %d AND recall_user_id = %d LIMIT 1",
            (int) $group_id,
            (int) $recall_user_id
        ));

        if ($row) {
            return $row;
        }

        $wpdb->insert($table, [
            'group_id'              => (int) $group_id,
            'recall_user_id'        => (int) $recall_user_id,
            'participant_status'    => 'active',
            'total_questions'       => 0,
            'introduced_questions'  => 0,
            'mastered_questions'    => 0,
            'score_total'           => 0,
            'speed_bonus_total'     => 0,
            'penalty_total'         => 0,
            'answers_total'         => 0,
            'correct_total'         => 0,
            'incorrect_total'       => 0,
            'unanswered_total'      => 0,
            'last_answer_at'        => null,
            'last_activity_at'      => null,
            'cached_rank'           => null,
            'updated_at'            => current_time('mysql'),
        ]);

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE group_id = %d AND recall_user_id = %d LIMIT 1",
            (int) $group_id,
            (int) $recall_user_id
        ));
    }

    public static function recalculate_user_group_stats($group_id, $recall_user_id) {
        global $wpdb;

        $stats_table = self::get_stats_table();
        $progress_table = InSkill_Recall_DB::table('user_question_progress');
        $questions_table = InSkill_Recall_DB::table('questions');

        self::get_or_create_stats_row($group_id, $recall_user_id);

        $progress_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$progress_table} WHERE group_id = %d AND recall_user_id = %d",
            (int) $group_id,
            (int) $recall_user_id
        ));

        $total_questions = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$questions_table}
             WHERE group_id = %d
               AND status = 'active'",
            (int) $group_id
        ));

        $introduced_questions = count($progress_rows);
        $mastered_questions = 0;
        $score_total = 0;
        $speed_bonus_total = 0;
        $penalty_total = 0;
        $answers_total = 0;
        $correct_total = 0;
        $incorrect_total = 0;
        $unanswered_total = 0;
        $last_answer_at = null;
        $last_activity_at = null;

        foreach ($progress_rows as $row) {
            if ($row->current_level === InSkill_Recall_V2_Progress_Service::LEVEL_MASTERED) {
                $mastered_questions++;
            }

            if (!empty($row->awarded_nv1_points)) {
                $score_total += InSkill_Recall_V2_Progress_Service::get_level_points(InSkill_Recall_V2_Progress_Service::LEVEL_NV1);
            }
            if (!empty($row->awarded_nv2_points)) {
                $score_total += InSkill_Recall_V2_Progress_Service::get_level_points(InSkill_Recall_V2_Progress_Service::LEVEL_NV2);
            }
            if (!empty($row->awarded_nv3_points)) {
                $score_total += InSkill_Recall_V2_Progress_Service::get_level_points(InSkill_Recall_V2_Progress_Service::LEVEL_NV3);
            }
            if (!empty($row->awarded_nv4_points)) {
                $score_total += InSkill_Recall_V2_Progress_Service::get_level_points(InSkill_Recall_V2_Progress_Service::LEVEL_NV4);
            }
            if (!empty($row->awarded_nv5_points)) {
                $score_total += InSkill_Recall_V2_Progress_Service::get_level_points(InSkill_Recall_V2_Progress_Service::LEVEL_NV5);
            }

            $speed_bonus_total += (int) $row->speed_bonus_count;
            $penalty_total += (int) $row->penalty_points_total;
            $answers_total += (int) $row->total_answers_count;
            $correct_total += (int) $row->total_correct_count;
            $incorrect_total += (int) $row->total_incorrect_count;
            $unanswered_total += (int) $row->total_unanswered_count;

            if (!empty($row->last_answered_at) && ($last_answer_at === null || $row->last_answered_at > $last_answer_at)) {
                $last_answer_at = $row->last_answered_at;
            }

            if (!empty($row->updated_at) && ($last_activity_at === null || $row->updated_at > $last_activity_at)) {
                $last_activity_at = $row->updated_at;
            }
        }

        $score_total += $speed_bonus_total;
        $score_total -= $penalty_total;

        $participant_status = InSkill_Recall_V2_Status_Service::compute_participant_status_from_values(
            $total_questions,
            $mastered_questions,
            $last_answer_at
        );

        $wpdb->update(
            $stats_table,
            [
                'participant_status'   => $participant_status,
                'total_questions'      => $total_questions,
                'introduced_questions' => $introduced_questions,
                'mastered_questions'   => $mastered_questions,
                'score_total'          => $score_total,
                'speed_bonus_total'    => $speed_bonus_total,
                'penalty_total'        => $penalty_total,
                'answers_total'        => $answers_total,
                'correct_total'        => $correct_total,
                'incorrect_total'      => $incorrect_total,
                'unanswered_total'     => $unanswered_total,
                'last_answer_at'       => $last_answer_at,
                'last_activity_at'     => $last_activity_at,
                'updated_at'           => current_time('mysql'),
            ],
            [
                'group_id'       => (int) $group_id,
                'recall_user_id' => (int) $recall_user_id,
            ]
        );

        self::recalculate_group_ranks($group_id);
    }

    public static function recalculate_group_ranks($group_id) {
        global $wpdb;

        $stats_table = self::get_stats_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT *
             FROM {$stats_table}
             WHERE group_id = %d
             ORDER BY score_total DESC, last_answer_at DESC, recall_user_id ASC",
            (int) $group_id
        ));

        $rank = 0;
        $position = 0;
        $previous_score = null;

        foreach ($rows as $row) {
            $position++;

            if ($previous_score === null || (int) $row->score_total !== (int) $previous_score) {
                $rank = $position;
                $previous_score = (int) $row->score_total;
            }

            $wpdb->update(
                $stats_table,
                [
                    'cached_rank' => $rank,
                    'updated_at'  => current_time('mysql'),
                ],
                ['id' => (int) $row->id]
            );
        }
    }
}