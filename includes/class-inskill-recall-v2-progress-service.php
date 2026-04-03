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
        return current_time('mysql');
    }

    public static function today_date() {
        return wp_date('Y-m-d', current_time('timestamp'), wp_timezone());
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
        $map = [
            self::LEVEL_NV0      => 0,
            self::LEVEL_NV1      => 1,
            self::LEVEL_NV2      => 2,
            self::LEVEL_NV3      => 3,
            self::LEVEL_NV4      => 4,
            self::LEVEL_NV5      => 5,
            self::LEVEL_MASTERED => 6,
        ];

        return isset($map[$level]) ? $map[$level] : 0;
    }

    public static function get_level_points($level) {
        $map = [
            self::LEVEL_NV1 => 10,
            self::LEVEL_NV2 => 20,
            self::LEVEL_NV3 => 30,
            self::LEVEL_NV4 => 40,
            self::LEVEL_NV5 => 50,
        ];

        return isset($map[$level]) ? $map[$level] : 0;
    }

    public static function get_correct_transition($current_level, $today = null) {
        if (!$today) {
            $today = self::today_date();
        }

        switch ($current_level) {
            case self::LEVEL_NV0:
                $next_level = self::LEVEL_NV1;
                $next_due_date = self::add_days($today, 3);
                break;
            case self::LEVEL_NV1:
                $next_level = self::LEVEL_NV2;
                $next_due_date = self::add_days($today, 4);
                break;
            case self::LEVEL_NV2:
                $next_level = self::LEVEL_NV3;
                $next_due_date = self::add_days($today, 6);
                break;
            case self::LEVEL_NV3:
                $next_level = self::LEVEL_NV4;
                $next_due_date = self::add_days($today, 8);
                break;
            case self::LEVEL_NV4:
                $next_level = self::LEVEL_NV5;
                $next_due_date = self::add_days($today, 10);
                break;
            case self::LEVEL_NV5:
                $next_level = self::LEVEL_MASTERED;
                $next_due_date = null;
                break;
            default:
                $next_level = self::LEVEL_MASTERED;
                $next_due_date = null;
                break;
        }

        return [
            'next_level'     => $next_level,
            'next_due_date'  => $next_due_date,
            'next_due_at'    => $next_due_date ? self::due_datetime_for_date($next_due_date) : null,
            'next_state'     => $next_level === self::LEVEL_MASTERED ? self::STATE_MASTERED : self::STATE_ACTIVE,
            'mastered_at'    => $next_level === self::LEVEL_MASTERED ? self::now_mysql() : null,
            'downgrade_rule' => self::get_downgrade_rule($next_level, $next_due_date),
        ];
    }

    public static function get_incorrect_transition($today = null) {
        if (!$today) {
            $today = self::today_date();
        }

        $next_due_date = self::add_days($today, 1);

        return [
            'next_level'     => self::LEVEL_NV0,
            'next_due_date'  => $next_due_date,
            'next_due_at'    => self::due_datetime_for_date($next_due_date),
            'next_state'     => self::STATE_ACTIVE,
            'mastered_at'    => null,
            'downgrade_rule' => self::get_downgrade_rule(self::LEVEL_NV0, $next_due_date),
        ];
    }

    public static function get_unanswered_requeue_transition($current_level, $current_consecutive_unanswered_days, $today = null) {
        if (!$today) {
            $today = self::today_date();
        }

        $next_due_date = self::add_days($today, 1);
        $new_level = $current_level;
        $new_count = (int) $current_consecutive_unanswered_days + 1;

        $rule = self::get_downgrade_rule($current_level, $today);
        if (!empty($rule['threshold']) && $new_count >= (int) $rule['threshold']) {
            $new_level = $rule['downgrade_to'];
            $new_count = 0;
        }

        return [
            'next_level'                    => $new_level,
            'next_due_date'                 => $next_due_date,
            'next_due_at'                   => self::due_datetime_for_date($next_due_date),
            'next_state'                    => self::STATE_ACTIVE,
            'new_consecutive_unanswered'    => $new_count,
            'downgrade_rule'                => self::get_downgrade_rule($new_level, $next_due_date),
        ];
    }

    public static function get_downgrade_rule($level, $base_date = null) {
        if (!$base_date) {
            $base_date = self::today_date();
        }

        $threshold = null;
        $downgrade_to = null;

        switch ($level) {
            case self::LEVEL_NV0:
                $threshold = null;
                $downgrade_to = null;
                break;
            case self::LEVEL_NV1:
                $threshold = 4;
                $downgrade_to = self::LEVEL_NV0;
                break;
            case self::LEVEL_NV2:
                $threshold = 4;
                $downgrade_to = self::LEVEL_NV1;
                break;
            case self::LEVEL_NV3:
                $threshold = 4;
                $downgrade_to = self::LEVEL_NV2;
                break;
            case self::LEVEL_NV4:
                $threshold = 7;
                $downgrade_to = self::LEVEL_NV3;
                break;
            case self::LEVEL_NV5:
                $threshold = 7;
                $downgrade_to = self::LEVEL_NV4;
                break;
            default:
                $threshold = null;
                $downgrade_to = null;
                break;
        }

        if ($threshold === null) {
            return [
                'threshold'         => null,
                'downgrade_to'      => null,
                'downgrade_on_date' => null,
                'downgrade_at'      => null,
            ];
        }

        $downgrade_on_date = self::add_days($base_date, $threshold - 1);

        return [
            'threshold'         => $threshold,
            'downgrade_to'      => $downgrade_to,
            'downgrade_on_date' => $downgrade_on_date,
            'downgrade_at'      => self::downgrade_datetime_for_date($downgrade_on_date),
        ];
    }

    public static function get_progress($progress_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::get_table() . " WHERE id = %d LIMIT 1",
            (int) $progress_id
        ));
    }

    public static function get_progress_by_user_and_question($group_id, $recall_user_id, $question_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::get_table() . " WHERE group_id = %d AND recall_user_id = %d AND question_id = %d LIMIT 1",
            (int) $group_id,
            (int) $recall_user_id,
            (int) $question_id
        ));
    }

    public static function get_group_progress_rows($group_id, $recall_user_id, $include_mastered = true) {
        global $wpdb;

        $sql = "SELECT * FROM " . self::get_table() . " WHERE group_id = %d AND recall_user_id = %d";
        if (!$include_mastered) {
            $sql .= " AND current_level != 'mastered'";
        }
        $sql .= " ORDER BY question_order_index ASC, id ASC";

        return $wpdb->get_results($wpdb->prepare($sql, (int) $group_id, (int) $recall_user_id));
    }

    public static function get_due_progress_rows($group_id, $recall_user_id, $today = null) {
        global $wpdb;

        if (!$today) {
            $today = self::today_date();
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::get_table() . "
             WHERE group_id = %d
               AND recall_user_id = %d
               AND current_level != 'mastered'
               AND next_due_date IS NOT NULL
               AND next_due_date <= %s
             ORDER BY next_due_date ASC, question_order_index ASC, id ASC",
            (int) $group_id,
            (int) $recall_user_id,
            $today
        ));
    }

    public static function get_rows_due_for_downgrade($today = null) {
        global $wpdb;

        if (!$today) {
            $today = self::today_date();
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::get_table() . "
             WHERE current_level NOT IN ('nv0', 'mastered')
               AND downgrade_on_date IS NOT NULL
               AND downgrade_on_date <= %s
               AND downgrade_at IS NOT NULL
               AND downgrade_at <= %s
             ORDER BY downgrade_at ASC, id ASC",
            $today,
            self::now_mysql()
        ));
    }

    public static function count_progress_rows($group_id, $recall_user_id) {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::get_table() . " WHERE group_id = %d AND recall_user_id = %d",
            (int) $group_id,
            (int) $recall_user_id
        ));
    }

    public static function create_initial_progress($group_id, $recall_user_id, $question_id, $question_order_index, $today = null) {
        global $wpdb;

        if (!$today) {
            $today = self::today_date();
        }

        $existing = self::get_progress_by_user_and_question($group_id, $recall_user_id, $question_id);
        if ($existing) {
            return $existing;
        }

        $rule = self::get_downgrade_rule(self::LEVEL_NV0, $today);
        $now = self::now_mysql();

        $wpdb->insert(self::get_table(), [
            'group_id'                     => (int) $group_id,
            'recall_user_id'               => (int) $recall_user_id,
            'question_id'                  => (int) $question_id,
            'question_order_index'         => (int) $question_order_index,
            'is_initially_assigned'        => 1,
            'current_level'                => self::LEVEL_NV0,
            'current_state'                => self::STATE_ACTIVE,
            'first_presented_at'           => null,
            'first_answered_at'            => null,
            'last_presented_at'            => null,
            'last_answered_at'             => null,
            'last_result'                  => null,
            'next_due_date'                => $today,
            'next_due_at'                  => self::due_datetime_for_date($today),
            'downgrade_on_date'            => $rule['downgrade_on_date'],
            'downgrade_at'                 => $rule['downgrade_at'],
            'consecutive_unanswered_days'  => 0,
            'total_presentations_count'    => 0,
            'total_answers_count'          => 0,
            'total_correct_count'          => 0,
            'total_incorrect_count'        => 0,
            'total_unanswered_count'       => 0,
            'awarded_nv1_points'           => 0,
            'awarded_nv2_points'           => 0,
            'awarded_nv3_points'           => 0,
            'awarded_nv4_points'           => 0,
            'awarded_nv5_points'           => 0,
            'speed_bonus_count'            => 0,
            'penalty_points_total'         => 0,
            'mastered_at'                  => null,
            'created_at'                   => $now,
            'updated_at'                   => $now,
        ]);

        return self::get_progress((int) $wpdb->insert_id);
    }

    public static function mark_presented($progress_id) {
        global $wpdb;

        $progress = self::get_progress($progress_id);
        if (!$progress) {
            return false;
        }

        $total_presentations_count = ((int) $progress->total_presentations_count) + 1;
        $first_presented_at = $progress->first_presented_at ? $progress->first_presented_at : self::now_mysql();

        return false !== $wpdb->update(
            self::get_table(),
            [
                'first_presented_at'        => $first_presented_at,
                'last_presented_at'         => self::now_mysql(),
                'total_presentations_count' => $total_presentations_count,
                'updated_at'                => self::now_mysql(),
            ],
            ['id' => (int) $progress_id]
        );
    }

    public static function apply_correct_answer($progress_id, $today = null, $speed_bonus_awarded = 0) {
        global $wpdb;

        if (!$today) {
            $today = self::today_date();
        }

        $progress = self::get_progress($progress_id);
        if (!$progress) {
            return false;
        }

        $transition = self::get_correct_transition($progress->current_level, $today);
        $next_level = $transition['next_level'];

        $points_column = null;
        if ($next_level === self::LEVEL_NV1) {
            $points_column = 'awarded_nv1_points';
        } elseif ($next_level === self::LEVEL_NV2) {
            $points_column = 'awarded_nv2_points';
        } elseif ($next_level === self::LEVEL_NV3) {
            $points_column = 'awarded_nv3_points';
        } elseif ($next_level === self::LEVEL_NV4) {
            $points_column = 'awarded_nv4_points';
        } elseif ($next_level === self::LEVEL_NV5) {
            $points_column = 'awarded_nv5_points';
        }

        $update = [
            'current_level'               => $next_level,
            'current_state'               => $transition['next_state'],
            'first_answered_at'           => $progress->first_answered_at ? $progress->first_answered_at : self::now_mysql(),
            'last_answered_at'            => self::now_mysql(),
            'last_result'                 => 'correct',
            'next_due_date'               => $transition['next_due_date'],
            'next_due_at'                 => $transition['next_due_at'],
            'downgrade_on_date'           => $transition['downgrade_rule']['downgrade_on_date'],
            'downgrade_at'                => $transition['downgrade_rule']['downgrade_at'],
            'consecutive_unanswered_days' => 0,
            'total_answers_count'         => ((int) $progress->total_answers_count) + 1,
            'total_correct_count'         => ((int) $progress->total_correct_count) + 1,
            'speed_bonus_count'           => ((int) $progress->speed_bonus_count) + ((int) $speed_bonus_awarded > 0 ? 1 : 0),
            'mastered_at'                 => $transition['mastered_at'],
            'updated_at'                  => self::now_mysql(),
        ];

        if ($points_column && empty($progress->{$points_column})) {
            $update[$points_column] = 1;
        }

        return false !== $wpdb->update(self::get_table(), $update, ['id' => (int) $progress_id]);
    }

    public static function apply_incorrect_answer($progress_id, $today = null) {
        global $wpdb;

        if (!$today) {
            $today = self::today_date();
        }

        $progress = self::get_progress($progress_id);
        if (!$progress) {
            return false;
        }

        $transition = self::get_incorrect_transition($today);

        return false !== $wpdb->update(
            self::get_table(),
            [
                'current_level'               => $transition['next_level'],
                'current_state'               => $transition['next_state'],
                'first_answered_at'           => $progress->first_answered_at ? $progress->first_answered_at : self::now_mysql(),
                'last_answered_at'            => self::now_mysql(),
                'last_result'                 => 'incorrect',
                'next_due_date'               => $transition['next_due_date'],
                'next_due_at'                 => $transition['next_due_at'],
                'downgrade_on_date'           => $transition['downgrade_rule']['downgrade_on_date'],
                'downgrade_at'                => $transition['downgrade_rule']['downgrade_at'],
                'consecutive_unanswered_days' => 0,
                'total_answers_count'         => ((int) $progress->total_answers_count) + 1,
                'total_incorrect_count'       => ((int) $progress->total_incorrect_count) + 1,
                'mastered_at'                 => null,
                'updated_at'                  => self::now_mysql(),
            ],
            ['id' => (int) $progress_id]
        );
    }

    public static function apply_unanswered($progress_id, $scheduled_date = null, $penalty_points = 1) {
        global $wpdb;

        if (!$scheduled_date) {
            $scheduled_date = self::today_date();
        }

        $progress = self::get_progress($progress_id);
        if (!$progress) {
            return false;
        }

        $transition = self::get_unanswered_requeue_transition(
            $progress->current_level,
            (int) $progress->consecutive_unanswered_days,
            $scheduled_date
        );

        return false !== $wpdb->update(
            self::get_table(),
            [
                'current_level'               => $transition['next_level'],
                'current_state'               => self::STATE_ACTIVE,
                'last_result'                 => 'unanswered',
                'next_due_date'               => $transition['next_due_date'],
                'next_due_at'                 => $transition['next_due_at'],
                'downgrade_on_date'           => $transition['downgrade_rule']['downgrade_on_date'],
                'downgrade_at'                => $transition['downgrade_rule']['downgrade_at'],
                'consecutive_unanswered_days' => $transition['new_consecutive_unanswered'],
                'total_unanswered_count'      => ((int) $progress->total_unanswered_count) + 1,
                'penalty_points_total'        => ((int) $progress->penalty_points_total) + (int) $penalty_points,
                'updated_at'                  => self::now_mysql(),
            ],
            ['id' => (int) $progress_id]
        );
    }

    public static function apply_midday_downgrade($progress_id, $today = null) {
        global $wpdb;

        if (!$today) {
            $today = self::today_date();
        }

        $progress = self::get_progress($progress_id);
        if (!$progress) {
            return false;
        }

        $rule = self::get_downgrade_rule($progress->current_level, $today);
        if (empty($rule['downgrade_to'])) {
            return false;
        }

        $new_level = $rule['downgrade_to'];
        $new_rule = self::get_downgrade_rule($new_level, $today);

        return false !== $wpdb->update(
            self::get_table(),
            [
                'current_level'      => $new_level,
                'current_state'      => self::STATE_OVERDUE,
                'downgrade_on_date'  => $new_rule['downgrade_on_date'],
                'downgrade_at'       => $new_rule['downgrade_at'],
                'updated_at'         => self::now_mysql(),
            ],
            ['id' => (int) $progress_id]
        );
    }

    public static function count_never_seen_questions($group_id, $recall_user_id) {
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

    public static function get_next_never_seen_questions($group_id, $recall_user_id, $limit = 1, $question_order_mode = 'ordered') {
        global $wpdb;

        $base_sql = "
            SELECT q.*
            FROM " . InSkill_Recall_DB::table('questions') . " q
            WHERE q.group_id = %d
              AND q.status = 'active'
              AND q.id NOT IN (
                  SELECT p.question_id
                  FROM " . self::get_table() . " p
                  WHERE p.group_id = %d
                    AND p.recall_user_id = %d
              )
        ";

        if ($question_order_mode === 'random') {
            $base_sql .= " ORDER BY RAND() ";
        } else {
            $base_sql .= " ORDER BY q.sort_order ASC, q.id ASC ";
        }

        $base_sql .= " LIMIT %d ";

        return $wpdb->get_results($wpdb->prepare(
            $base_sql,
            (int) $group_id,
            (int) $group_id,
            (int) $recall_user_id,
            (int) $limit
        ));
    }
}