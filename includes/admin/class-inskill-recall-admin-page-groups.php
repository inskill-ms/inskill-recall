<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_Admin_Page_Groups {
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
            'group_created' => 'Groupe créé.',
            'group_updated' => 'Groupe mis à jour.',
            'group_deleted' => 'Groupe supprimé.',
            'group_duplicated' => 'Groupe dupliqué. Le clone a été créé en inactif, sans participants, avec la date de démarrage du jour.',
            'group_create_error' => 'Erreur lors de la création du groupe.',
            'group_duplicate_error' => 'Erreur lors de la duplication du groupe.',
        ];

        if (!isset($map[$message])) {
            return;
        }

        $class = 'notice notice-success is-dismissible';
        if (in_array($message, ['group_create_error', 'group_duplicate_error'], true)) {
            $class = 'notice notice-error is-dismissible';
        }

        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($map[$message]) . '</p></div>';
    }

    private function get_leaderboard_mode_label($mode) {
        switch ((string) $mode) {
            case 'A':
                return 'Mode A : classement complet';
            case 'C':
                return 'Mode C : position personnelle uniquement';
            default:
                return 'Mode B : top dynamique + position personnelle';
        }
    }

    public function render() {
        $edit_group = null;
        $memberships = [];
        if (!empty($_GET['edit_group'])) {
            $edit_group = $this->repository->get_group((int) $_GET['edit_group']);
            if ($edit_group) {
                $memberships = $this->repository->get_group_memberships((int) $edit_group->id);
            }
        }

        $groups = $this->repository->get_groups();
        $users = $this->repository->get_users();
        $selected_member_ids = array_map(static function ($row) {
            return (int) $row->recall_user_id;
        }, $memberships);
        ?>
        <div class="wrap">
            <h1>InSkill Recall — Groupes</h1>
            <?php $this->render_notice(); ?>

            <div style="display:grid;grid-template-columns:minmax(340px,460px) 1fr;gap:24px;align-items:start;">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;">
                    <h2 style="margin-top:0;"><?php echo $edit_group ? 'Modifier le groupe' : 'Nouveau groupe'; ?></h2>

                    <form method="post">
                        <?php wp_nonce_field('inskill_recall_admin_action'); ?>
                        <input type="hidden" name="inskill_recall_action" value="save_group">
                        <input type="hidden" name="group_id" value="<?php echo esc_attr($edit_group->id ?? 0); ?>">

                        <table class="form-table" role="presentation">
                            <tr>
                                <th><label for="name">Nom</label></th>
                                <td><input type="text" class="regular-text" name="name" id="name" value="<?php echo esc_attr($edit_group->name ?? ''); ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="description">Description</label></th>
                                <td><textarea class="large-text" rows="4" name="description" id="description"><?php echo esc_textarea($edit_group->description ?? ''); ?></textarea></td>
                            </tr>
                            <tr>
                                <th><label for="start_date">Date de démarrage</label></th>
                                <td><input type="date" name="start_date" id="start_date" value="<?php echo esc_attr($edit_group->start_date ?? ''); ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="status">Statut</label></th>
                                <td>
                                    <select name="status" id="status">
                                        <option value="active" <?php selected($edit_group->status ?? 'active', 'active'); ?>>Actif</option>
                                        <option value="inactive" <?php selected($edit_group->status ?? '', 'inactive'); ?>>Inactif</option>
                                        <option value="deleted" <?php selected($edit_group->status ?? '', 'deleted'); ?>>Supprimé</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="leaderboard_mode">Mode de classement</label></th>
                                <td>
                                    <select name="leaderboard_mode" id="leaderboard_mode">
                                        <option value="A" <?php selected($edit_group->leaderboard_mode ?? 'B', 'A'); ?>>Mode A : classement complet</option>
                                        <option value="B" <?php selected($edit_group->leaderboard_mode ?? 'B', 'B'); ?>>Mode B : top dynamique + position personnelle</option>
                                        <option value="C" <?php selected($edit_group->leaderboard_mode ?? 'B', 'C'); ?>>Mode C : position personnelle uniquement</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="question_order_mode">Ordre des nouvelles questions</label></th>
                                <td>
                                    <select name="question_order_mode" id="question_order_mode">
                                        <option value="ordered" <?php selected($edit_group->question_order_mode ?? 'ordered', 'ordered'); ?>>Dans l’ordre</option>
                                        <option value="random" <?php selected($edit_group->question_order_mode ?? 'ordered', 'random'); ?>>Aléatoire pour chacun</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Participants</th>
                                <td>
                                    <div style="max-height:240px;overflow:auto;border:1px solid #dcdcde;padding:10px;border-radius:8px;background:#fff;">
                                        <?php if (empty($users)) : ?>
                                            <p style="margin:0;">Aucun utilisateur disponible.</p>
                                        <?php else : ?>
                                            <?php foreach ($users as $user) : ?>
                                                <label style="display:block;margin-bottom:8px;">
                                                    <input type="checkbox" name="member_ids[]" value="<?php echo esc_attr($user->id); ?>" <?php checked(in_array((int) $user->id, $selected_member_ids, true)); ?>>
                                                    <?php echo esc_html(trim($user->first_name . ' ' . $user->last_name) ?: $user->email); ?>
                                                </label>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        </table>

                        <p>
                            <button type="submit" class="button button-primary"><?php echo $edit_group ? 'Mettre à jour' : 'Créer'; ?></button>
                            <?php if ($edit_group) : ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=inskill-recall-groups')); ?>" class="button">Annuler</a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;">
                    <h2 style="margin-top:0;">Liste des groupes</h2>

                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nom</th>
                                <th>Démarrage</th>
                                <th>Statut</th>
                                <th>Classement</th>
                                <th>Ordre</th>
                                <th>Participants</th>
                                <th>Questions</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($groups)) : ?>
                                <tr><td colspan="9">Aucun groupe.</td></tr>
                            <?php else : ?>
                                <?php foreach ($groups as $group) : ?>
                                    <tr>
                                        <td><?php echo esc_html($group->id); ?></td>
                                        <td><?php echo esc_html($group->name); ?></td>
                                        <td><?php echo esc_html($group->start_date); ?></td>
                                        <td><?php echo esc_html($group->status); ?></td>
                                        <td><?php echo esc_html($this->get_leaderboard_mode_label($group->leaderboard_mode)); ?></td>
                                        <td><?php echo esc_html($group->question_order_mode === 'random' ? 'Aléatoire pour chacun' : 'Dans l’ordre'); ?></td>
                                        <td><?php echo esc_html((int) $group->participants_count); ?></td>
                                        <td><?php echo esc_html((int) $group->questions_count); ?></td>
                                        <td>
                                            <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'inskill-recall-groups', 'edit_group' => $group->id], admin_url('admin.php'))); ?>">Éditer</a>

                                            <form method="post" style="display:inline;" onsubmit="return confirm('Dupliquer ce groupe avec ses questions, sans participants ?');">
                                                <?php wp_nonce_field('inskill_recall_admin_action'); ?>
                                                <input type="hidden" name="inskill_recall_action" value="duplicate_group">
                                                <input type="hidden" name="group_id" value="<?php echo esc_attr($group->id); ?>">
                                                <button type="submit" class="button button-small">Dupliquer</button>
                                            </form>

                                            <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer ce groupe ?');">
                                                <?php wp_nonce_field('inskill_recall_admin_action'); ?>
                                                <input type="hidden" name="inskill_recall_action" value="delete_group">
                                                <input type="hidden" name="group_id" value="<?php echo esc_attr($group->id); ?>">
                                                <button type="submit" class="button button-small">Supprimer</button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php if (!empty($group->description)) : ?>
                                        <tr>
                                            <td colspan="9"><?php echo wp_kses_post($group->description); ?></td>
                                        </tr>
                                    <?php endif; ?>
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