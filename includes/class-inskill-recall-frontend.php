<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_Frontend {
    const SHORTCODE = 'inskill_recall_dashboard';

    public function __construct() {
        add_shortcode(self::SHORTCODE, [$this, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('wp_ajax_inskill_recall_get_dashboard', [$this, 'ajax_get_dashboard']);
        add_action('wp_ajax_nopriv_inskill_recall_get_dashboard', [$this, 'ajax_get_dashboard']);

        add_action('wp_ajax_inskill_recall_get_question', [$this, 'ajax_get_question']);
        add_action('wp_ajax_nopriv_inskill_recall_get_question', [$this, 'ajax_get_question']);

        add_action('wp_ajax_inskill_recall_submit_answer_v2', [$this, 'ajax_submit_answer']);
        add_action('wp_ajax_nopriv_inskill_recall_submit_answer_v2', [$this, 'ajax_submit_answer']);

        add_action('wp_ajax_inskill_recall_save_push_subscription', [$this, 'ajax_save_push_subscription']);
        add_action('wp_ajax_nopriv_inskill_recall_save_push_subscription', [$this, 'ajax_save_push_subscription']);

        add_action('wp_ajax_inskill_recall_save_preferences', [$this, 'ajax_save_preferences']);
        add_action('wp_ajax_nopriv_inskill_recall_save_preferences', [$this, 'ajax_save_preferences']);
    }

    public static function get_dashboard_page_id() {
        return (int) get_option('inskill_recall_dashboard_page_id', 0);
    }

    public static function get_dashboard_page() {
        $page_id = self::get_dashboard_page_id();
        if ($page_id <= 0) {
            return null;
        }

        $page = get_post($page_id);
        if (!$page || $page->post_type !== 'page') {
            return null;
        }

        return $page;
    }

    public static function get_dashboard_page_url() {
        $page = self::get_dashboard_page();
        if (!$page) {
            return '';
        }

        $url = get_permalink($page);
        return $url ? (string) $url : '';
    }

    public static function get_user_dashboard_url($user) {
        if (!$user || empty($user->access_token)) {
            return '';
        }

        $base_url = self::get_dashboard_page_url();
        if ($base_url === '') {
            return '';
        }

        return add_query_arg('token', (string) $user->access_token, $base_url);
    }

    public static function ensure_dashboard_page_exists() {
        $existing_page = self::get_dashboard_page();
        if ($existing_page) {
            return (int) $existing_page->ID;
        }

        $page_id = wp_insert_post([
            'post_title'   => 'InSkill Recall Dashboard',
            'post_name'    => 'inskill-recall-dashboard',
            'post_content' => '[' . self::SHORTCODE . ']',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ], true);

        if (is_wp_error($page_id) || !$page_id) {
            return 0;
        }

        update_option('inskill_recall_dashboard_page_id', (int) $page_id);

        return (int) $page_id;
    }

    public function enqueue_assets() {
        wp_register_style(
            'inskill-recall',
            INSKILL_RECALL_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            INSKILL_RECALL_VERSION
        );

        wp_register_script(
            'inskill-recall-utils',
            INSKILL_RECALL_PLUGIN_URL . 'assets/js/frontend/utils.js',
            ['jquery'],
            INSKILL_RECALL_VERSION,
            true
        );

        wp_register_script(
            'inskill-recall-api',
            INSKILL_RECALL_PLUGIN_URL . 'assets/js/frontend/api.js',
            ['jquery', 'inskill-recall-utils'],
            INSKILL_RECALL_VERSION,
            true
        );

        wp_register_script(
            'inskill-recall-push',
            INSKILL_RECALL_PLUGIN_URL . 'assets/js/frontend/push.js',
            ['jquery', 'inskill-recall-utils', 'inskill-recall-api'],
            INSKILL_RECALL_VERSION,
            true
        );

        wp_register_script(
            'inskill-recall-render-preferences',
            INSKILL_RECALL_PLUGIN_URL . 'assets/js/frontend/render-preferences.js',
            ['jquery', 'inskill-recall-utils', 'inskill-recall-api'],
            INSKILL_RECALL_VERSION,
            true
        );

        wp_register_script(
            'inskill-recall-render-session',
            INSKILL_RECALL_PLUGIN_URL . 'assets/js/frontend/render-session.js',
            ['jquery', 'inskill-recall-utils', 'inskill-recall-api', 'inskill-recall-push', 'inskill-recall-render-preferences'],
            INSKILL_RECALL_VERSION,
            true
        );

        wp_register_script(
            'inskill-recall-app',
            INSKILL_RECALL_PLUGIN_URL . 'assets/js/frontend/app.js',
            ['jquery', 'inskill-recall-utils', 'inskill-recall-api', 'inskill-recall-push', 'inskill-recall-render-preferences', 'inskill-recall-render-session'],
            INSKILL_RECALL_VERSION,
            true
        );
    }

    public function render_shortcode() {
        $user = InSkill_Recall_Auth::get_current_user_from_request();
        if (!$user) {
            return '<div class="inskill-recall-app"><div class="inskill-recall-box"><p>Lien invalide ou expiré.</p></div></div>';
        }

        InSkill_Recall_Auth::touch_last_access((int) $user->id);

        wp_enqueue_style('inskill-recall');
        wp_enqueue_script('inskill-recall-utils');
        wp_enqueue_script('inskill-recall-api');
        wp_enqueue_script('inskill-recall-push');
        wp_enqueue_script('inskill-recall-render-preferences');
        wp_enqueue_script('inskill-recall-render-session');
        wp_enqueue_script('inskill-recall-app');

        wp_localize_script('inskill-recall-app', 'InSkillRecall', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('inskill_recall_frontend'),
            'token' => isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '',
            'swUrl' => INSKILL_RECALL_PLUGIN_URL . 'inskill-recall-sw.js',
            'vapidPublicKey' => InSkill_Recall_Push::get_vapid_public_key(),
            'labels' => [
                'homeGreetingFormat' => 'Bonjour {prenom}, vous avez {count} question(s) disponible(s) !',
                'start' => 'Démarrer',
                'continue' => 'Continuer',
                'reviewAnswers' => 'Revoir mes réponses',
                'resumeTitle' => 'Résumé du jour',
                'empty' => 'Aucune question disponible pour le moment.',
                'correct' => 'Bonne réponse',
                'incorrect' => 'Réponse incorrecte',
                'next' => 'Question suivante',
                'backToList' => 'Retour à la liste',
                'done' => 'Session terminée',
                'quit' => 'Quitter',
                'loading' => 'Chargement…',
                'notificationsPrompt' => 'Activez les notifications pour être alerté quand vos questions sont disponibles.',
                'notificationsDenied' => 'Les notifications sont bloquées sur cet appareil.',
                'notificationsUnsupported' => 'Ce navigateur ne prend pas correctement en charge les notifications.',
                'enableNotifications' => 'Activer les notifications',
                'preferencesTitle' => 'Préférences de notification',
                'preferencesIntro' => 'Définissez l’heure à laquelle vous souhaitez recevoir vos notifications.',
                'preferencesTimezone' => 'Fuseau horaire',
                'preferencesHour' => 'Heure souhaitée',
                'preferencesWeekend' => 'Recevoir les notifications le week-end',
                'preferencesWeekendHelp' => 'Activez cette option si vous souhaitez aussi être notifié le samedi et le dimanche.',
                'preferencesSave' => 'Enregistrer mes préférences',
                'preferencesSaved' => 'Préférences enregistrées.',
                'preferencesSaveError' => 'Impossible d’enregistrer les préférences.',
                'preferencesSummaryPrefix' => 'Réglage actuel :',
                'preferencesSummaryEveryday' => 'tous les jours',
                'preferencesSummaryWeekdays' => 'du lundi au vendredi',
                'statusActive' => 'Actif',
                'statusInactive' => 'Inactif',
                'statusFinished' => 'Terminé',
                'questionListTitle' => 'Questions du jour',
                'calendarTitle' => 'Historique',
                'upcomingTitle' => 'Prochains rappels',
                'indexTitle' => 'Index des questions',
                'leaderboardTitle' => 'Classement',
                'confirmAnswerTitle' => 'Confirmer la réponse',
                'confirmAnswerText' => 'Confirmez-vous cette réponse ?',
                'confirmYes' => 'Oui',
                'confirmNo' => 'Non',
                'noSelection' => 'Veuillez sélectionner au moins une réponse.',
                'loadError' => 'Erreur de chargement.',
                'saveError' => 'Erreur lors de l’enregistrement.',
                'finishedMessage' => 'Bravo, vous avez terminé vos questions du jour 👏',
                'programFinishedMessage' => 'Félicitations, votre programme est terminé.',
                'noQuestionTodayMessage' => 'Aucune question n’est prévue aujourd’hui.',
                'urgentToday' => 'Urgent aujourd’hui',
                'urgentTomorrow' => 'Urgent demain',
            ],
        ]);

        return '<div class="inskill-recall-app" id="inskill-recall-app"><div class="inskill-recall-loading">Chargement…</div></div>';
    }

    protected function verify_nonce() {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'inskill_recall_frontend')) {
            wp_send_json_error(['message' => 'Nonce invalide.'], 403);
        }
    }

    protected function require_recall_user() {
        $user = InSkill_Recall_Auth::get_current_user_from_request();
        if (!$user) {
            wp_send_json_error(['message' => 'Utilisateur invalide.'], 403);
        }

        InSkill_Recall_Auth::touch_last_access((int) $user->id);

        return $user;
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

    protected function get_group_question_index($group_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, internal_label, question_text
             FROM " . InSkill_Recall_DB::table('questions') . "
             WHERE group_id = %d
               AND status = 'active'
             ORDER BY internal_label ASC, id ASC",
            (int) $group_id
        ));
    }

    protected function get_leaderboard_for_group($group) {
        global $wpdb;

        $mode = isset($group->leaderboard_mode) ? (string) $group->leaderboard_mode : 'B';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, u.first_name, u.last_name, u.email
             FROM " . InSkill_Recall_DB::table('user_group_stats') . " s
             INNER JOIN " . InSkill_Recall_DB::table('users') . " u ON u.id = s.recall_user_id
             WHERE s.group_id = %d
             ORDER BY s.score_total DESC, s.last_answer_at DESC, s.recall_user_id ASC",
            (int) $group->id
        ));

        if ($mode === 'A') {
            return $rows;
        }

        $participantCount = count($rows);
        $topCount = max(3, min(10, (int) ceil($participantCount / 3)));

        if ($mode === 'B') {
            $visible = [];
            $lastVisibleRank = null;

            foreach ($rows as $index => $row) {
                if ($index < $topCount) {
                    $visible[] = $row;
                    $lastVisibleRank = (int) $row->cached_rank;
                    continue;
                }

                if ($lastVisibleRank !== null && (int) $row->cached_rank === $lastVisibleRank) {
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
        global $wpdb;

        InSkill_Recall_V2_Engine::prepare_daily_questions_for_user((int) $group->id, (int) $user->id);

        $today = InSkill_Recall_V2_Progress_Service::today_date();

        $stats = $this->get_stats_for_group((int) $group->id, (int) $user->id);
        $queue = InSkill_Recall_V2_Occurrence_Service::get_today_front_queue((int) $group->id, (int) $user->id, $today);
        $history = InSkill_Recall_V2_Occurrence_Service::get_user_occurrences_until_today((int) $group->id, (int) $user->id, $today);

        $upcoming = $wpdb->get_results($wpdb->prepare(
            "SELECT o.*, p.current_level
             FROM " . InSkill_Recall_DB::table('question_occurrences') . " o
             INNER JOIN " . InSkill_Recall_DB::table('user_question_progress') . " p ON p.id = o.progress_id
             WHERE o.group_id = %d
               AND o.recall_user_id = %d
               AND o.scheduled_date > %s
             ORDER BY o.scheduled_date ASC, o.id ASC",
            (int) $group->id,
            (int) $user->id,
            $today
        ));

        $todayCorrect = 0;
        $todayIncorrect = 0;
        $todayRemaining = 0;

        foreach ($queue as $row) {
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
            $message = 'Vous pouvez avancer à votre rythme.';
        }

        $actionLabel = 'Démarrer';
        if ($status === 'finished') {
            $actionLabel = 'Revoir mes réponses';
        } elseif ($todayRemaining > 0 && ($todayCorrect + $todayIncorrect) > 0) {
            $actionLabel = 'Continuer';
        }

        return [
            'group' => [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'leaderboard_mode' => (string) $group->leaderboard_mode,
            ],
            'summary' => [
                'score_total' => $stats ? (int) $stats->score_total : 0,
                'rank' => $stats && $stats->cached_rank ? (int) $stats->cached_rank : null,
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
            'question_index' => $this->format_question_index_rows($this->get_group_question_index((int) $group->id)),
            'leaderboard' => array_map([$this, 'format_leaderboard_row'], $this->get_leaderboard_for_group($group)),
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
            'occurrence_id' => (int) $row->id,
            'question_id' => (int) $row->question_id,
            'scheduled_date' => (string) $row->scheduled_date,
            'current_level' => (string) $row->current_level,
        ];
    }

    protected function format_question_index_rows($rows) {
        $formatted = [];
        $position = 1;

        foreach ((array) $rows as $row) {
            $formatted[] = [
                'question_id' => (int) $row->id,
                'number' => $position,
                'internal_label' => !empty($row->internal_label) ? (string) $row->internal_label : ('Q' . (int) $row->id),
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

    public function ajax_get_dashboard() {
        $this->verify_nonce();
        $user = $this->require_recall_user();

        $groups = $this->get_groups_for_user((int) $user->id);
        if (empty($groups)) {
            wp_send_json_success([
                'user' => [
                    'id' => (int) $user->id,
                    'first_name' => (string) $user->first_name,
                    'display_name' => InSkill_Recall_Auth::get_display_name($user),
                ],
                'groups' => [],
                'preferences' => InSkill_Recall_Auth::get_notification_preferences($user),
            ]);
        }

        $payloadGroups = [];
        foreach ($groups as $group) {
            $payloadGroups[] = $this->build_group_dashboard_payload($group, $user);
        }

        wp_send_json_success([
            'user' => [
                'id' => (int) $user->id,
                'first_name' => (string) $user->first_name,
                'display_name' => InSkill_Recall_Auth::get_display_name($user),
            ],
            'groups' => $payloadGroups,
            'preferences' => InSkill_Recall_Auth::get_notification_preferences($user),
        ]);
    }

    public function ajax_get_question() {
        $this->verify_nonce();
        $user = $this->require_recall_user();

        $occurrence_id = isset($_POST['occurrence_id']) ? (int) $_POST['occurrence_id'] : 0;
        if ($occurrence_id <= 0) {
            wp_send_json_error(['message' => 'Occurrence invalide.'], 400);
        }

        $payload = $this->get_question_payload_for_occurrence($occurrence_id, (int) $user->id);
        if (is_wp_error($payload)) {
            wp_send_json_error(['message' => $payload->get_error_message()], 400);
        }

        wp_send_json_success($payload);
    }

    public function ajax_submit_answer() {
        $this->verify_nonce();
        $user = $this->require_recall_user();

        $occurrence_id = isset($_POST['occurrence_id']) ? (int) $_POST['occurrence_id'] : 0;
        $selected_choice_ids = isset($_POST['selected_choice_ids']) ? (array) $_POST['selected_choice_ids'] : [];
        $selected_choice_ids = array_values(array_filter(array_map('intval', $selected_choice_ids)));

        if ($occurrence_id <= 0) {
            wp_send_json_error(['message' => 'Occurrence invalide.'], 400);
        }

        if (empty($selected_choice_ids)) {
            wp_send_json_error(['message' => 'Veuillez sélectionner au moins une réponse.'], 400);
        }

        $occurrence = InSkill_Recall_V2_Occurrence_Service::get_occurrence($occurrence_id);
        if (!$occurrence || (int) $occurrence->recall_user_id !== (int) $user->id) {
            wp_send_json_error(['message' => 'Occurrence invalide.'], 403);
        }

        $result = InSkill_Recall_V2_Engine::submit_answer($occurrence_id, $selected_choice_ids);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        $questionPayload = $this->get_question_payload_for_occurrence($occurrence_id, (int) $user->id);
        wp_send_json_success([
            'result' => $result,
            'question' => $questionPayload,
        ]);
    }

    public function ajax_save_push_subscription() {
        $this->verify_nonce();
        $user = $this->require_recall_user();

        $subscription = isset($_POST['subscription']) ? wp_unslash($_POST['subscription']) : '';
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        $saved = InSkill_Recall_Push::save_subscription((int) $user->id, $subscription, $userAgent);
        if (is_wp_error($saved)) {
            wp_send_json_error(['message' => $saved->get_error_message()], 400);
        }

        wp_send_json_success(['message' => 'Abonnement enregistré.']);
    }

    public function ajax_save_preferences() {
        $this->verify_nonce();
        $user = $this->require_recall_user();

        $hour = isset($_POST['notification_hour']) ? (int) $_POST['notification_hour'] : InSkill_Recall_Auth::DEFAULT_NOTIFICATION_HOUR;
        $minute = isset($_POST['notification_minute']) ? (int) $_POST['notification_minute'] : InSkill_Recall_Auth::DEFAULT_NOTIFICATION_MINUTE;
        $timezone = isset($_POST['notification_timezone']) ? sanitize_text_field(wp_unslash($_POST['notification_timezone'])) : InSkill_Recall_Auth::DEFAULT_NOTIFICATION_TIMEZONE;
        $allow_weekend = !empty($_POST['notifications_weekend']) ? 1 : 0;

        $updated = InSkill_Recall_Auth::update_notification_preferences((int) $user->id, $hour, $minute, $allow_weekend, $timezone);
        if (!$updated) {
            wp_send_json_error(['message' => 'Impossible d’enregistrer les préférences.'], 400);
        }

        $freshUser = InSkill_Recall_Auth::get_user((int) $user->id);

        wp_send_json_success([
            'preferences' => InSkill_Recall_Auth::get_notification_preferences($freshUser),
        ]);
    }
}


