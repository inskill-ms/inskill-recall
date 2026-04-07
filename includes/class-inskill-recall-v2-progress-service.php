<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_V2_Progress_Service {
    const LEVEL_NV0      = 'nv0';
    const LEVEL_NV1      = 'nv1';
    const LEVEL_NV2      = 'nv2';
    const LEVEL_NV3      = 'nv3';
    const LEVEL_NV4      = 'nv4';
    const LEVEL_NV5      = 'nv5';
    const LEVEL_MASTERED = 'mastered';

    const STATE_ACTIVE   = 'active';
    const STATE_OVERDUE  = 'overdue';
    const STATE_MASTERED = 'mastered';

    public static function get_table() {
        return InSkill_Recall_DB::table('user_question_progress');
    }

    public static function now_mysql() {
        return InSkill_Recall_Time::now_mysql();
    }

    public static function today_date() {
        return InSkill_Recall_Time::today_date();
    }

    public static function due_datetime_for_date($date) {
        return $date . ' 00:01:00';
    }

    public static function downgrade_datetime_for_date($date) {
        return $date . ' 12:00:00';
    }

    public static function add_days($date, $days) {
        try {
            $dt = new DateTimeImmutable($date, wp_timezone());
            return $dt->modify(($days >= 0 ? '+' : '') . (int) $days . ' days')->format('Y-m-d');
        } catch (Exception $e) {
            return $date;
        }
    }

    public static function get_level_rank($level) {
        switch ((string) $level) {
            case self::LEVEL_NV5:
                return 5;
            case self::LEVEL_NV4:
                return 4;
            case self::LEVEL_NV3:
                return 3;
            case self::LEVEL_NV2:
                return 2;
            case self::LEVEL_NV1:
                return 1;
            case self::LEVEL_MASTERED:
                return 6;
            case self::LEVEL_NV0:
            default:
                return 0;
        }
    }

    public static function get_level_points($level) {
        switch ((string) $level) {
            case self::LEVEL_NV1:
                return 10;
            case self::LEVEL_NV2:
                return 20;
            case self::LEVEL_NV3:
                return 30;
            case self::LEVEL_NV4:
                return 40;
            case self::LEVEL_NV5:
                return 50;
            default:
                return 0;
        }
    }

    public static function get_progress($progress_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::get_table() . " WHERE id = %d LIMIT 1",
            (int) $progress_id
        ));
    }

    public static function count_progress_rows($group_id, $recall_user_id) {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM " . self::get_table() . "
             WHERE group_id = %d
               AND recall_user_id = %d",
            (int) $group_id,
            (int) $recall_user_id
        ));
    }

    public static function get_due_progress_rows($group_id, $recall_user_id, $today) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT *
             FROM " . self::get_table() . "
             WHERE group_id = %d
               AND recall_user_id = %d
               AND current_state != %s
               AND next_due_date IS NOT NULL
               AND next_due_date <= %s
             ORDER BY next_due_date ASC, question_order_index ASC, id ASC",
            (int) $group_id,
            (int) $recall_user_id,
            self::STATE_MASTERED,
            $today
        ));
    }

    public static function normalize_internal_label_for_sort($label) {
        $label = strtoupper(trim((string) $label));

        if ($label === '') {
            return 'ZZZZZZZZZZ';
        }

        if (preg_match('/^Q(\d{7})(?:-(\d+))?$/', $label, $matches)) {
            $base = $matches[1];
            $suffix = isset($matches[2]) ? str_pad((string) (int) $matches[2], 6, '0', STR_PAD_LEFT) : '000000';
            return $base . '-' . $suffix;
        }

        return 'ZZZZZZZZZZ-' . $label;
    }

    public static function get_next_never_seen_questions($group_id, $recall_user_id, $limit = 1, $question_order_mode = 'ordered') {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT q.*
             FROM " . InSkill_Recall_DB::table('questions') . " q
             WHERE q.group_id = %d
               AND q.status = 'active'
               AND q.id NOT IN (
                    SELECT p.question_id
                    FROM " . self::get_table() . " p
                    WHERE p.group_id = %d
                      AND p.recall_user_id = %d
               )",
            (int) $group_id,
            (int) $group_id,
            (int) $recall_user_id
        ));

        if (empty($rows)) {
            return [];
        }

        if ($question_order_mode === 'random') {
            shuffle($rows);
            return array_slice($rows, 0, (int) $limit);
        }

        usort($rows, function ($a, $b) {
            $aKey = self::normalize_internal_label_for_sort(isset($a->internal_label) ? $a->internal_label : '');
            $bKey = self::normalize_internal_label_for_sort(isset($b->internal_label) ? $b->internal_label : '');

            if ($aKey !== $bKey) {
                return strcmp($aKey, $bKey);
            }

            return ((int) $a->id) <=> ((int) $b->id);
        });

        return array_slice($rows, 0, (int) $limit);
    }

    public static function get_remaining_never_seen_count($group_id, $recall_user_id) {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(q.id)
             FROM " . InSkill_Recall_DB::table('questions') . " q
             WHERE q.group_id = %d
               AND q.status = 'active'
               AND q.id NOT IN (
                    SELECT p.question_id
                    FROM " . self::get_table() . " p
                    WHERE p.group_id = %d
                      AND p.recall_user_id = %d
               )",
            (int) $group_id,
            (int) $group_id,
            (int) $recall_user_id
        ));
    }

    public static function create_initial_progress($group_id, $recall_user_id, $question_id, $question_order_index, $today, $chain_number = null, $parent_progress_id = null) {
        global $wpdb;

        $table = self::get_table();
        $now = self::now_mysql();

        $wpdb->insert($table, [
            'group_id'                    => (int) $group_id,
            'recall_user_id'              => (int) $recall_user_id,
            'question_id'                 => (int) $question_id,
            'question_order_index'        => (int) $question_order_index,
            'is_initially_assigned'       => $parent_progress_id ? 0 : 1,
            'injection_chain_number'      => $chain_number !== null ? (int) $chain_number : null,
            'parent_progress_id'          => $parent_progress_id !== null ? (int) $parent_progress_id : null,
            'current_level'               => self::LEVEL_NV0,
            'current_state'               => self::STATE_ACTIVE,
            'first_presented_at'          => null,
            'first_answered_at'           => null,
            'last_presented_at'           => null,
            'last_answered_at'            => null,
            'last_result'                 => null,
            'next_due_date'               => $today,
            'next_due_at'                 => self::due_datetime_for_date($today),
            'downgrade_on_date'           => null,
            'downgrade_at'                => null,
            'consecutive_unanswered_days' => 0,
            'total_presentations_count'   => 0,
            'total_answers_count'         => 0,
            'total_correct_count'         => 0,
            'total_incorrect_count'       => 0,
            'total_unanswered_count'      => 0,
            'awarded_nv1_points'          => 0,
            'awarded_nv2_points'          => 0,
            'awarded_nv3_points'          => 0,
            'awarded_nv4_points'          => 0,
            'awarded_nv5_points'          => 0,
            'speed_bonus_count'           => 0,
            'penalty_points_total'        => 0,
            'mastered_at'                 => null,
            'created_at'                  => $now,
            'updated_at'                  => $now,
        ]);

        return self::get_progress((int) $wpdb->insert_id);
    }

    public static function get_chain_tip($group_id, $recall_user_id, $chain_number) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT *
             FROM " . self::get_table() . "
             WHERE group_id = %d
               AND recall_user_id = %d
               AND injection_chain_number = %d
             ORDER BY created_at DESC, id DESC
             LIMIT 1",
            (int) $group_id,
            (int) $recall_user_id,
            (int) $chain_number
        ));
    }

    public static function chain_has_child($progress_id) {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM " . self::get_table() . "
             WHERE parent_progress_id = %d",
            (int) $progress_id
        )) > 0;
    }

    public static function get_unlock_date_from_first_answer($first_answered_at) {
        if (empty($first_answered_at)) {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($first_answered_at, wp_timezone());
            return $dt->modify('+3 days')->format('Y-m-d');
        } catch (Exception $e) {
            return null;
        }
    }

    public static function get_rows_due_for_downgrade($today) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT *
             FROM " . self::get_table() . "
             WHERE current_state != %s
               AND downgrade_on_date = %s",
            self::STATE_MASTERED,
            $today
        ));
    }

    public static function mark_presented($progress_id, $presented_date = null) {
        global $wpdb;

        $progress_id = (int) $progress_id;
        if ($progress_id <= 0) {
            return false;
        }

        if (!$presented_date) {
            $presented_date = self::today_date();
        }

        $progress = self::get_progress($progress_id);
        if (!$progress) {
            return false;
        }

        $presented_at = self::due_datetime_for_date($presented_date);
        $total_presentations_count = isset($progress->total_presentations_count)
            ? ((int) $progress->total_presentations_count + 1)
            : 1;

        $first_presented_at = !empty($progress->first_presented_at)
            ? $progress->first_presented_at
            : $presented_at;

        return false !== $wpdb->update(
            self::get_table(),
            [
                'first_presented_at'        => $first_presented_at,
                'last_presented_at'         => $presented_at,
                'total_presentations_count' => $total_presentations_count,
                'updated_at'                => self::now_mysql(),
            ],
            ['id' => $progress_id]
        );
    }

    public static function get_correct_transition($current_level, $answer_date) {
        $current_level = (string) $current_level;
        $answer_date = (string) $answer_date;

        switch ($current_level) {
            case self::LEVEL_NV0:
                $next_level = self::LEVEL_NV1;
                break;
            case self::LEVEL_NV1:
                $next_level = self::LEVEL_NV2;
                break;
            case self::LEVEL_NV2:
                $next_level = self::LEVEL_NV3;
                break;
            case self::LEVEL_NV3:
                $next_level = self::LEVEL_NV4;
                break;
            case self::LEVEL_NV4:
                $next_level = self::LEVEL_NV5;
                break;
            case self::LEVEL_NV5:
                $next_level = self::LEVEL_MASTERED;
                break;
            case self::LEVEL_MASTERED:
            default:
                $next_level = self::LEVEL_MASTERED;
                break;
        }

        $next_due_date = ($next_level !== self::LEVEL_MASTERED)
            ? self::compute_next_due_date_after_answer($next_level, $answer_date)
            : null;

        $downgrade_on_date = ($next_level !== self::LEVEL_MASTERED && $next_due_date)
            ? self::compute_downgrade_date_for_level($next_level, $next_due_date)
            : null;

        return [
            'current_level'     => $current_level,
            'next_level'        => $next_level,
            'next_due_date'     => $next_due_date,
            'downgrade_on_date' => $downgrade_on_date,
        ];
    }

    public static function apply_answer_result($progress_id, $is_correct, $today, $new_level, $awarded_points = 0, $speed_bonus_awarded = 0) {
        global $wpdb;

        $progress = self::get_progress($progress_id);
        if (!$progress) {
            return false;
        }

        $data = [
            'current_level'               => $new_level,
            'current_state'               => ($new_level === self::LEVEL_MASTERED) ? self::STATE_MASTERED : self::STATE_ACTIVE,
            'last_answered_at'            => self::now_mysql(),
            'last_result'                 => $is_correct ? 'correct' : 'incorrect',
            'consecutive_unanswered_days' => 0,
            'total_answers_count'         => (int) $progress->total_answers_count + 1,
            'total_correct_count'         => (int) $progress->total_correct_count + ($is_correct ? 1 : 0),
            'total_incorrect_count'       => (int) $progress->total_incorrect_count + ($is_correct ? 0 : 1),
            'updated_at'                  => self::now_mysql(),
        ];

        if (empty($progress->first_answered_at)) {
            $data['first_answered_at'] = self::now_mysql();
        }

        if ($new_level === self::LEVEL_MASTERED) {
            $data['mastered_at'] = self::now_mysql();
            $data['next_due_date'] = null;
            $data['next_due_at'] = null;
            $data['downgrade_on_date'] = null;
            $data['downgrade_at'] = null;
        } else {
            $nextDate = self::compute_next_due_date_after_answer($new_level, $today);
            $data['next_due_date'] = $nextDate;
            $data['next_due_at'] = self::due_datetime_for_date($nextDate);
            $data['downgrade_on_date'] = self::compute_downgrade_date_for_level($new_level, $nextDate);
            $data['downgrade_at'] = $data['downgrade_on_date'] ? self::downgrade_datetime_for_date($data['downgrade_on_date']) : null;
        }

        if ($awarded_points > 0) {
            switch ($new_level) {
                case self::LEVEL_NV1:
                    $data['awarded_nv1_points'] = 1;
                    break;
                case self::LEVEL_NV2:
                    $data['awarded_nv2_points'] = 1;
                    break;
                case self::LEVEL_NV3:
                    $data['awarded_nv3_points'] = 1;
                    break;
                case self::LEVEL_NV4:
                    $data['awarded_nv4_points'] = 1;
                    break;
                case self::LEVEL_NV5:
                    $data['awarded_nv5_points'] = 1;
                    break;
            }
        }

        if ($speed_bonus_awarded > 0) {
            $data['speed_bonus_count'] = (int) $progress->speed_bonus_count + 1;
        }

        return false !== $wpdb->update(
            self::get_table(),
            $data,
            ['id' => (int) $progress_id]
        );
    }

    public static function apply_correct_answer($progress_id, $today, $speed_bonus_awarded = 0) {
        $progress = self::get_progress($progress_id);
        if (!$progress) {
            return false;
        }

        $transition = self::get_correct_transition((string) $progress->current_level, (string) $today);
        $awarded_points = 0;

        switch ($transition['next_level']) {
            case self::LEVEL_NV1:
                $awarded_points = empty($progress->awarded_nv1_points) ? self::get_level_points(self::LEVEL_NV1) : 0;
                break;
            case self::LEVEL_NV2:
                $awarded_points = empty($progress->awarded_nv2_points) ? self::get_level_points(self::LEVEL_NV2) : 0;
                break;
            case self::LEVEL_NV3:
                $awarded_points = empty($progress->awarded_nv3_points) ? self::get_level_points(self::LEVEL_NV3) : 0;
                break;
            case self::LEVEL_NV4:
                $awarded_points = empty($progress->awarded_nv4_points) ? self::get_level_points(self::LEVEL_NV4) : 0;
                break;
            case self::LEVEL_NV5:
                $awarded_points = empty($progress->awarded_nv5_points) ? self::get_level_points(self::LEVEL_NV5) : 0;
                break;
        }

        return self::apply_answer_result(
            $progress_id,
            true,
            $today,
            $transition['next_level'],
            $awarded_points,
            (int) $speed_bonus_awarded
        );
    }

    public static function apply_incorrect_answer($progress_id, $today) {
        return self::apply_answer_result(
            $progress_id,
            false,
            $today,
            self::LEVEL_NV0,
            0,
            0
        );
    }

    public static function apply_unanswered($progress_id, $today, $penalty = 1) {
        global $wpdb;

        $progress = self::get_progress($progress_id);
        if (!$progress) {
            return false;
        }

        $newCount = (int) $progress->consecutive_unanswered_days + 1;
        $newLevel = (string) $progress->current_level;

        if (in_array($newLevel, [self::LEVEL_NV1, self::LEVEL_NV2, self::LEVEL_NV3], true) && $newCount >= 4) {
            $newLevel = self::compute_midday_downgraded_level($progress->current_level);
            $newCount = 0;
        } elseif (in_array($newLevel, [self::LEVEL_NV4, self::LEVEL_NV5], true) && $newCount >= 7) {
            $newLevel = self::compute_midday_downgraded_level($progress->current_level);
            $newCount = 0;
        }

        $nextDate = self::add_days($today, 1);
        $downgradeDate = self::compute_downgrade_date_for_level($newLevel, $nextDate);

        return false !== $wpdb->update(
            self::get_table(),
            [
                'current_level'               => $newLevel,
                'current_state'               => self::STATE_ACTIVE,
                'last_result'                 => 'unanswered',
                'consecutive_unanswered_days' => $newCount,
                'total_unanswered_count'      => (int) $progress->total_unanswered_count + 1,
                'penalty_points_total'        => (int) $progress->penalty_points_total + (int) $penalty,
                'next_due_date'               => $nextDate,
                'next_due_at'                 => self::due_datetime_for_date($nextDate),
                'downgrade_on_date'           => $downgradeDate,
                'downgrade_at'                => $downgradeDate ? self::downgrade_datetime_for_date($downgradeDate) : null,
                'updated_at'                  => self::now_mysql(),
            ],
            ['id' => (int) $progress_id]
        );
    }

    public static function apply_midday_downgrade($progress_id, $today) {
        global $wpdb;

        $progress = self::get_progress($progress_id);
        if (!$progress) {
            return false;
        }

        $newLevel = self::compute_midday_downgraded_level($progress->current_level);
        $nextDate = $today;
        $downgradeDate = self::compute_downgrade_date_for_level($newLevel, $nextDate);

        return false !== $wpdb->update(
            self::get_table(),
            [
                'current_level'               => $newLevel,
                'current_state'               => self::STATE_ACTIVE,
                'consecutive_unanswered_days' => 0,
                'next_due_date'               => $nextDate,
                'next_due_at'                 => self::due_datetime_for_date($nextDate),
                'downgrade_on_date'           => $downgradeDate,
                'downgrade_at'                => $downgradeDate ? self::downgrade_datetime_for_date($downgradeDate) : null,
                'updated_at'                  => self::now_mysql(),
            ],
            ['id' => (int) $progress_id]
        );
    }

    public static function compute_next_due_date_after_answer($new_level, $today) {
        switch ((string) $new_level) {
            case self::LEVEL_NV1:
                return self::add_days($today, 3);
            case self::LEVEL_NV2:
                return self::add_days($today, 4);
            case self::LEVEL_NV3:
                return self::add_days($today, 6);
            case self::LEVEL_NV4:
                return self::add_days($today, 8);
            case self::LEVEL_NV5:
                return self::add_days($today, 10);
            default:
                return self::add_days($today, 1);
        }
    }

    public static function compute_downgrade_date_for_level($level, $base_date) {
        switch ((string) $level) {
            case self::LEVEL_NV1:
            case self::LEVEL_NV2:
            case self::LEVEL_NV3:
                return self::add_days($base_date, 3);
            case self::LEVEL_NV4:
            case self::LEVEL_NV5:
                return self::add_days($base_date, 6);
            default:
                return null;
        }
    }

    public static function compute_midday_downgraded_level($level) {
        switch ((string) $level) {
            case self::LEVEL_NV1:
                return self::LEVEL_NV0;
            case self::LEVEL_NV2:
                return self::LEVEL_NV1;
            case self::LEVEL_NV3:
                return self::LEVEL_NV2;
            case self::LEVEL_NV4:
                return self::LEVEL_NV3;
            case self::LEVEL_NV5:
                return self::LEVEL_NV4;
            default:
                return self::LEVEL_NV0;
        }
    }
}