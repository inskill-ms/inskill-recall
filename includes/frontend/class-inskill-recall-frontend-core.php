<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_Frontend_Core {
    const SHORTCODE = 'inskill_recall_dashboard';

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
                'systemTimeTitle' => 'Heure système',
                'systemTimeTimezone' => 'Fuseau système',
                'userTimeTitle' => 'Heure utilisateur',
                'userTimeTimezone' => 'Fuseau utilisateur',
                'clockSimulatedBadge' => 'Mode test temporel actif',
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

    protected function get_debug_log_path() {
        $upload_dir = wp_upload_dir();
        $base_dir = !empty($upload_dir['basedir']) ? $upload_dir['basedir'] : WP_CONTENT_DIR . '/uploads';

        if (!is_dir($base_dir)) {
            wp_mkdir_p($base_dir);
        }

        return trailingslashit($base_dir) . 'inskill-recall-debug.log';
    }

    protected function debug_log($channel, array $payload = []) {
        $line = wp_json_encode([
            'time' => current_time('mysql'),
            'channel' => $channel,
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!$line) {
            return;
        }

        @file_put_contents($this->get_debug_log_path(), $line . PHP_EOL, FILE_APPEND);
    }

    protected function sanitize_selected_choice_ids($selected_choice_ids) {
        $values = [];

        if (is_array($selected_choice_ids)) {
            $values = $selected_choice_ids;
        } elseif ($selected_choice_ids !== null && $selected_choice_ids !== '') {
            $values = [$selected_choice_ids];
        }

        $values = array_values(array_filter(array_map('intval', $values)));

        return $values;
    }
}