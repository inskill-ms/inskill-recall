<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_Frontend extends InSkill_Recall_Frontend_Dashboard {
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

    public function ajax_get_dashboard() {
        try {
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
        } catch (Throwable $e) {
            $this->debug_log('ajax_get_dashboard_exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            wp_send_json_error([
                'message' => 'Erreur dashboard. Consultez le log du plugin.',
            ], 500);
        }
    }

    public function ajax_get_question() {
        try {
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
        } catch (Throwable $e) {
            $this->debug_log('ajax_get_question_exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'post' => $_POST,
            ]);

            wp_send_json_error([
                'message' => 'Erreur chargement question. Consultez le log du plugin.',
            ], 500);
        }
    }

    public function ajax_submit_answer() {
        $diagnostic_id = 'IR-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false);

        try {
            $this->verify_nonce();
            $user = $this->require_recall_user();

            $occurrence_id = isset($_POST['occurrence_id']) ? (int) $_POST['occurrence_id'] : 0;
            $selected_choice_ids = isset($_POST['selected_choice_ids']) ? $this->sanitize_selected_choice_ids($_POST['selected_choice_ids']) : [];

            $this->debug_log('ajax_submit_answer_request', [
                'diagnostic_id' => $diagnostic_id,
                'occurrence_id' => $occurrence_id,
                'selected_choice_ids' => $selected_choice_ids,
                'raw_post' => $_POST,
                'user_id' => isset($user->id) ? (int) $user->id : 0,
            ]);

            if ($occurrence_id <= 0) {
                wp_send_json_error([
                    'message' => 'Occurrence invalide.',
                    'diagnostic_id' => $diagnostic_id,
                ], 400);
            }

            if (empty($selected_choice_ids)) {
                wp_send_json_error([
                    'message' => 'Veuillez sélectionner au moins une réponse.',
                    'diagnostic_id' => $diagnostic_id,
                ], 400);
            }

            $occurrence = InSkill_Recall_V2_Occurrence_Service::get_occurrence($occurrence_id);
            if (!$occurrence || (int) $occurrence->recall_user_id !== (int) $user->id) {
                wp_send_json_error([
                    'message' => 'Occurrence invalide.',
                    'diagnostic_id' => $diagnostic_id,
                ], 403);
            }

            $result = InSkill_Recall_V2_Engine::submit_answer($occurrence_id, $selected_choice_ids);
            if (is_wp_error($result)) {
                $this->debug_log('ajax_submit_answer_wp_error', [
                    'diagnostic_id' => $diagnostic_id,
                    'message' => $result->get_error_message(),
                    'occurrence_id' => $occurrence_id,
                    'selected_choice_ids' => $selected_choice_ids,
                ]);

                wp_send_json_error([
                    'message' => $result->get_error_message(),
                    'diagnostic_id' => $diagnostic_id,
                ], 400);
            }

            $questionPayload = $this->get_question_payload_for_occurrence($occurrence_id, (int) $user->id);

            $this->debug_log('ajax_submit_answer_success', [
                'diagnostic_id' => $diagnostic_id,
                'occurrence_id' => $occurrence_id,
                'result' => $result,
            ]);

            wp_send_json_success([
                'result' => $result,
                'question' => $questionPayload,
                'diagnostic_id' => $diagnostic_id,
            ]);
        } catch (Throwable $e) {
            $this->debug_log('ajax_submit_answer_exception', [
                'diagnostic_id' => $diagnostic_id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'post' => $_POST,
            ]);

            wp_send_json_error([
                'message' => 'Erreur serveur lors de l’enregistrement. Diagnostic : ' . $diagnostic_id,
                'diagnostic_id' => $diagnostic_id,
                'log_path' => $this->get_debug_log_path(),
            ], 500);
        }
    }

    public function ajax_save_push_subscription() {
        try {
            $this->verify_nonce();
            $user = $this->require_recall_user();

            $subscription = isset($_POST['subscription']) ? wp_unslash($_POST['subscription']) : '';
            $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

            $saved = InSkill_Recall_Push::save_subscription((int) $user->id, $subscription, $userAgent);
            if (is_wp_error($saved)) {
                wp_send_json_error(['message' => $saved->get_error_message()], 400);
            }

            wp_send_json_success(['message' => 'Abonnement enregistré.']);
        } catch (Throwable $e) {
            $this->debug_log('ajax_save_push_subscription_exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            wp_send_json_error([
                'message' => 'Erreur abonnement notifications. Consultez le log du plugin.',
            ], 500);
        }
    }

    public function ajax_save_preferences() {
        try {
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
        } catch (Throwable $e) {
            $this->debug_log('ajax_save_preferences_exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            wp_send_json_error([
                'message' => 'Erreur sauvegarde préférences. Consultez le log du plugin.',
            ], 500);
        }
    }
}