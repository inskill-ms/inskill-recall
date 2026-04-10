<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_Frontend_Dashboard extends InSkill_Recall_Frontend_Core {

    protected function maybe_run_daily_closure() {
        $today = InSkill_Recall_V2_Progress_Service::today_date();
        $last_run = (string) get_option('inskill_recall_last_daily_run', '');

        if ($last_run === $today) {
            return;
        }

        InSkill_Recall_V2_Engine::close_pending_occurrences_for_previous_days();
        update_option('inskill_recall_last_daily_run', $today, false);
    }

    protected function get_groups_for_user($recall_user_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT g.*
             FROM " . InSkill_Recall_DB::table('group_memberships') . " gm
             INNER JOIN " . InSkill_Recall_DB::table('groups') . " g ON g.id = gm.group_id
             WHERE gm.recall_user_id = %d
               AND gm.status = 'active'
               AND g.status = 'active'
             ORDER BY g.id ASC",
            (int) $recall_user_id
        ));
    }

    protected function get_stats_for_group($group_id, $recall_user_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT *
             FROM " . InSkill_Recall_DB::table('user_group_stats') . "
             WHERE group_id = %d AND recall_user_id = %d
             LIMIT 1",
            (int) $group_id,
            (int) $recall_user_id
        ));
    }

    protected function get_question_choices($question_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT *
             FROM " . InSkill_Recall_DB::table('question_choices') . "
             WHERE question_id = %d
             ORDER BY sort_order ASC, id ASC",
            (int) $question_id
        ));
    }

    protected function get_question_row($question_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT *
             FROM " . InSkill_Recall_DB::table('questions') . "
             WHERE id = %d
             LIMIT 1",
            (int) $question_id
        ));
    }

    protected function get_user_question_index($group_id, $recall_user_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.question_id, p.question_order_index, q.internal_label, q.question_text
             FROM " . InSkill_Recall_DB::table('user_question_progress') . " p
             INNER JOIN " . InSkill_Recall_DB::table('questions') . " q ON q.id = p.question_id
             WHERE p.group_id = %d
               AND p.recall_user_id = %d
             ORDER BY p.question_order_index ASC, p.id ASC",
            (int) $group_id,
            (int) $recall_user_id
        ));
    }

    protected function get_frontend_history($group_id, $recall_user_id, $today) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT o.*, q.question_text
             FROM " . InSkill_Recall_DB::table('question_occurrences') . " o
             INNER JOIN " . InSkill_Recall_DB::table('questions') . " q ON q.id = o.question_id
             WHERE o.group_id = %d
               AND o.recall_user_id = %d
               AND o.scheduled_date <= %s
               AND (o.scheduled_date < %s OR o.status != 'pending')
             ORDER BY o.scheduled_date DESC, o.id DESC
             LIMIT 50",
            (int) $group_id,
            (int) $recall_user_id,
            $today,
            $today
        ));
    }

    protected function get_frontend_upcoming($group_id, $recall_user_id, $today) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT
                p.id AS progress_id,
                p.question_id,
                p.current_level,
                p.next_due_date AS scheduled_date,
                q.question_text,
                q.internal_label,
                p.question_order_index
             FROM " . InSkill_Recall_DB::table('user_question_progress') . " p
             INNER JOIN " . InSkill_Recall_DB::table('questions') . " q ON q.id = p.question_id
             WHERE p.group_id = %d
               AND p.recall_user_id = %d
               AND p.current_state != %s
               AND p.next_due_date IS NOT NULL
               AND p.next_due_date > %s
             ORDER BY p.next_due_date ASC, p.question_order_index ASC, p.id ASC",
            (int) $group_id,
            (int) $recall_user_id,
            InSkill_Recall_V2_Progress_Service::STATE_MASTERED,
            $today
        ));
    }

    protected function get_leaderboard_for_group($group) {
        global $wpdb;

        $mode = isset($group->leaderboard_mode) ? (string) $group->leaderboard_mode : 'B';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, u.first_name, u.last_name, u.email
             FROM " . InSkill_Recall_DB::table('user_group_stats') . " s
             INNER JOIN " . InSkill_Recall_DB::table('users') . " u ON u.id = s.recall_user_id
             INNER JOIN " . InSkill_Recall_DB::table('group_memberships') . " gm
                 ON gm.group_id = s.group_id
                AND gm.recall_user_id = s.recall_user_id
                AND gm.status = 'active'
             WHERE s.group_id = %d
             ORDER BY s.score_total DESC, s.last_answer_at DESC, s.recall_user_id ASC",
            (int) $group->id
        ));

        if (empty($rows)) {
            return [];
        }

        $rank = 0;
        $position = 0;
        $previous_score = null;

        foreach ($rows as $row) {
            $position++;

            if ($previous_score === null || (int) $row->score_total !== (int) $previous_score) {
                $rank = $position;
                $previous_score = (int) $row->score_total;
            }

            $row->cached_rank = $rank;
        }

        if ($mode === 'A') {
            return $rows;
        }

        $participant_count = count($rows);
        $top_count = max(3, min(10, (int) ceil($participant_count / 3)));

        if ($mode === 'B') {
            $visible = [];
            $last_visible_rank = null;

            foreach ($rows as $index => $row) {
                if ($index < $top_count) {
                    $visible[] = $row;
                    $last_visible_rank = (int) $row->cached_rank;
                    continue;
                }

                if ($last_visible_rank !== null && (int) $row->cached_rank === $last_visible_rank) {
                    $visible[] = $row;
                    continue;
                }

                break;
            }

            return $visible;
        }

        return [];
    }

    protected function build_group_dashboard_payload($group, $user) {
        $this->maybe_run_daily_closure();

        InSkill_Recall_V2_Engine::prepare_daily_questions_for_user((int) $group->id, (int) $user->id);

        $today = InSkill_Recall_V2_Progress_Service::today_date();

        $stats = $this->get_stats_for_group((int) $group->id, (int) $user->id);

        $queue = InSkill_Recall_V2_Occurrence_Service::get_today_front_queue((int) $group->id, (int) $user->id, $today);
        $todayRows = InSkill_Recall_V2_Occurrence_Service::get_all_today_occurrences((int) $group->id, (int) $user->id, $today);

        $history = $this->get_frontend_history((int) $group->id, (int) $user->id, $today);
        $upcoming = $this->get_frontend_upcoming((int) $group->id, (int) $user->id, $today);

        $todayCorrect = 0;
        $todayIncorrect = 0;
        $todayRemaining = 0;

        foreach ($todayRows as $row) {
            if ($row->status === 'answered_correct') {
                $todayCorrect++;
            } elseif ($row->status === 'answered_incorrect') {
                $todayIncorrect++;
            } elseif ($row->status === 'pending') {
                $todayRemaining++;
            }
        }

        $status = $stats ? (string) $stats->participant_status : 'active';

        if ($status === 'finished') {
            $message = 'Félicitations, votre programme est terminé.';
        } elseif ($todayRemaining <= 0 && ($todayCorrect + $todayIncorrect) > 0) {
            $message = 'Bravo, vous avez terminé vos questions du jour 👏';
        } elseif ($todayRemaining <= 0) {
            $message = 'Aucune question n’est prévue aujourd’hui.';
        } else {
            $message = 'Répondez sans attendre pour gagnez des bonus de progression (et éviter les pénalités de retard).';
        }

        $actionLabel = 'Démarrer';
        if ($status === 'finished') {
            $actionLabel = 'Revoir mes réponses';
        } elseif ($todayRemaining > 0 && ($todayCorrect + $todayIncorrect) > 0) {
            $actionLabel = 'Continuer';
        }

        $leaderboard = $this->get_leaderboard_for_group($group);
        $userRank = null;

        foreach ($leaderboard as $leaderboardRow) {
            if (isset($leaderboardRow->recall_user_id) && (int) $leaderboardRow->recall_user_id === (int) $user->id) {
                $userRank = (int) $leaderboardRow->cached_rank;
                break;
            }
        }

        return [
            'group' => [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'leaderboard_mode' => (string) $group->leaderboard_mode,
            ],
            'summary' => [
                'score_total' => $stats ? (int) $stats->score_total : 0,
                'rank' => $userRank,
                'mastered_questions' => $stats ? (int) $stats->mastered_questions : 0,
                'total_questions' => $stats ? (int) $stats->total_questions : 0,
                'participant_status' => $status,
                'participant_status_label' => $this->get_status_label($status),
                'today_correct' => $todayCorrect,
                'today_incorrect' => $todayIncorrect,
                'today_remaining' => $todayRemaining,
                'message' => $message,
                'action_label' => $actionLabel,
            ],
            'queue' => array_map([$this, 'format_occurrence_list_row'], $queue),
            'history' => array_map([$this, 'format_occurrence_list_row'], $history),
            'upcoming' => array_map([$this, 'format_upcoming_row'], $upcoming),
            'question_index' => $this->format_question_index_rows(
                $this->get_user_question_index((int) $group->id, (int) $user->id)
            ),
            'leaderboard' => array_map([$this, 'format_leaderboard_row'], $leaderboard),
            'preferences' => InSkill_Recall_Auth::get_notification_preferences($user),
        ];
    }

    protected function format_occurrence_list_row($row) {
        return [
            'occurrence_id' => isset($row->id) ? (int) $row->id : 0,
            'question_id' => isset($row->question_id) ? (int) $row->question_id : 0,
            'scheduled_date' => isset($row->scheduled_date) ? (string) $row->scheduled_date : '',
            'display_level' => isset($row->display_level) ? (string) $row->display_level : '',
            'effective_level' => isset($row->effective_level) ? (string) $row->effective_level : '',
            'status' => isset($row->status) ? (string) $row->status : '',
            'occurrence_type' => isset($row->occurrence_type) ? (string) $row->occurrence_type : '',
            'question_text' => isset($row->question_text) ? wp_kses_post($row->question_text) : '',
        ];
    }

    protected function format_upcoming_row($row) {
        return [
            'occurrence_id' => isset($row->progress_id) ? (int) $row->progress_id : (isset($row->id) ? (int) $row->id : 0),
            'question_id' => (int) $row->question_id,
            'scheduled_date' => (string) $row->scheduled_date,
            'current_level' => (string) $row->current_level,
            'question_text' => isset($row->question_text) ? wp_strip_all_tags((string) $row->question_text) : '',
            'internal_label' => isset($row->internal_label) ? (string) $row->internal_label : '',
        ];
    }

    protected function format_question_index_rows($rows) {
        $formatted = [];
        $position = 1;

        foreach ((array) $rows as $row) {
            $questionId = isset($row->question_id) ? (int) $row->question_id : (isset($row->id) ? (int) $row->id : 0);

            $formatted[] = [
                'question_id' => $questionId,
                'number' => $position,
                'internal_label' => !empty($row->internal_label) ? (string) $row->internal_label : ('Q' . $questionId),
                'question_text' => wp_strip_all_tags((string) $row->question_text),
            ];
            $position++;
        }

        return $formatted;
    }

    protected function format_leaderboard_row($row) {
        $name = trim(((string) $row->first_name) . ' ' . ((string) $row->last_name));
        if ($name === '') {
            $name = (string) $row->email;
        }

        return [
            'rank' => (int) $row->cached_rank,
            'name' => $name,
            'score_total' => (int) $row->score_total,
            'mastered_questions' => (int) $row->mastered_questions,
            'total_questions' => (int) $row->total_questions,
            'participant_status' => (string) $row->participant_status,
            'participant_status_label' => $this->get_status_label((string) $row->participant_status),
        ];
    }

    protected function get_status_label($status) {
        switch ($status) {
            case 'inactive':
                return 'Inactif';
            case 'finished':
                return 'Terminé';
            default:
                return 'Actif';
        }
    }

    protected function get_question_payload_for_occurrence($occurrence_id, $recall_user_id) {
        $occurrence = InSkill_Recall_V2_Occurrence_Service::get_occurrence($occurrence_id);
        if (!$occurrence) {
            return new WP_Error('missing_occurrence', 'Occurrence introuvable.');
        }

        if ((int) $occurrence->recall_user_id !== (int) $recall_user_id) {
            return new WP_Error('forbidden', 'Accès interdit.');
        }

        $question = $this->get_question_row((int) $occurrence->question_id);
        if (!$question) {
            return new WP_Error('missing_question', 'Question introuvable.');
        }

        $choices = $this->get_question_choices((int) $question->id);
        $selected = json_decode((string) $occurrence->selected_choice_ids_json, true);
        $selected = is_array($selected) ? array_map('intval', $selected) : [];

        return [
            'occurrence_id' => (int) $occurrence->id,
            'question_id' => (int) $question->id,
            'question_type' => !empty($question->question_type) ? (string) $question->question_type : 'qcu',
            'question_text' => wp_kses_post($question->question_text),
            'explanation' => wp_kses_post($question->explanation),
            'image_url' => $question->image_id ? wp_get_attachment_image_url($question->image_id, 'large') : $question->image_url,
            'display_level' => (string) $occurrence->display_level,
            'status' => (string) $occurrence->status,
            'choices' => array_map(function ($choice) use ($selected, $occurrence) {
                $isCorrect = (int) $choice->is_correct === 1;
                $isSelected = in_array((int) $choice->id, $selected, true);

                return [
                    'id' => (int) $choice->id,
                    'text' => wp_kses_post($choice->choice_text),
                    'is_correct' => $isCorrect,
                    'selected' => $isSelected,
                    'disabled' => $occurrence->status !== 'pending',
                ];
            }, $choices),
        ];
    }
}