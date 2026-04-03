<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_V2_Engine {
    public static function get_group($group_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . InSkill_Recall_DB::table('groups') . " WHERE id = %d LIMIT 1",
            (int) $group_id
        ));
    }

    public static function get_active_groups() {
        global $wpdb;

        $today = InSkill_Recall_V2_Progress_Service::today_date();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . InSkill_Recall_DB::table('groups') . "
             WHERE status = 'active'
               AND start_date <= %s
             ORDER BY start_date ASC, id ASC",
            $today
        ));
    }

    public static function get_group_members($group_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT u.*, gm.group_id
             FROM " . InSkill_Recall_DB::table('group_memberships') . " gm
             INNER JOIN " . InSkill_Recall_DB::table('users') . " u ON u.id = gm.recall_user_id
             WHERE gm.group_id = %d
               AND gm.status = 'active'
               AND u.status = 'active'
             ORDER BY u.id ASC",
            (int) $group_id
        ));
    }

    public static function prepare_daily_questions_for_user($group_id, $recall_user_id, $today = null) {
        if (!$today) {
            $today = InSkill_Recall_V2_Progress_Service::today_date();
        }

        $group = self::get_group($group_id);
        if (!$group || $group->status !== 'active') {
            return;
        }

        $due_rows = InSkill_Recall_V2_Progress_Service::get_due_progress_rows($group_id, $recall_user_id, $today);

        foreach ($due_rows as $progress) {
            $occurrence_type = ((string) $progress->next_due_date < $today) ? 'overdue' : 'review';
            InSkill_Recall_V2_Occurrence_Service::ensure_occurrence_exists($progress, $today, $occurrence_type);
        }

        $newQuestions = self::compute_new_questions_to_assign($group_id, $recall_user_id, $today, $group->question_order_mode);
        foreach ($newQuestions as $question) {
            $progress = InSkill_Recall_V2_Progress_Service::create_initial_progress(
                $group_id,
                $recall_user_id,
                (int) $question->id,
                (int) $question->sort_order,
                $today
            );

            InSkill_Recall_V2_Occurrence_Service::ensure_occurrence_exists($progress, $today, 'new');
        }

        InSkill_Recall_V2_Scoring_Service::recalculate_user_group_stats($group_id, $recall_user_id);
    }

    public static function compute_new_questions_to_assign($group_id, $recall_user_id, $today = null, $question_order_mode = 'ordered') {
        if (!$today) {
            $today = InSkill_Recall_V2_Progress_Service::today_date();
        }

        $progressRows = InSkill_Recall_V2_Progress_Service::get_group_progress_rows($group_id, $recall_user_id, true);
        $assignedCount = count($progressRows);

        if ($assignedCount === 0) {
            return InSkill_Recall_V2_Progress_Service::get_next_never_seen_questions(
                $group_id,
                $recall_user_id,
                2,
                $question_order_mode
            );
        }

        $eligibleCount = 0;
        foreach ($progressRows as $row) {
            if (!empty($row->first_answered_at)) {
                try {
                    $firstAnswered = new DateTimeImmutable($row->first_answered_at, wp_timezone());
                    $unlockDate = $firstAnswered->modify('+3 days')->format('Y-m-d');
                    if ($unlockDate <= $today) {
                        $eligibleCount++;
                    }
                } catch (Exception $e) {
                }
            }
        }

        $alreadyAssigned = $assignedCount;
        $maxAllowedByUnlock = 2 + $eligibleCount;
        $availableSlots = max(0, $maxAllowedByUnlock - $alreadyAssigned);

        if ($availableSlots <= 0) {
            return [];
        }

        return InSkill_Recall_V2_Progress_Service::get_next_never_seen_questions(
            $group_id,
            $recall_user_id,
            $availableSlots,
            $question_order_mode
        );
    }

    public static function evaluate_answer($question_id, array $selected_choice_ids) {
        global $wpdb;

        $choices = $wpdb->get_results($wpdb->prepare(
            "SELECT id, is_correct FROM " . InSkill_Recall_DB::table('question_choices') . " WHERE question_id = %d",
            (int) $question_id
        ));

        $selected = array_map('intval', array_values($selected_choice_ids));
        sort($selected);

        $correct = [];
        foreach ($choices as $choice) {
            if ((int) $choice->is_correct === 1) {
                $correct[] = (int) $choice->id;
            }
        }
        sort($correct);

        return $selected === $correct;
    }

    public static function get_correct_choice_ids($question_id) {
        global $wpdb;

        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT id
             FROM " . InSkill_Recall_DB::table('question_choices') . "
             WHERE question_id = %d
               AND is_correct = 1
             ORDER BY sort_order ASC, id ASC",
            (int) $question_id
        )));
    }

    public static function submit_answer($occurrence_id, array $selected_choice_ids) {
        $occurrence = InSkill_Recall_V2_Occurrence_Service::get_occurrence($occurrence_id);
        if (!$occurrence || $occurrence->status !== 'pending') {
            return new WP_Error('invalid_occurrence', 'Occurrence invalide.');
        }

        $progress = InSkill_Recall_V2_Progress_Service::get_progress((int) $occurrence->progress_id);
        if (!$progress) {
            return new WP_Error('missing_progress', 'Progression introuvable.');
        }

        $correct_choice_ids = self::get_correct_choice_ids((int) $occurrence->question_id);
        $isCorrect = self::evaluate_answer((int) $occurrence->question_id, $selected_choice_ids);

        if ($isCorrect) {
            $transition = InSkill_Recall_V2_Progress_Service::get_correct_transition(
                $progress->current_level,
                (string) $occurrence->scheduled_date
            );

            $levelPoints = InSkill_Recall_V2_Scoring_Service::compute_level_points_to_award($progress, $transition['next_level']);
            $speedBonus = InSkill_Recall_V2_Scoring_Service::compute_speed_bonus($occurrence);

            InSkill_Recall_V2_Occurrence_Service::mark_answered_correct(
                (int) $occurrence->id,
                $selected_choice_ids,
                $correct_choice_ids,
                $progress->current_level,
                $levelPoints,
                $speedBonus
            );

            InSkill_Recall_V2_Progress_Service::apply_correct_answer(
                (int) $progress->id,
                (string) $occurrence->scheduled_date,
                $speedBonus
            );
        } else {
            InSkill_Recall_V2_Occurrence_Service::mark_answered_incorrect(
                (int) $occurrence->id,
                $selected_choice_ids,
                $correct_choice_ids,
                $progress->current_level
            );

            InSkill_Recall_V2_Progress_Service::apply_incorrect_answer(
                (int) $progress->id,
                (string) $occurrence->scheduled_date
            );
        }

        InSkill_Recall_V2_Scoring_Service::recalculate_user_group_stats((int) $progress->group_id, (int) $progress->recall_user_id);

        return [
            'success'            => true,
            'is_correct'         => $isCorrect,
            'correct_choice_ids' => $correct_choice_ids,
        ];
    }

    public static function process_unanswered_occurrences_for_day($group_id, $recall_user_id, $date = null) {
        if (!$date) {
            $date = InSkill_Recall_V2_Progress_Service::today_date();
        }

        $pending = InSkill_Recall_V2_Occurrence_Service::get_pending_occurrences_for_date($group_id, $recall_user_id, $date);

        foreach ($pending as $occurrence) {
            $progress = InSkill_Recall_V2_Progress_Service::get_progress((int) $occurrence->progress_id);
            if (!$progress) {
                continue;
            }

            InSkill_Recall_V2_Occurrence_Service::mark_unanswered((int) $occurrence->id, $progress->current_level, 1);
            InSkill_Recall_V2_Progress_Service::apply_unanswered((int) $progress->id, $date, 1);
        }

        InSkill_Recall_V2_Scoring_Service::recalculate_user_group_stats($group_id, $recall_user_id);
    }

    public static function run_midday_downgrades($today = null) {
        if (!$today) {
            $today = InSkill_Recall_V2_Progress_Service::today_date();
        }

        $rows = InSkill_Recall_V2_Progress_Service::get_rows_due_for_downgrade($today);

        foreach ($rows as $progress) {
            $pendingOccurrence = InSkill_Recall_V2_Occurrence_Service::get_pending_occurrence_for_progress_and_date((int) $progress->id, $today);
            if (!$pendingOccurrence) {
                continue;
            }

            InSkill_Recall_V2_Progress_Service::apply_midday_downgrade((int) $progress->id, $today);
            InSkill_Recall_V2_Scoring_Service::recalculate_user_group_stats((int) $progress->group_id, (int) $progress->recall_user_id);
        }
    }

    public static function prepare_all_due_occurrences_for_today() {
        $today = InSkill_Recall_V2_Progress_Service::today_date();
        $groups = self::get_active_groups();

        foreach ($groups as $group) {
            $members = self::get_group_members((int) $group->id);

            foreach ($members as $member) {
                self::prepare_daily_questions_for_user((int) $group->id, (int) $member->id, $today);
            }
        }
    }

    public static function close_pending_occurrences_for_previous_days() {
        $today = InSkill_Recall_V2_Progress_Service::today_date();
        $yesterday = InSkill_Recall_V2_Progress_Service::add_days($today, -1);

        $groups = self::get_active_groups();
        foreach ($groups as $group) {
            $members = self::get_group_members((int) $group->id);

            foreach ($members as $member) {
                self::process_unanswered_occurrences_for_day((int) $group->id, (int) $member->id, $yesterday);
            }
        }
    }
}