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
        $cron_mode = InSkill_Recall_V2_Cron::get_cron_mode();
        $cron_token = InSkill_Recall_V2_Cron::get_external_cron_token();
        $cron_endpoint = InSkill_Recall_V2_Cron::get_external_cron_endpoint_url();
        $cron_command = '5-59/5 * * * * /usr/bin/curl -fsS "' . $cron_endpoint . '?token=' . rawurlencode($cron_token) . '&ts=$(/bin/date +\%s)" > /dev/null 2>&1';

        $selected_test_user_id = isset($_GET['test_push_user_id']) ? (int) $_GET['test_push_user_id'] : 0;
        if ($selected_test_user_id <= 0 && !empty($users)) {
            $selected_test_user_id = (int) $users[0]->id;
        }
        ?>
        <div class="wrap">
            <h1>InSkill Recall — Notifications</h1>
            <?php $this->render_notice(); ?>

            <style>
                .inskill-notif-grid {
                    display: grid;
                    grid-template-columns: minmax(380px, 520px) 1fr;
                    gap: 24px;
                    align-items: start;
                }
                .inskill-card {
                    background: #fff;
                    border: 1px solid #dcdcde;
                    border-radius: 12px;
                    padding: 20px;
                    box-sizing: border-box;
                }
                .inskill-card + .inskill-card {
                    margin-top: 24px;
                }
                .inskill-card h2 {
                    margin-top: 0;
                    margin-bottom: 8px;
                }
                .inskill-card-intro {
                    margin: 0 0 18px 0;
                    color: #646970;
                    line-height: 1.5;
                }
                .inskill-form-grid {
                    display: grid;
                    gap: 16px;
                }
                .inskill-field-row {
                    display: grid;
                    gap: 8px;
                }
                .inskill-field-row label,
                .inskill-field-row .inskill-field-label {
                    display: block;
                    font-weight: 600;
                }
                .inskill-field-row .description {
                    margin: 0;
                    color: #646970;
                }
                .inskill-field-row textarea,
                .inskill-field-row input[type="text"],
                .inskill-field-row select {
                    width: 100%;
                    max-width: 100%;
                }
                .inskill-radio-stack {
                    display: grid;
                    gap: 10px;
                    margin-top: 4px;
                }
                .inskill-radio-option {
                    display: block;
                    border: 1px solid #dcdcde;
                    border-radius: 10px;
                    padding: 12px 14px;
                    background: #fff;
                }
                .inskill-radio-option input {
                    margin-right: 8px;
                    margin-top: 1px;
                }
                .inskill-radio-title {
                    font-weight: 600;
                }
                .inskill-radio-help {
                    margin: 6px 0 0 24px;
                    color: #646970;
                    line-height: 1.45;
                }
                .inskill-code-box {
                    width: 100%;
                    max-width: 100%;
                    min-height: 68px;
                    font-family: Consolas, Monaco, monospace;
                    white-space: pre-wrap;
                    word-break: break-word;
                    overflow-wrap: anywhere;
                    resize: vertical;
                }
                .inskill-code-box.is-single-line {
                    min-height: 54px;
                }
                .inskill-summary-list {
                    display: grid;
                    grid-template-columns: repeat(2, minmax(180px, 1fr));
                    gap: 12px;
                    margin: 0;
                }
                .inskill-summary-item {
                    border: 1px solid #dcdcde;
                    border-radius: 10px;
                    padding: 14px;
                    background: #f6f7f7;
                }
                .inskill-summary-item strong {
                    display: block;
                    font-size: 24px;
                    line-height: 1.2;
                    margin-bottom: 4px;
                }
                .inskill-summary-item span {
                    color: #646970;
                }
                .inskill-inline-form {
                    display: flex;
                    gap: 12px;
                    align-items: end;
                    flex-wrap: wrap;
                }
                .inskill-inline-form .inskill-field-row {
                    flex: 1 1 320px;
                }
                .inskill-scroll-table {
                    max-height: 420px;
                    overflow: auto;
                    border: 1px solid #dcdcde;
                    border-radius: 10px;
                }
                .inskill-scroll-table table {
                    margin: 0;
                    border: none;
                }
                .inskill-scroll-table thead th {
                    position: sticky;
                    top: 0;
                    background: #fff;
                    z-index: 1;
                }
                .inskill-external-only[hidden] {
                    display: none !important;
                }
                @media (max-width: 1280px) {
                    .inskill-notif-grid {
                        grid-template-columns: 1fr;
                    }
                }
                @media (max-width: 782px) {
                    .inskill-summary-list {
                        grid-template-columns: 1fr;
                    }
                }
            </style>

            <div class="inskill-notif-grid">
                <div>
                    <form method="post">
                        <?php wp_nonce_field('inskill_recall_admin_action'); ?>
                        <input type="hidden" name="inskill_recall_action" value="save_notification_settings">

                        <div class="inskill-card">
                            <h2>Configuration push</h2>
                            <p class="inskill-card-intro">Paramètres VAPID et fuseaux autorisés pour les notifications web push.</p>

                            <div class="inskill-form-grid">
                                <div class="inskill-field-row">
                                    <label for="vapid_subject">VAPID subject</label>
                                    <input type="text" class="regular-text" name="vapid_subject" id="vapid_subject" value="<?php echo esc_attr($vapid_subject); ?>">
                                </div>

                                <div class="inskill-field-row">
                                    <label for="vapid_public_key">VAPID public key</label>
                                    <textarea class="large-text code" rows="4" name="vapid_public_key" id="vapid_public_key"><?php echo esc_textarea($vapid_public_key); ?></textarea>
                                </div>

                                <div class="inskill-field-row">
                                    <label for="vapid_private_key">VAPID private key</label>
                                    <textarea class="large-text code" rows="4" name="vapid_private_key" id="vapid_private_key"><?php echo esc_textarea($vapid_private_key); ?></textarea>
                                </div>

                                <div class="inskill-field-row">
                                    <label for="allowed_timezones">Fuseaux autorisés</label>
                                    <textarea class="large-text code" rows="8" name="allowed_timezones" id="allowed_timezones"><?php echo esc_textarea($allowed_timezones); ?></textarea>
                                    <p class="description">Format : Libellé|Identifiant timezone, une ligne par fuseau.</p>
                                </div>
                            </div>
                        </div>

                        <div class="inskill-card">
                            <h2>Déclenchement du cron</h2>
                            <p class="inskill-card-intro">Un seul mode doit être actif à la fois. Le moteur V2 n’accepte que la source correspondant au mode sélectionné.</p>

                            <div class="inskill-form-grid">
                                <div class="inskill-field-row">
                                    <div class="inskill-field-label">Mode actif</div>
                                    <div class="inskill-radio-stack">
                                        <label class="inskill-radio-option">
                                            <input type="radio" name="cron_mode" value="<?php echo esc_attr(InSkill_Recall_V2_Cron::CRON_MODE_WP); ?>" <?php checked($cron_mode, InSkill_Recall_V2_Cron::CRON_MODE_WP); ?>>
                                            <span class="inskill-radio-title">Cron WordPress / hébergement (wp-cron.php)</span>
                                            <p class="inskill-radio-help">Mode compatible avec le cron OVH qui exécute directement <code>wp-cron.php</code>.</p>
                                        </label>
                                        <label class="inskill-radio-option">
                                            <input type="radio" name="cron_mode" value="<?php echo esc_attr(InSkill_Recall_V2_Cron::CRON_MODE_EXTERNAL_VPS); ?>" <?php checked($cron_mode, InSkill_Recall_V2_Cron::CRON_MODE_EXTERNAL_VPS); ?>>
                                            <span class="inskill-radio-title">Cron externe VPS Oracle (endpoint sécurisé)</span>
                                            <p class="inskill-radio-help">Mode recommandé pour un déclenchement plus précis. Les appels via <code>wp-cron.php</code> sont alors ignorés par le moteur V2.</p>
                                        </label>
                                    </div>
                                </div>

                                <div class="inskill-field-row inskill-external-only" data-external-only>
                                    <label for="cron_token">Token cron externe</label>
                                    <input type="text" class="large-text code" name="cron_token" id="cron_token" value="<?php echo esc_attr($cron_token); ?>">
                                    <p class="description">Token secret obligatoire pour le mode VPS externe. S’il est vide ou invalide, un nouveau token robuste sera généré à l’enregistrement.</p>
                                </div>

                                <div class="inskill-field-row inskill-external-only" data-external-only>
                                    <label for="inskill-cron-endpoint">Endpoint VPS externe</label>
                                    <textarea id="inskill-cron-endpoint" class="inskill-code-box is-single-line" readonly><?php echo esc_textarea($cron_endpoint . '?token=' . $cron_token); ?></textarea>
                                    <p class="description">URL sécurisée à appeler depuis le VPS quand le mode externe est actif.</p>
                                </div>

                                <div class="inskill-field-row inskill-external-only" data-external-only>
                                    <label for="inskill-cron-command">Commande cron VPS recommandée</label>
                                    <textarea id="inskill-cron-command" class="inskill-code-box" readonly><?php echo esc_textarea($cron_command); ?></textarea>
                                    <p class="description">Exemple production prévu pour un déclenchement toutes les 5 minutes à partir de <code>:05</code>. Le paramètre <code>ts</code> est volontairement ajouté pour éviter le cache CDN.</p>
                                </div>
                            </div>
                        </div>

                        <p>
                            <button type="submit" class="button button-primary">Enregistrer</button>
                        </p>
                    </form>
                </div>

                <div>
                    <div class="inskill-card">
                        <h2>Résumé</h2>
                        <div class="inskill-summary-list">
                            <div class="inskill-summary-item">
                                <strong><?php echo esc_html($summary['subscriptions_total']); ?></strong>
                                <span>Appareils enregistrés</span>
                            </div>
                            <div class="inskill-summary-item">
                                <strong><?php echo esc_html($summary['subscriptions_active']); ?></strong>
                                <span>Appareils actifs</span>
                            </div>
                            <div class="inskill-summary-item">
                                <strong><?php echo esc_html($summary['logs_total']); ?></strong>
                                <span>Notifications journalisées</span>
                            </div>
                            <div class="inskill-summary-item">
                                <strong><?php echo esc_html($summary['logs_sent']); ?></strong>
                                <span>Notifications envoyées avec succès</span>
                            </div>
                        </div>
                    </div>

                    <div class="inskill-card">
                        <h2>Test push admin</h2>
                        <p class="inskill-card-intro">Ce test envoie une notification push de vérification à l’utilisateur sélectionné sans impacter la logique métier quotidienne.</p>

                        <form method="post" class="inskill-inline-form">
                            <?php wp_nonce_field('inskill_recall_admin_action'); ?>
                            <input type="hidden" name="inskill_recall_action" value="send_test_push_notification">

                            <div class="inskill-field-row">
                                <label for="test_push_user_id">Utilisateur cible</label>
                                <select name="test_push_user_id" id="test_push_user_id">
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

                    <div class="inskill-card">
                        <h2>Appareils abonnés</h2>

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

                    <div class="inskill-card">
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

                        <div class="inskill-scroll-table">
                            <table class="widefat striped">
                                <thead>
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

            <script>
                (function () {
                    const radios = document.querySelectorAll('input[name="cron_mode"]');
                    const externalRows = document.querySelectorAll('[data-external-only]');
                    if (!radios.length || !externalRows.length) {
                        return;
                    }

                    function refreshCronModeUi() {
                        let current = '';
                        radios.forEach(function (radio) {
                            if (radio.checked) {
                                current = radio.value;
                            }
                        });

                        const isExternal = current === '<?php echo esc_js(InSkill_Recall_V2_Cron::CRON_MODE_EXTERNAL_VPS); ?>';
                        externalRows.forEach(function (row) {
                            row.hidden = !isExternal;
                        });
                    }

                    radios.forEach(function (radio) {
                        radio.addEventListener('change', refreshCronModeUi);
                    });

                    refreshCronModeUi();
                })();
            </script>
        </div>
        <?php
    }
}
