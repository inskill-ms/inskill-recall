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
        ];

        if (!isset($map[$message])) {
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($map[$message]) . '</p></div>';
    }

    public function render() {
        $counts = $this->repository->get_dashboard_counts();
        $page_id = InSkill_Recall_Frontend::get_dashboard_page_id();
        $page = InSkill_Recall_Frontend::get_dashboard_page();
        $page_url = InSkill_Recall_Frontend::get_dashboard_page_url();
        ?>
        <div class="wrap">
            <h1>InSkill Recall — Tableau de bord</h1>
            <?php $this->render_notice(); ?>

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
                <h2 style="margin-top:0;">Page frontend</h2>

                <?php if ($page) : ?>
                    <p><strong>Page configurée :</strong> <?php echo esc_html(get_the_title($page)); ?> (#<?php echo esc_html($page->ID); ?>)</p>
                    <p><strong>URL :</strong> <code><?php echo esc_html($page_url); ?></code></p>
                    <p>
                        <a class="button button-primary" href="<?php echo esc_url($page_url); ?>" target="_blank" rel="noopener">Ouvrir la page frontend</a>
                        <a class="button" href="<?php echo esc_url(get_edit_post_link($page->ID)); ?>">Éditer la page</a>
                    </p>
                    <p class="description">Le lien personnel d’un utilisateur correspond à cette URL avec le paramètre <code>?token=...</code>.</p>
                <?php else : ?>
                    <p>Aucune page frontend n’est configurée pour le shortcode <code>[inskill_recall_dashboard]</code>.</p>
                    <form method="post">
                        <?php wp_nonce_field('inskill_recall_admin_action'); ?>
                        <input type="hidden" name="inskill_recall_action" value="create_dashboard_page">
                        <button type="submit" class="button button-primary">Créer automatiquement la page frontend</button>
                    </form>
                <?php endif; ?>
            </div>

            <div style="margin-top:24px;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;max-width:1000px;">
                <h2 style="margin-top:0;">Raccourcis</h2>
                <p>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=inskill-recall-users')); ?>">Gérer les utilisateurs</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=inskill-recall-groups')); ?>">Gérer les groupes</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=inskill-recall-questions')); ?>">Gérer les questions</a>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=inskill-recall-stats')); ?>">Voir les statistiques</a>
                </p>
            </div>
        </div>
        <?php
    }
}