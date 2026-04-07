<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_V2_Status_Service {
    public static function compute_participant_status_from_values($total_questions, $mastered_questions, $last_answer_at) {
        if ((int) $total_questions > 0 && (int) $mastered_questions >= (int) $total_questions) {
            return 'finished';
        }

        if (empty($last_answer_at)) {
            return 'active';
        }

        try {
            $last = new DateTimeImmutable($last_answer_at, wp_timezone());
            $now = new DateTimeImmutable(InSkill_Recall_Time::now_mysql(), wp_timezone());
            $diffDays = (int) $last->diff($now)->format('%a');

            if ($diffDays >= 5) {
                return 'inactive';
            }
        } catch (Exception $e) {
            return 'active';
        }

        return 'active';
    }
}