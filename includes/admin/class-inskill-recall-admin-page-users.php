<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_Admin_Page_Users {
    private $repository;

    public function __construct(InSkill_Recall_Admin_Repository $repository) {
        $this->repository = $repository;
    }

    private function render_notice() {
        if (empty($_GET['message'])) {
            return;
        }

        $message = sanitize_text_field(wp_unslash($_GET['message']));
        $map = [
            'user_created' => 'Utilisateur créé.',
            'user_updated' => 'Utilisateur mis à jour.',
            'user_deleted' => 'Utilisateur supprimé.',
            'token_regenerated' => 'Token régénéré.',
            'user_create_error' => 'Erreur lors de la création de l’utilisateur.',
        ];

        if (!isset($map[$message])) {
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($map[$message]) . '</p></div>';
    }

    public function render() {
        $edit_user = null;
        if (!empty($_GET['edit_user'])) {
            $edit_user = $this->repository->get_user((int) $_GET['edit_user']);
        }

        $users = $this->repository->get_users();
        $timezones = InSkill_Recall_Auth::get_allowed_timezones();
        $dashboard_page_url = InSkill_Recall_Frontend::get_dashboard_page_url();
        ?>
        <div class="wrap">
            <h1>InSkill Recall — Utilisateurs</h1>
            <?php $this->render_notice(); ?>

            <div style="display:grid;grid-template-columns:minmax(320px,420px) 1fr;gap:24px;align-items:start;">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;">
                    <h2 style="margin-top:0;"><?php echo $edit_user ? 'Modifier l’utilisateur' : 'Nouvel utilisateur'; ?></h2>

                    <form method="post">
                        <?php wp_nonce_field('inskill_recall_admin_action'); ?>
                        <input type="hidden" name="inskill_recall_action" value="save_user">
                        <input type="hidden" name="user_id" value="<?php echo esc_attr($edit_user ? $edit_user->id : 0); ?>">

                        <table class="form-table" role="presentation">
                            <tr>
                                <th><label for="first_name">Prénom</label></th>
                                <td><input type="text" class="regular-text" name="first_name" id="first_name" value="<?php echo esc_attr($edit_user->first_name ?? ''); ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="last_name">Nom</label></th>
                                <td><input type="text" class="regular-text" name="last_name" id="last_name" value="<?php echo esc_attr($edit_user->last_name ?? ''); ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="email">Email</label></th>
                                <td><input type="email" class="regular-text" name="email" id="email" value="<?php echo esc_attr($edit_user->email ?? ''); ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="status">Statut</label></th>
                                <td>
                                    <select name="status" id="status">
                                        <option value="active" <?php selected($edit_user->status ?? 'active', 'active'); ?>>Actif</option>
                                        <option value="inactive" <?php selected($edit_user->status ?? '', 'inactive'); ?>>Inactif</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="notification_hour">Heure de notification</label></th>
                                <td>
                                    <input type="number" min="0" max="23" name="notification_hour" id="notification_hour" value="<?php echo esc_attr(isset($edit_user->notification_hour) ? (int) $edit_user->notification_hour : 9); ?>" style="width:80px;">
                                    :
                                    <input type="number" min="0" max="59" name="notification_minute" value="<?php echo esc_attr(isset($edit_user->notification_minute) ? (int) $edit_user->notification_minute : 0); ?>" style="width:80px;">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="notification_timezone">Fuseau horaire</label></th>
                                <td>
                                    <select name="notification_timezone" id="notification_timezone">
                                        <?php foreach ($timezones as $tz => $label) : ?>
                                            <option value="<?php echo esc_attr($tz); ?>" <?php selected($edit_user->notification_timezone ?? InSkill_Recall_Auth::DEFAULT_NOTIFICATION_TIMEZONE, $tz); ?>>
                                                <?php echo esc_html($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Week-end</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="notifications_weekend" value="1" <?php checked(!empty($edit_user->notifications_weekend)); ?>>
                                        Recevoir les notifications le week-end
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <p>
                            <button type="submit" class="button button-primary"><?php echo $edit_user ? 'Mettre à jour' : 'Créer'; ?></button>
                            <?php if ($edit_user) : ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=inskill-recall-users')); ?>" class="button">Annuler</a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;">
                    <h2 style="margin-top:0;">Liste des utilisateurs</h2>

                    <?php if (!$dashboard_page_url) : ?>
                        <div class="notice notice-warning inline">
                            <p>La page frontend n’est pas encore configurée. Crée-la depuis le tableau de bord admin pour obtenir des liens personnels complets.</p>
                        </div>
                    <?php endif; ?>

                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nom</th>
                                <th>Email</th>
                                <th>Statut</th>
                                <th>Heure</th>
                                <th>Fuseau</th>
                                <th>Token</th>
                                <th>Lien</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)) : ?>
                                <tr><td colspan="9">Aucun utilisateur.</td></tr>
                            <?php else : ?>
                                <?php foreach ($users as $user) : ?>
                                    <?php $dashboard_url = InSkill_Recall_Frontend::get_user_dashboard_url($user); ?>
                                    <tr>
                                        <td><?php echo esc_html($user->id); ?></td>
                                        <td><?php echo esc_html(trim($user->first_name . ' ' . $user->last_name)); ?></td>
                                        <td><?php echo esc_html($user->email); ?></td>
                                        <td><?php echo esc_html($user->status); ?></td>
                                        <td><?php echo esc_html(sprintf('%02d:%02d', (int) $user->notification_hour, (int) $user->notification_minute)); ?></td>
                                        <td><?php echo esc_html($user->notification_timezone); ?></td>
                                        <td><code><?php echo esc_html(substr($user->access_token, 0, 12)); ?>…</code></td>
                                        <td>
                                            <?php if ($dashboard_url) : ?>
                                                <a class="button button-small" href="<?php echo esc_url($dashboard_url); ?>" target="_blank" rel="noopener">Ouvrir</a>
                                            <?php else : ?>
                                                <span style="color:#646970;">Page frontend absente</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'inskill-recall-users', 'edit_user' => $user->id], admin_url('admin.php'))); ?>">Éditer</a>

                                            <form method="post" style="display:inline;">
                                                <?php wp_nonce_field('inskill_recall_admin_action'); ?>
                                                <input type="hidden" name="inskill_recall_action" value="regenerate_user_token">
                                                <input type="hidden" name="user_id" value="<?php echo esc_attr($user->id); ?>">
                                                <button type="submit" class="button button-small">Régénérer token</button>
                                            </form>

                                            <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer cet utilisateur ?');">
                                                <?php wp_nonce_field('inskill_recall_admin_action'); ?>
                                                <input type="hidden" name="inskill_recall_action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?php echo esc_attr($user->id); ?>">
                                                <button type="submit" class="button button-small">Supprimer</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="9">
                                            Lien personnel :
                                            <?php if ($dashboard_url) : ?>
                                                <code><?php echo esc_html($dashboard_url); ?></code>
                                            <?php else : ?>
                                                <em>Indisponible tant que la page frontend n’est pas créée.</em>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
}