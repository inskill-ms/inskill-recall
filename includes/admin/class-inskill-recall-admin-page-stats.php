<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_Admin_Page_Stats {
    private $repository;

    public function __construct(InSkill_Recall_Admin_Repository $repository) {
        $this->repository = $repository;
    }

    public function render() {
        $stats = $this->repository->get_group_stats();
        $progress = $this->repository->get_progress_overview(200);
        ?>
        <div class="wrap">
            <h1>InSkill Recall — Statistiques</h1>

            <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;margin-bottom:24px;">
                <h2 style="margin-top:0;">Statistiques par utilisateur / groupe</h2>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Groupe</th>
                            <th>Utilisateur</th>
                            <th>Statut</th>
                            <th>Score</th>
                            <th>Rang</th>
                            <th>Maîtrisées</th>
                            <th>Total</th>
                            <th>Réponses</th>
                            <th>Bonnes</th>
                            <th>Erreurs</th>
                            <th>Non répondues</th>
                            <th>Dernière réponse</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stats)) : ?>
                            <tr><td colspan="12">Aucune statistique.</td></tr>
                        <?php else : ?>
                            <?php foreach ($stats as $row) : ?>
                                <tr>
                                    <td><?php echo esc_html($row->group_name); ?></td>
                                    <td><?php echo esc_html(trim($row->first_name . ' ' . $row->last_name) ?: $row->email); ?></td>
                                    <td><?php echo esc_html($row->participant_status); ?></td>
                                    <td><?php echo esc_html((int) $row->score_total); ?></td>
                                    <td><?php echo esc_html($row->cached_rank ?: '—'); ?></td>
                                    <td><?php echo esc_html((int) $row->mastered_questions); ?></td>
                                    <td><?php echo esc_html((int) $row->total_questions); ?></td>
                                    <td><?php echo esc_html((int) $row->answers_total); ?></td>
                                    <td><?php echo esc_html((int) $row->correct_total); ?></td>
                                    <td><?php echo esc_html((int) $row->incorrect_total); ?></td>
                                    <td><?php echo esc_html((int) $row->unanswered_total); ?></td>
                                    <td><?php echo esc_html($row->last_answer_at ?: '—'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;">
                <h2 style="margin-top:0;">Dernières progressions</h2>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Mis à jour</th>
                            <th>Groupe</th>
                            <th>Utilisateur</th>
                            <th>Question</th>
                            <th>Niveau</th>
                            <th>État</th>
                            <th>Prochaine échéance</th>
                            <th>Dernier résultat</th>
                            <th>Bonnes</th>
                            <th>Erreurs</th>
                            <th>Non répondues</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($progress)) : ?>
                            <tr><td colspan="11">Aucune progression.</td></tr>
                        <?php else : ?>
                            <?php foreach ($progress as $row) : ?>
                                <tr>
                                    <td><?php echo esc_html($row->updated_at); ?></td>
                                    <td><?php echo esc_html($row->group_name); ?></td>
                                    <td><?php echo esc_html(trim($row->first_name . ' ' . $row->last_name) ?: $row->email); ?></td>
                                    <td>
                                        <?php
                                        $label = $row->internal_label ? $row->internal_label : ('Q' . $row->question_id);
                                        echo esc_html($label . ' — ' . wp_trim_words(wp_strip_all_tags($row->question_text), 10));
                                        ?>
                                    </td>
                                    <td><?php echo esc_html($row->current_level); ?></td>
                                    <td><?php echo esc_html($row->current_state); ?></td>
                                    <td><?php echo esc_html($row->next_due_at ?: '—'); ?></td>
                                    <td><?php echo esc_html($row->last_result ?: '—'); ?></td>
                                    <td><?php echo esc_html((int) $row->total_correct_count); ?></td>
                                    <td><?php echo esc_html((int) $row->total_incorrect_count); ?></td>
                                    <td><?php echo esc_html((int) $row->total_unanswered_count); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
}