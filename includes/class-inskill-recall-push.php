<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_Push {
    protected static function subscriptions_table() {
        return InSkill_Recall_DB::table('push_subscriptions');
    }

    protected static function users_table() {
        return InSkill_Recall_DB::table('users');
    }

    protected static function get_last_notified_column_name() {
        if (class_exists('InSkill_Recall_Time') && InSkill_Recall_Time::is_test_mode_enabled()) {
            return 'last_notified_at_simulated';
        }

        return 'last_notified_at';
    }

    protected static function get_last_notified_value_for_context($user) {
        if (!$user) {
            return null;
        }

        $column = self::get_last_notified_column_name();

        if (!isset($user->{$column}) || empty($user->{$column})) {
            return null;
        }

        return (string) $user->{$column};
    }

    public static function get_vapid_subject() {
        return (string) get_option('inskill_recall_vapid_subject', 'mailto:contact@example.com');
    }

    public static function get_vapid_public_key() {
        return (string) get_option('inskill_recall_vapid_public_key', '');
    }

    public static function get_vapid_private_key() {
        return (string) get_option('inskill_recall_vapid_private_key', '');
    }

    public static function has_vapid_configuration() {
        return self::get_vapid_subject() !== ''
            && self::get_vapid_public_key() !== ''
            && self::get_vapid_private_key() !== '';
    }

    protected static function sanitize_user_agent($user_agent) {
        return sanitize_textarea_field((string) $user_agent);
    }

    protected static function cleanup_duplicate_subscriptions_for_user_device($recall_user_id, $user_agent, $keep_subscription_id, $keep_endpoint_hash) {
        global $wpdb;

        $recall_user_id = (int) $recall_user_id;
        $keep_subscription_id = (int) $keep_subscription_id;
        $keep_endpoint_hash = (string) $keep_endpoint_hash;
        $user_agent = self::sanitize_user_agent($user_agent);

        if ($recall_user_id <= 0 || $keep_subscription_id <= 0 || $user_agent === '') {
            return;
        }

        $duplicates = $wpdb->get_results($wpdb->prepare(
            "SELECT id, endpoint_hash
             FROM " . self::subscriptions_table() . "
             WHERE recall_user_id = %d
               AND status = 'active'
               AND user_agent = %s
               AND id != %d
             ORDER BY updated_at DESC, id DESC",
            $recall_user_id,
            $user_agent,
            $keep_subscription_id
        ));

        if (empty($duplicates)) {
            return;
        }

        foreach ($duplicates as $duplicate) {
            if (!empty($duplicate->endpoint_hash) && (string) $duplicate->endpoint_hash === $keep_endpoint_hash) {
                continue;
            }

            $wpdb->update(
                self::subscriptions_table(),
                [
                    'status'             => 'inactive',
                    'last_error_at'      => current_time('mysql'),
                    'last_error_message' => 'duplicate_subscription_cleaned',
                    'updated_at'         => current_time('mysql'),
                ],
                ['id' => (int) $duplicate->id]
            );
        }
    }

    public static function normalize_subscription_payload($subscription) {
        if (is_string($subscription)) {
            $decoded = json_decode($subscription, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $subscription = $decoded;
            }
        }

        if (!is_array($subscription)) {
            return null;
        }

        if (empty($subscription['endpoint'])) {
            return null;
        }

        $endpoint = esc_url_raw((string) $subscription['endpoint']);
        if ($endpoint === '') {
            return null;
        }

        $keys = isset($subscription['keys']) && is_array($subscription['keys']) ? $subscription['keys'] : [];
        $p256dh = isset($keys['p256dh']) ? sanitize_text_field((string) $keys['p256dh']) : '';
        $auth = isset($keys['auth']) ? sanitize_text_field((string) $keys['auth']) : '';

        if ($p256dh === '' || $auth === '') {
            return null;
        }

        return [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => $p256dh,
                'auth' => $auth,
            ],
        ];
    }

    public static function save_subscription($recall_user_id, $subscription, $user_agent = '') {
        global $wpdb;

        $recall_user_id = (int) $recall_user_id;
        if ($recall_user_id <= 0) {
            return new WP_Error('invalid_user', 'Utilisateur invalide.');
        }

        $payload = self::normalize_subscription_payload($subscription);
        if (!$payload) {
            return new WP_Error('invalid_subscription', 'Abonnement push invalide.');
        }

        $endpoint_hash = hash('sha256', $payload['endpoint']);
        $table = self::subscriptions_table();
        $user_agent = self::sanitize_user_agent($user_agent);

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE endpoint_hash = %s LIMIT 1",
            $endpoint_hash
        ));

        $now = current_time('mysql');
        $data = [
            'recall_user_id'    => $recall_user_id,
            'endpoint_hash'     => $endpoint_hash,
            'endpoint'          => $payload['endpoint'],
            'subscription_json' => wp_json_encode($payload),
            'user_agent'        => $user_agent !== '' ? $user_agent : null,
            'status'            => 'active',
            'updated_at'        => $now,
        ];

        $saved_subscription_id = 0;

        if ($existing) {
            $result = $wpdb->update($table, $data, ['id' => (int) $existing->id]);
            if ($result === false) {
                return new WP_Error('db_update_failed', 'Impossible de mettre à jour l’abonnement push.');
            }
            $saved_subscription_id = (int) $existing->id;
        } else {
            $data['created_at'] = $now;
            $result = $wpdb->insert($table, $data);

            if ($result === false) {
                return new WP_Error('db_insert_failed', 'Impossible d’enregistrer l’abonnement push.');
            }

            $saved_subscription_id = (int) $wpdb->insert_id;
        }

        self::cleanup_duplicate_subscriptions_for_user_device(
            $recall_user_id,
            $user_agent,
            $saved_subscription_id,
            $endpoint_hash
        );

        return true;
    }

    public static function get_active_subscriptions_for_user($recall_user_id) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT *
             FROM " . self::subscriptions_table() . "
             WHERE recall_user_id = %d
               AND status = 'active'
             ORDER BY id ASC",
            (int) $recall_user_id
        ));
    }

    public static function deactivate_subscription($subscription_id, $error_message = '') {
        global $wpdb;

        $wpdb->update(
            self::subscriptions_table(),
            [
                'status'             => 'inactive',
                'last_error_at'      => current_time('mysql'),
                'last_error_message' => $error_message ? sanitize_textarea_field($error_message) : null,
                'updated_at'         => current_time('mysql'),
            ],
            ['id' => (int) $subscription_id]
        );
    }

    public static function mark_subscription_success($subscription_id) {
        global $wpdb;

        $wpdb->update(
            self::subscriptions_table(),
            [
                'last_success_at'    => current_time('mysql'),
                'last_error_at'      => null,
                'last_error_message' => null,
                'status'             => 'active',
                'updated_at'         => current_time('mysql'),
            ],
            ['id' => (int) $subscription_id]
        );
    }

    public static function mark_user_notified($recall_user_id) {
        global $wpdb;

        $column = self::get_last_notified_column_name();
        $now = InSkill_Recall_Time::now_mysql();

        $wpdb->update(
            self::users_table(),
            [
                $column      => $now,
                'updated_at' => $now,
            ],
            ['id' => (int) $recall_user_id]
        );
    }

    protected static function send_payload_to_user($recall_user_id, array $payload, $mark_notified = true) {
        $subscriptions = self::get_active_subscriptions_for_user($recall_user_id);
        if (empty($subscriptions)) {
            return false;
        }

        if (!class_exists('\Minishlink\WebPush\WebPush') || !class_exists('\Minishlink\WebPush\Subscription')) {
            return false;
        }

        if (!self::has_vapid_configuration()) {
            return false;
        }

        $auth = [
            'VAPID' => [
                'subject'    => self::get_vapid_subject(),
                'publicKey'  => self::get_vapid_public_key(),
                'privateKey' => self::get_vapid_private_key(),
            ],
        ];

        try {
            $webPush = new \Minishlink\WebPush\WebPush($auth);
            $webPush->setReuseVAPIDHeaders(true);
        } catch (Exception $e) {
            return false;
        }

        $queued = 0;

        foreach ($subscriptions as $subscription) {
            $subscriptionPayload = json_decode((string) $subscription->subscription_json, true);
            if (!is_array($subscriptionPayload)) {
                self::deactivate_subscription((int) $subscription->id, 'subscription_json_invalid');
                continue;
            }

            try {
                $webPush->queueNotification(
                    \Minishlink\WebPush\Subscription::create($subscriptionPayload),
                    wp_json_encode($payload)
                );
                $queued++;
            } catch (Exception $e) {
                self::deactivate_subscription((int) $subscription->id, $e->getMessage());
            }
        }

        if ($queued <= 0) {
            return false;
        }

        $success = false;

        foreach ($webPush->flush() as $report) {
            try {
                $endpoint = method_exists($report, 'getEndpoint') ? (string) $report->getEndpoint() : '';
                $endpoint_hash = $endpoint !== '' ? hash('sha256', $endpoint) : '';
                $subscriptionId = 0;

                if ($endpoint_hash !== '') {
                    foreach ($subscriptions as $sub) {
                        if ((string) $sub->endpoint_hash === $endpoint_hash) {
                            $subscriptionId = (int) $sub->id;
                            break;
                        }
                    }
                }

                if ($report->isSuccess()) {
                    if ($subscriptionId > 0) {
                        self::mark_subscription_success($subscriptionId);
                    }
                    $success = true;
                } else {
                    $reason = method_exists($report, 'getReason') ? (string) $report->getReason() : 'push_send_failed';
                    if ($subscriptionId > 0) {
                        self::deactivate_subscription($subscriptionId, $reason);
                    }
                }
            } catch (Exception $e) {
            }
        }

        if ($success && $mark_notified) {
            self::mark_user_notified((int) $recall_user_id);
        }

        return $success;
    }

    public static function send_to_user($recall_user_id, array $payload) {
        return self::send_payload_to_user((int) $recall_user_id, $payload, true);
    }

    public static function send_test_to_user($recall_user_id, array $payload) {
        return self::send_payload_to_user((int) $recall_user_id, $payload, false);
    }
}