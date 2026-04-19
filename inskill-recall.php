<?php
/**
 * Plugin Name: InSkill Recall
 * Description: Plugin d'ancrage mémoriel v2 avec accès sécurisé par lien personnel et notifications web push.
 * Version: 0.6.4
 * Author: OpenAI
 * Text Domain: inskill-recall
 */

if (!defined('ABSPATH')) {
    exit;
}

define('INSKILL_RECALL_VERSION', '0.6.4');
define('INSKILL_RECALL_PLUGIN_FILE', __FILE__);
define('INSKILL_RECALL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('INSKILL_RECALL_PLUGIN_URL', plugin_dir_url(__FILE__));

$autoload = INSKILL_RECALL_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/class-inskill-recall-db.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/class-inskill-recall-auth.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/class-inskill-recall-push.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/class-inskill-recall-time.php';

require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/class-inskill-recall-v2-progress-service.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/class-inskill-recall-v2-occurrence-service.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/class-inskill-recall-v2-scoring-service.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/class-inskill-recall-v2-status-service.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/class-inskill-recall-v2-engine.php';

require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/cron/class-inskill-recall-v2-cron-base.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/cron/class-inskill-recall-v2-cron-decisions.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/cron/class-inskill-recall-v2-cron-runner.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/cron/class-inskill-recall-v2-cron-notifications.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/class-inskill-recall-v2-cron.php';

require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/admin/class-inskill-recall-admin-repository.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/admin/class-inskill-recall-admin-actions.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/admin/class-inskill-recall-admin-page-dashboard.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/admin/class-inskill-recall-admin-page-users.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/admin/class-inskill-recall-admin-page-groups.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/admin/class-inskill-recall-admin-page-questions.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/admin/class-inskill-recall-admin-page-notifications.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/admin/class-inskill-recall-admin-page-stats.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/class-inskill-recall-admin.php';

require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/frontend/class-inskill-recall-frontend-core.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/frontend/class-inskill-recall-frontend-dashboard.php';
require_once INSKILL_RECALL_PLUGIN_DIR . 'includes/class-inskill-recall-frontend.php';

register_activation_hook(__FILE__, function () {
    InSkill_Recall_DB::activate();
    InSkill_Recall_Frontend::ensure_dashboard_page_exists();
    InSkill_Recall_V2_Cron::activate();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    InSkill_Recall_V2_Cron::deactivate();
    flush_rewrite_rules();
});

final class InSkill_Recall {
    private static $instance = null;

    private $cron = null;
    private $frontend = null;
    private $admin = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'init']);
    }

    public function init() {
        InSkill_Recall_DB::maybe_upgrade();

        if (is_admin() && !InSkill_Recall_Frontend::get_dashboard_page()) {
            InSkill_Recall_Frontend::ensure_dashboard_page_exists();
        }

        $this->frontend = new InSkill_Recall_Frontend();
        $this->cron = new InSkill_Recall_V2_Cron();

        if (is_admin()) {
            $this->admin = new InSkill_Recall_Admin();
        }
    }
}

InSkill_Recall::instance();