<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_Admin_Page_Questions {
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
            'question_created' => 'Question créée.',
            'question_updated' => 'Question mise à jour.',
            'question_deleted' => 'Question supprimée.',
            'question_deactivated' => 'Question désactivée.',
            'question_create_error' => 'Erreur lors de la création de la question.',
            'question_locked' => 'Cette question a déjà été utilisée. Vous pouvez modifier son contenu rédactionnel, mais pas la/les bonne(s) réponse(s).',
            'question_validation_error' => !empty($_GET['error_detail']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['error_detail']))) : 'Impossible d’enregistrer la question.',
        ];

        if (!isset($map[$message])) {
            return;
        }

        $class = 'notice notice-success is-dismissible';
        if (in_array($message, ['question_create_error', 'question_validation_error'], true)) {
            $class = 'notice notice-error is-dismissible';
        } elseif ($message === 'question_locked') {
            $class = 'notice notice-warning is-dismissible';
        }

        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($map[$message]) . '</p></div>';
    }

    public function render() {
        $groups = $this->repository->get_groups();

        $edit_question = null;
        $edit_choices = [];
        $is_locked = false;

        if (!empty($_GET['edit_question'])) {
            $edit_question = $this->repository->get_question((int) $_GET['edit_question']);
            if ($edit_question) {
                $edit_choices = $this->repository->get_question_choices((int) $edit_question->id);
                $is_locked = $this->repository->question_has_activity((int) $edit_question->id);
            }
        }

        $questions = $this->repository->get_questions();
        $default_auto_label = $this->repository->get_next_auto_internal_label();
        ?>
        <div class="wrap">
            <h1>InSkill Recall — Questions</h1>
            <?php $this->render_notice(); ?>

            <div style="display:grid;grid-template-columns:minmax(420px,620px) 1fr;gap:24px;align-items:start;">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;">
                    <h2 style="margin-top:0;"><?php echo $edit_question ? 'Modifier la question' : 'Nouvelle question'; ?></h2>

                    <?php if ($is_locked) : ?>
                        <div class="notice notice-warning inline">
                            <p>Cette question a déjà été utilisée. Vous pouvez modifier son contenu rédactionnel, mais pas la/les bonne(s) réponse(s).</p>
                        </div>
                    <?php endif; ?>

                    <form method="post">
                        <?php wp_nonce_field('inskill_recall_admin_action'); ?>
                        <input type="hidden" name="inskill_recall_action" value="save_question">
                        <input type="hidden" name="question_id" value="<?php echo esc_attr($edit_question->id ?? 0); ?>">

                        <table class="form-table" role="presentation">
                            <?php if (!$edit_question) : ?>
                                <tr>
                                    <th>Création</th>
                                    <td>
                                        <label style="display:block;margin-bottom:8px;">
                                            <input type="radio" name="question_creation_mode" value="new" checked>
                                            Nouvelle question
                                        </label>
                                        <label style="display:block;">
                                            <input type="radio" name="question_creation_mode" value="insert">
                                            Insérer une question
                                        </label>
                                        <p class="description">Nouvelle question = libellé interne généré automatiquement. Insérer une question = libellé interne saisi manuellement, par exemple Q0000004-1.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <th><label for="group_id">Groupe</label></th>
                                <td>
                                    <select name="group_id" id="group_id" <?php disabled($is_locked); ?>>
                                        <?php foreach ($groups as $group) : ?>
                                            <option value="<?php echo esc_attr($group->id); ?>" <?php selected($edit_question->group_id ?? 0, $group->id); ?>>
                                                <?php echo esc_html($group->name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="internal_label">Libellé interne</label></th>
                                <td>
                                    <?php if ($edit_question) : ?>
                                        <input type="text" class="regular-text" name="internal_label" id="internal_label" value="<?php echo esc_attr($edit_question->internal_label ?? ''); ?>" disabled>
                                        <p class="description">Le libellé interne n’est pas modifiable.</p>
                                    <?php else : ?>
                                        <input type="text" class="regular-text" name="internal_label" id="internal_label" value="" placeholder="<?php echo esc_attr($default_auto_label); ?> ou Q0000004-1">
                                        <p class="description">Laissez vide pour une nouvelle question classique. Renseignez un libellé seulement si vous souhaitez insérer une question à un endroit précis.</p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="question_type">Type</label></th>
                                <td>
                                    <select name="question_type" id="question_type" <?php disabled($is_locked); ?>>
                                        <option value="qcu" <?php selected($edit_question->question_type ?? 'qcu', 'qcu'); ?>>QCU : Question à choix unique</option>
                                        <option value="qcm" <?php selected($edit_question->question_type ?? 'qcu', 'qcm'); ?>>QCM : Question à choix multiples</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="question_text">Question</label></th>
                                <td><textarea class="large-text" rows="4" name="question_text" id="question_text"><?php echo esc_textarea($edit_question->question_text ?? ''); ?></textarea></td>
                            </tr>
                            <tr>
                                <th><label for="explanation">Explication</label></th>
                                <td><textarea class="large-text" rows="4" name="explanation" id="explanation"><?php echo esc_textarea($edit_question->explanation ?? ''); ?></textarea></td>
                            </tr>
                            <tr>
                                <th><label for="status">Statut</label></th>
                                <td>
                                    <select name="status" id="status">
                                        <option value="active" <?php selected($edit_question->status ?? 'active', 'active'); ?>>Actif</option>
                                        <option value="inactive" <?php selected($edit_question->status ?? '', 'inactive'); ?>>Inactif</option>
                                    </select>
                                </td>
                            </tr>
                        </table>

                        <h3>Choix de réponse</h3>
                        <p>Vous pouvez définir jusqu’à 10 choix. L’enregistrement est bloqué s’il n’y a aucune bonne réponse sélectionnée. Pour une QCU, une seule bonne réponse est autorisée.</p>

                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th>Choix</th>
                                    <th>Bonne réponse</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($i = 0; $i < 10; $i++) : ?>
                                    <?php
                                    $choice_text = '';
                                    $is_correct = false;

                                    if (isset($edit_choices[$i])) {
                                        $choice_text = $edit_choices[$i]->choice_text;
                                        $is_correct = !empty($edit_choices[$i]->is_correct);
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <input type="text" class="regular-text" name="choice_text[<?php echo esc_attr($i); ?>]" value="<?php echo esc_attr($choice_text); ?>">
                                        </td>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="choice_is_correct[<?php echo esc_attr($i); ?>]" value="1" <?php checked($is_correct); ?> <?php disabled($is_locked); ?>>
                                                Correct
                                            </label>
                                        </td>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>

                        <p style="margin-top:16px;">
                            <button type="submit" class="button button-primary"><?php echo $edit_question ? 'Mettre à jour' : 'Créer'; ?></button>
                            <?php if ($edit_question) : ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=inskill-recall-questions')); ?>" class="button">Annuler</a>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>

                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;">
                    <h2 style="margin-top:0;">Liste des questions</h2>

                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Groupe</th>
                                <th>Libellé interne</th>
                                <th>Type</th>
                                <th>Question</th>
                                <th>Choix</th>
                                <th>Statut</th>
                                <th>Verrou</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($questions)) : ?>
                                <tr><td colspan="9">Aucune question.</td></tr>
                            <?php else : ?>
                                <?php foreach ($questions as $question) : ?>
                                    <?php $row_locked = $this->repository->question_has_activity((int) $question->id); ?>
                                    <tr>
                                        <td><?php echo esc_html($question->id); ?></td>
                                        <td><?php echo esc_html($question->group_name); ?></td>
                                        <td><?php echo esc_html($question->internal_label ?: ('Q' . $question->id)); ?></td>
                                        <td><?php echo esc_html(strtoupper($question->question_type ?: 'qcu')); ?></td>
                                        <td><?php echo esc_html(wp_trim_words(wp_strip_all_tags($question->question_text), 14)); ?></td>
                                        <td><?php echo esc_html((int) $question->choices_count); ?></td>
                                        <td><?php echo esc_html($question->status); ?></td>
                                        <td><?php echo $row_locked ? 'Oui' : 'Non'; ?></td>
                                        <td>
                                            <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'inskill-recall-questions', 'edit_question' => $question->id], admin_url('admin.php'))); ?>">Éditer</a>

                                            <?php if (!$row_locked) : ?>
                                                <form method="post" style="display:inline;" onsubmit="return confirm('Supprimer cette question ?');">
                                                    <?php wp_nonce_field('inskill_recall_admin_action'); ?>
                                                    <input type="hidden" name="inskill_recall_action" value="delete_question">
                                                    <input type="hidden" name="question_id" value="<?php echo esc_attr($question->id); ?>">
                                                    <button type="submit" class="button button-small">Supprimer</button>
                                                </form>
                                            <?php else : ?>
                                                <?php if (($question->status ?? '') !== 'inactive') : ?>
                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Désactiver cette question ?');">
                                                        <?php wp_nonce_field('inskill_recall_admin_action'); ?>
                                                        <input type="hidden" name="inskill_recall_action" value="deactivate_question">
                                                        <input type="hidden" name="question_id" value="<?php echo esc_attr($question->id); ?>">
                                                        <button type="submit" class="button button-small">Désactiver</button>
                                                    </form>
                                                <?php else : ?>
                                                    <span style="color:#646970;">Déjà inactive</span>
                                                <?php endif; ?>
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
