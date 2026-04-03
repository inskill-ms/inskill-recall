<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_Admin_Page_Notifications {
    private $repository;

    public function __construct(InSkill_Recall_Admin_Repository $repository) {
        $this->repository = $repository;
    }

    public function render() {
        $summary = $this->repository->get_notification_summary();
        $logs = $this->repository->get_notification_logs(100);

        $allowed_timezones = (string) get_option(
            'inskill_recall_allowed_timezones',
            InSkill_Recall_Auth::get_default_allowed_timezones_raw()
        );

        $vapid_subject = (string) get_option('inskill_recall_vapid_subject', 'mailto:contact@example.com');
        $vapid_public_key = (string) get_option('inskill_recall_vapid_public_key', '');
        $vapid_private_key = (string) get_option('inskill_recall_vapid_private_key', '');

        ?>
        <div class="wrap">
            <h1>InSkill Recall — Notifications</h1>

            <?php if (!empty($_GET['message']) && sanitize_text_field(wp_unslash($_GET['message'])) === 'notifications_saved') : ?>
                <div class="notice notice-success is-dismissible"><p>Réglages notifications enregistrés.</p></div>
            <?php endif; ?>

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
                            <li>Abonnements total : <?php echo esc_html($summary['subscriptions_total']); ?></li>
                            <li>Abonnements actifs : <?php echo esc_html($summary['subscriptions_active']); ?></li>
                            <li>Logs total : <?php echo esc_html($summary['logs_total']); ?></li>
                            <li>Logs envoyés : <?php echo esc_html($summary['logs_sent']); ?></li>
                        </ul>
                    </div>

                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;">
                        <h2 style="margin-top:0;">Derniers logs</h2>

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
                                    <tr><td colspan="6">Aucun log.</td></tr>
                                <?php else : ?>
                                    <?php foreach ($logs as $log) : ?>
                                        <tr>
                                            <td><?php echo esc_html($log->sent_at); ?></td>
                                            <td><?php echo esc_html(trim($log->first_name . ' ' . $log->last_name) ?: $log->email); ?></td>
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
        <?php
    }
}