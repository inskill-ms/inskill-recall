<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_Admin_Page_Dashboard {
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
            'dashboard_page_created' => 'Page frontend créée ou déjà disponible.',
            'dashboard_page_error' => 'Impossible de créer la page frontend.',
            'test_datetime_saved' => 'Date/heure simulée enregistrée. Le moteur utilise maintenant cette date de test.',
            'test_datetime_cleared' => 'Mode test désactivé. Le moteur utilise de nouveau l’heure réelle.',
            'test_datetime_error' => 'Impossible d’enregistrer la date/heure simulée. Vérifiez le format saisi.',
            'test_engine_ran' => 'Moteur exécuté manuellement avec succès pour la date/heure actuellement simulée.',
            'test_engine_error' => 'Erreur lors de l’exécution manuelle du moteur.',
        ];

        if (!isset($map[$message])) {
            return;
        }

        $class = 'notice notice-success is-dismissible';
        if (in_array($message, ['dashboard_page_error', 'test_datetime_error', 'test_engine_error'], true)) {
            $class = 'notice notice-error is-dismissible';
        }

        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($map[$message]) . '</p></div>';
    }

    public function render() {
        $counts = $this->repository->get_dashboard_counts();
        $page_id = InSkill_Recall_Frontend::get_dashboard_page_id();
        $page = InSkill_Recall_Frontend::get_dashboard_page();
        $page_url = InSkill_Recall_Frontend::get_dashboard_page_url();

        $forced_datetime = InSkill_Recall_Time::get_forced_datetime();
        $forced_datetime_input = InSkill_Recall_Time::get_datetime_local_input_value();
        $is_test_mode = InSkill_Recall_Time::is_test_mode_enabled();
        $now_label = InSkill_Recall_Time::now_label();
        ?>
        <div class="wrap">
            <h1>InSkill Recall — Tableau de bord</h1>
            <?php $this->render_notice(); ?>

            <?php if ($is_test_mode) : ?>
                <div class="notice notice-warning" style="margin-top:16px;">
                    <p>
                        <strong>Mode test temporel actif.</strong>
                        Le moteur simule actuellement la date/heure :
                        <code><?php echo esc_html($forced_datetime); ?></code>
                    </p>
                </div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;max-width:1000px;margin-top:20px;">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;">
                    <h2 style="margin-top:0;">Utilisateurs</h2>
                    <p style="font-size:28px;font-weight:700;margin:0;"><?php echo esc_html($counts['users']); ?></p>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;">
                    <h2 style="margin-top:0;">Groupes</h2>
                    <p style="font-size:28px;font-weight:700;margin:0;"><?php echo esc_html($counts['groups']); ?></p>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;">
                    <h2 style="margin-top:0;">Questions</h2>
                    <p style="font-size:28px;font-weight:700;margin:0;"><?php echo esc_html($counts['questions']); ?></p>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;">
                    <h2 style="margin-top:0;">Progressions actives</h2>
                    <p style="font-size:28px;font-weight:700;margin:0;"><?php echo esc_html($counts['active_progress']); ?></p>
                </div>
            </div>

            <div style="margin-top:24px;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;max-width:1000px;">
                <h2 style="margin-top:0;">Mode test temporel</h2>

                <p style="margin-top:0;color:#50575e;">
                    Utilise une date/heure simulée pour tester le moteur sans attendre le lendemain.
                </p>

                <div style="margin:12px 0 18px 0;padding:12px 14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;">
                    <p style="margin:0 0 6px 0;"><strong>Date/heure actuellement utilisée par le moteur :</strong></p>
                    <p style="margin:0;"><code><?php echo esc_html($now_label); ?></code></p>
                </div>

                <form method="post" style="margin-bottom:12px;">
                    <?php wp_nonce_field('inskill_recall_admin_action'); ?>
                    <input type="hidden" name="inskill_recall_action" value="save_test_datetime">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th><label for="test_datetime">Date/heure simulée</label></th>
                            <td>
                                <input
                                    type="datetime-local"
                                    id="test_datetime"
                                    name="test_datetime"
                                    value="<?php echo esc_attr($forced_datetime_input); ?>"
                                    style="min-width:260px;"
                                >
                                <p class="description">
                                    Exemple d’usage : passer à J+1, J+3 ou J+10 pour tester les rappels, injections et rétrogradations.
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p>
                        <button type="submit" class="button button-primary">
                            <?php echo $is_test_mode ? 'Mettre à jour la date simulée' : 'Activer la date simulée'; ?>
                        </button>
                    </p>
                </form>

                <form method="post" style="display:inline-block;margin-right:10px;">
                    <?php wp_nonce_field('inskill_recall_admin_action'); ?>
                    <input type="hidden" name="inskill_recall_action" value="run_test_engine_now">
                    <button type="submit" class="button button-secondary">
                        Exécuter le moteur maintenant
                    </button>
                </form>

                <form method="post" style="display:inline-block;">
                    <?php wp_nonce_field('inskill_recall_admin_action'); ?>
                    <input type="hidden" name="inskill_recall_action" value="clear_test_datetime">
                    <button type="submit" class="button" <?php disabled(!$is_test_mode); ?>>
                        Revenir à l’heure réelle
                    </button>
                </form>

                <p class="description" style="margin-top:14px;">
                    Le bouton <strong>Exécuter le moteur maintenant</strong> est utile en test pour forcer immédiatement le recalcul du moteur à la date simulée.
                </p>
            </div>

            <div style="margin-top:24px;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;max-width:1000px;">
                <h2 style="margin-top:0;">Page frontend</h2>

                <?php if ($page && $page_id > 0) : ?>
                    <p><strong>ID de page :</strong> <?php echo esc_html($page_id); ?></p>
                    <p><strong>Titre :</strong> <?php echo esc_html($page->post_title); ?></p>
                    <p>
                        <strong>URL :</strong>
                        <?php if ($page_url) : ?>
                            <a href="<?php echo esc_url($page_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($page_url); ?></a>
                        <?php else : ?>
                            <em>URL indisponible</em>
                        <?php endif; ?>
                    </p>
                <?php else : ?>
                    <p>Aucune page frontend détectée.</p>
                <?php endif; ?>

                <form method="post" style="margin-top:16px;">
                    <?php wp_nonce_field('inskill_recall_admin_action'); ?>
                    <input type="hidden" name="inskill_recall_action" value="create_dashboard_page">
                    <button type="submit" class="button button-primary">Créer / vérifier la page frontend</button>
                </form>
            </div>
        </div>
        <?php
    }
}