<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_Admin_Page_Notifications {
    private $repository;

    public function __construct(InSkill_Recall_Admin_Repository $repository) {
        $this->repository = $repository;
    }

    private function get_user_label($row) {
        $first = isset($row->first_name) ? trim((string) $row->first_name) : '';
        $last  = isset($row->last_name) ? trim((string) $row->last_name) : '';
        $name  = trim($first . ' ' . $last);

        if ($name !== '') {
            return $name;
        }

        if (!empty($row->email)) {
            return (string) $row->email;
        }

        return 'Utilisateur inconnu';
    }

    private function get_recent_active_push_subscriptions($limit = 100) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, u.first_name, u.last_name, u.email
             FROM " . InSkill_Recall_DB::table('push_subscriptions') . " s
             LEFT JOIN " . InSkill_Recall_DB::table('users') . " u ON u.id = s.recall_user_id
             WHERE s.status = 'active'
             ORDER BY s.updated_at DESC, s.id DESC
             LIMIT %d",
            (int) $limit
        ));
    }

    private function render_notice() {
        if (empty($_GET['message'])) {
            return;
        }

        $message = sanitize_text_field(wp_unslash($_GET['message']));

        $map = [
            'notifications_saved'           => 'Réglages notifications enregistrés.',
            'notification_logs_cleared'     => 'Historique des notifications vidé avec succès.',
            'notification_logs_clear_error' => 'Impossible de vider l’historique des notifications.',
            'test_push_sent'                => 'Notification push de test envoyée avec succès.',
            'test_push_error'               => 'Aucune notification de test n’a pu être envoyée. Vérifiez les abonnements push actifs, la configuration VAPID et l’état des appareils de cet utilisateur.',
            'test_push_invalid_user'        => 'Utilisateur cible invalide pour le test push.',
        ];

        if (!isset($map[$message])) {
            return;
        }

        $class = 'notice notice-success is-dismissible';
        if (in_array($message, ['notification_logs_clear_error', 'test_push_error', 'test_push_invalid_user'], true)) {
            $class = 'notice notice-error is-dismissible';
        }

        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($map[$message]) . '</p></div>';
    }

    public function render() {
        $summary = $this->repository->get_notification_summary();
        $logs = $this->repository->get_notification_logs(100);
        $subscriptions = $this->get_recent_active_push_subscriptions(100);
        $users = $this->repository->get_users();

        $allowed_timezones = (string) get_option(
            'inskill_recall_allowed_timezones',
            InSkill_Recall_Auth::get_default_allowed_timezones_raw()
        );

        $vapid_subject = (string) get_option('inskill_recall_vapid_subject', 'mailto:contact@example.com');
        $vapid_public_key = (string) get_option('inskill_recall_vapid_public_key', '');
        $vapid_private_key = (string) get_option('inskill_recall_vapid_private_key', '');

        $selected_test_user_id = isset($_GET['test_push_user_id']) ? (int) $_GET['test_push_user_id'] : 0;
        if ($selected_test_user_id <= 0 && !empty($users)) {
            $selected_test_user_id = (int) $users[0]->id;
        }
        ?>
        <div class="wrap">
            <h1>InSkill Recall — Notifications</h1>
            <?php $this->render_notice(); ?>

            <div style="display:grid;grid-template-columns:minmax(360px,520px) 1fr;gap:24px;align-items:start;">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;">
                    <h2 style="margin-top:0;">Réglages</h2>

                    <form method="post">
                        <?php wp_nonce_field('inskill_recall_admin_action'); ?>
                        <input type="hidden" name="inskill_recall_action" value="save_notification_settings">

                        <table class="form-table" role="presentation">
                            <tr>
                                <th><label for="vapid_subject">VAPID subject</label></th>
                                <td><input type="text" class="regular-text" name="vapid_subject" id="vapid_subject" value="<?php echo esc_attr($vapid_subject); ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="vapid_public_key">VAPID public key</label></th>
                                <td><textarea class="large-text code" rows="4" name="vapid_public_key" id="vapid_public_key"><?php echo esc_textarea($vapid_public_key); ?></textarea></td>
                            </tr>
                            <tr>
                                <th><label for="vapid_private_key">VAPID private key</label></th>
                                <td><textarea class="large-text code" rows="4" name="vapid_private_key" id="vapid_private_key"><?php echo esc_textarea($vapid_private_key); ?></textarea></td>
                            </tr>
                            <tr>
                                <th><label for="allowed_timezones">Fuseaux autorisés</label></th>
                                <td>
                                    <textarea class="large-text code" rows="8" name="allowed_timezones" id="allowed_timezones"><?php echo esc_textarea($allowed_timezones); ?></textarea>
                                    <p class="description">Format : Libellé|Identifiant timezone, une ligne par fuseau.</p>
                                </td>
                            </tr>
                        </table>

                        <p>
                            <button type="submit" class="button button-primary">Enregistrer</button>
                        </p>
                    </form>
                </div>

                <div>
                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;margin-bottom:24px;">
                        <h2 style="margin-top:0;">Résumé</h2>
                        <ul style="margin:0 0 0 18px;">
                            <li>Appareils enregistrés : <?php echo esc_html($summary['subscriptions_total']); ?></li>
                            <li>Appareils actifs : <?php echo esc_html($summary['subscriptions_active']); ?></li>
                            <li>Notifications journalisées : <?php echo esc_html($summary['logs_total']); ?></li>
                            <li>Notifications envoyées avec succès : <?php echo esc_html($summary['logs_sent']); ?></li>
                        </ul>
                    </div>

                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;margin-bottom:24px;">
                        <h2 style="margin-top:0;">Test push admin</h2>
                        <p style="margin:8px 0 16px 0;color:#646970;">
                            Ce test envoie une notification push de vérification à l’utilisateur sélectionné sans impacter la logique métier quotidienne.
                        </p>

                        <form method="post" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap;">
                            <?php wp_nonce_field('inskill_recall_admin_action'); ?>
                            <input type="hidden" name="inskill_recall_action" value="send_test_push_notification">

                            <div>
                                <label for="test_push_user_id" style="display:block;font-weight:600;margin-bottom:6px;">Utilisateur cible</label>
                                <select name="test_push_user_id" id="test_push_user_id" style="min-width:320px;">
                                    <?php if (empty($users)) : ?>
                                        <option value="0">Aucun utilisateur disponible</option>
                                    <?php else : ?>
                                        <?php foreach ($users as $user) : ?>
                                            <option value="<?php echo esc_attr($user->id); ?>" <?php selected((int) $selected_test_user_id, (int) $user->id); ?>>
                                                <?php
                                                echo esc_html(
                                                    sprintf(
                                                        '#%d — %s — %s',
                                                        (int) $user->id,
                                                        $this->get_user_label($user),
                                                        !empty($user->email) ? (string) $user->email : 'sans email'
                                                    )
                                                );
                                                ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div>
                                <button type="submit" class="button button-primary" <?php disabled(empty($users)); ?>>
                                    Envoyer un test push
                                </button>
                            </div>
                        </form>
                    </div>

                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;margin-bottom:24px;">
                        <h2 style="margin-top:0;">Appareils abonnés</h2>

                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th>Utilisateur</th>
                                    <th>Statut</th>
                                    <th>Créé le</th>
                                    <th>Mis à jour le</th>
                                    <th>Dernier succès</th>
                                    <th>Dernière erreur</th>
                                    <th>Appareil / navigateur</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($subscriptions)) : ?>
                                    <tr><td colspan="7">Aucun appareil abonné.</td></tr>
                                <?php else : ?>
                                    <?php foreach ($subscriptions as $subscription) : ?>
                                        <tr>
                                            <td><?php echo esc_html($this->get_user_label($subscription)); ?></td>
                                            <td><?php echo esc_html($subscription->status ?: '—'); ?></td>
                                            <td><?php echo esc_html($subscription->created_at ?: '—'); ?></td>
                                            <td><?php echo esc_html($subscription->updated_at ?: '—'); ?></td>
                                            <td><?php echo esc_html($subscription->last_success_at ?: '—'); ?></td>
                                            <td><?php echo esc_html($subscription->last_error_at ?: '—'); ?></td>
                                            <td><?php echo esc_html($subscription->user_agent ?: '—'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
                            <div>
                                <h2 style="margin:0;">Historique des notifications envoyées</h2>
                                <p style="margin:8px 0 0 0;color:#646970;">Cette section liste les notifications réellement envoyées ou en erreur. Elle ne correspond pas à la liste des appareils abonnés.</p>
                            </div>

                            <form method="post" onsubmit="return confirm('Voulez-vous vraiment vider tout l’historique des notifications envoyées ?');" style="margin:0;">
                                <?php wp_nonce_field('inskill_recall_admin_action'); ?>
                                <input type="hidden" name="inskill_recall_action" value="clear_notification_logs">
                                <button type="submit" class="button button-secondary">Vider l’historique</button>
                            </form>
                        </div>

                        <div style="max-height:420px;overflow:auto;border:1px solid #dcdcde;border-radius:10px;">
                            <table class="widefat striped" style="margin:0;border:none;">
                                <thead style="position:sticky;top:0;background:#fff;z-index:1;">
                                    <tr>
                                        <th>Date</th>
                                        <th>Utilisateur</th>
                                        <th>Groupe</th>
                                        <th>Type</th>
                                        <th>Statut</th>
                                        <th>Message</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($logs)) : ?>
                                        <tr><td colspan="6">Aucune notification journalisée.</td></tr>
                                    <?php else : ?>
                                        <?php foreach ($logs as $log) : ?>
                                            <tr>
                                                <td><?php echo esc_html($log->sent_at); ?></td>
                                                <td><?php echo esc_html($this->get_user_label($log)); ?></td>
                                                <td><?php echo esc_html($log->group_name ?: '—'); ?></td>
                                                <td><?php echo esc_html($log->notification_type); ?></td>
                                                <td><?php echo esc_html($log->status); ?></td>
                                                <td><?php echo esc_html(wp_trim_words($log->body, 12)); ?></td>
                                            </tr>
                                            <?php if (!empty($log->error_message)) : ?>
                                                <tr>
                                                    <td colspan="6"><strong>Erreur :</strong> <?php echo esc_html($log->error_message); ?></td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}