<?php
if (!defined('ABSPATH')) {
    exit;
}

class InSkill_Recall_Admin_Page_Debug_Log {
    private $repository;

    public function __construct(InSkill_Recall_Admin_Repository $repository) {
        $this->repository = $repository;
    }

    private function get_log_base_path() {
        $upload_dir = wp_upload_dir();
        $base_dir = !empty($upload_dir['basedir']) ? $upload_dir['basedir'] : WP_CONTENT_DIR . '/uploads';

        return trailingslashit($base_dir) . 'inskill-recall-debug.log';
    }

    private function get_available_log_files() {
        $base_path = $this->get_log_base_path();

        $files = [];
        $candidates = [
            'active' => $base_path,
            '1'      => $base_path . '.1',
            '2'      => $base_path . '.2',
            '3'      => $base_path . '.3',
        ];

        foreach ($candidates as $key => $path) {
            if (!file_exists($path) || !is_readable($path)) {
                continue;
            }

            $files[$key] = [
                'key'      => $key,
                'path'     => $path,
                'label'    => $key === 'active' ? 'inskill-recall-debug.log' : 'inskill-recall-debug.log.' . $key,
                'size'     => filesize($path),
                'modified' => filemtime($path),
            ];
        }

        return $files;
    }

    private function format_bytes($bytes) {
        $bytes = (int) $bytes;

        if ($bytes >= 1048576) {
            return number_format_i18n($bytes / 1048576, 2) . ' Mo';
        }

        if ($bytes >= 1024) {
            return number_format_i18n($bytes / 1024, 1) . ' Ko';
        }

        return number_format_i18n($bytes) . ' o';
    }

    private function read_last_lines($path, $max_lines = 200) {
        $max_lines = max(10, min(1000, (int) $max_lines));

        if (!file_exists($path) || !is_readable($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }

        return array_slice($lines, -$max_lines);
    }

    private function decode_log_line($line) {
        $decoded = json_decode((string) $line, true);

        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function get_selected_file_key(array $files) {
        $selected = isset($_GET['log_file']) ? sanitize_text_field(wp_unslash($_GET['log_file'])) : 'active';

        if (!isset($files[$selected])) {
            return isset($files['active']) ? 'active' : key($files);
        }

        return $selected;
    }

    private function get_line_limit() {
        $limit = isset($_GET['lines']) ? (int) $_GET['lines'] : 200;

        if ($limit < 10) {
            return 10;
        }

        if ($limit > 1000) {
            return 1000;
        }

        return $limit;
    }

    private function get_filter_value($key) {
        if (!isset($_GET[$key])) {
            return '';
        }

        return sanitize_text_field(wp_unslash($_GET[$key]));
    }

    private function get_group_by_run_enabled() {
        if (!isset($_GET['group_by_run'])) {
            return true;
        }

        return (int) $_GET['group_by_run'] === 1;
    }

    private function get_payload_value_recursive($value, $target_key) {
        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $key => $item) {
            if ((string) $key === (string) $target_key) {
                return $item;
            }

            if (is_array($item)) {
                $found = $this->get_payload_value_recursive($item, $target_key);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function collect_payload_values_recursive($value, $target_key, array &$values) {
        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            if ((string) $key === (string) $target_key && $item !== null && $item !== '') {
                $values[] = (string) $item;
            }

            if (is_array($item)) {
                $this->collect_payload_values_recursive($item, $target_key, $values);
            }
        }
    }

    private function get_run_id_from_decoded(array $decoded) {
        $payload = isset($decoded['payload']) && is_array($decoded['payload']) ? $decoded['payload'] : [];
        $ts = $this->get_payload_value_recursive($payload, 'ts');

        if ($ts === null || $ts === '') {
            return '';
        }

        return (string) $ts;
    }

    private function get_latest_run_id_from_lines(array $lines) {
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $decoded = $this->decode_log_line($lines[$i]);
            if (!$decoded) {
                continue;
            }

            $run_id = $this->get_run_id_from_decoded($decoded);
            if ($run_id !== '') {
                return $run_id;
            }
        }

        return '';
    }

    private function decoded_line_matches_filters(array $decoded, array $filters) {
        if ($filters['channel'] !== '') {
            $channel = isset($decoded['channel']) ? (string) $decoded['channel'] : '';

            if (stripos($channel, $filters['channel']) === false) {
                return false;
            }
        }

        if ($filters['run_id'] !== '') {
            $run_id = $this->get_run_id_from_decoded($decoded);

            if ((string) $run_id !== (string) $filters['run_id']) {
                return false;
            }
        }

        if ($filters['user_id'] !== '') {
            $payload = isset($decoded['payload']) && is_array($decoded['payload']) ? $decoded['payload'] : [];
            $value = $this->get_payload_value_recursive($payload, 'user_id');

            if ((string) $value !== (string) $filters['user_id']) {
                return false;
            }
        }

        if ($filters['group_id'] !== '') {
            $payload = isset($decoded['payload']) && is_array($decoded['payload']) ? $decoded['payload'] : [];
            $value = $this->get_payload_value_recursive($payload, 'group_id');

            if ((string) $value !== (string) $filters['group_id']) {
                return false;
            }
        }

        if ($filters['search'] !== '') {
            $haystack = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!$haystack || stripos($haystack, $filters['search']) === false) {
                return false;
            }
        }

        return true;
    }

    private function filter_lines(array $lines, array $filters) {
        if (
            $filters['channel'] === ''
            && $filters['run_id'] === ''
            && $filters['user_id'] === ''
            && $filters['group_id'] === ''
            && $filters['search'] === ''
        ) {
            return $lines;
        }

        $filtered = [];

        foreach ($lines as $line) {
            $decoded = $this->decode_log_line($line);

            if (!$decoded) {
                if ($filters['search'] !== '' && stripos((string) $line, $filters['search']) !== false) {
                    $filtered[] = $line;
                }

                continue;
            }

            if ($this->decoded_line_matches_filters($decoded, $filters)) {
                $filtered[] = $line;
            }
        }

        return $filtered;
    }

    private function group_lines_by_run(array $lines) {
        $groups = [];
        $sequence = 0;

        foreach ($lines as $line) {
            $decoded = $this->decode_log_line($line);
            $run_id = $decoded ? $this->get_run_id_from_decoded($decoded) : '';

            if ($run_id === '') {
                $sequence++;
                $key = 'no_run_' . $sequence;
                $label = 'Lignes sans run ID';
            } else {
                $key = 'run_' . $run_id;
                $label = 'Run ' . $run_id;
            }

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key'        => $key,
                    'run_id'     => $run_id,
                    'label'      => $label,
                    'first_time' => $decoded && isset($decoded['time']) ? (string) $decoded['time'] : '',
                    'last_time'  => $decoded && isset($decoded['time']) ? (string) $decoded['time'] : '',
                    'lines'      => [],
                ];
            }

            if ($decoded && isset($decoded['time'])) {
                $groups[$key]['last_time'] = (string) $decoded['time'];
            }

            $groups[$key]['lines'][] = $line;
        }

        return array_values($groups);
    }

    private function get_channel_badge_style($channel) {
        $channel = (string) $channel;

        if (stripos($channel, 'error') !== false || stripos($channel, 'exception') !== false || stripos($channel, 'forbidden') !== false) {
            return 'background:#fef2f2;color:#991b1b;border:1px solid #fecaca;';
        }

        if (stripos($channel, 'skipped') !== false || stripos($channel, 'ignored') !== false || stripos($channel, 'mismatch') !== false) {
            return 'background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;';
        }

        if (stripos($channel, 'sent') !== false || stripos($channel, 'success') !== false || stripos($channel, 'executed') !== false || stripos($channel, 'end') !== false) {
            return 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;';
        }

        if (stripos($channel, 'start') !== false || stripos($channel, 'trigger') !== false || stripos($channel, 'check') !== false) {
            return 'background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;';
        }

        return 'background:#f6f7f7;color:#2c3338;border:1px solid #dcdcde;';
    }

    private function get_group_header_style($run_id) {
        if ($run_id === '') {
            return 'background:#f6f7f7;border:1px solid #dcdcde;';
        }

        return 'background:#f0f6fc;border:1px solid #b6d4fe;';
    }

    private function get_current_url_without_filters() {
        return add_query_arg(
            ['page' => 'inskill-recall-debug-log'],
            admin_url('admin.php')
        );
    }

    private function get_latest_run_url($selected_key, $limit, $latest_run_id) {
        if ($latest_run_id === '') {
            return '';
        }

        return add_query_arg(
            [
                'page'         => 'inskill-recall-debug-log',
                'log_file'     => $selected_key,
                'lines'        => $limit,
                'run_id'       => $latest_run_id,
                'group_by_run' => 1,
            ],
            admin_url('admin.php')
        );
    }

    private function increment_counter(array &$counter, $value) {
        $value = (string) $value;

        if ($value === '') {
            return;
        }

        if (!isset($counter[$value])) {
            $counter[$value] = 0;
        }

        $counter[$value]++;
    }

    private function analyze_run_lines(array $lines) {
        $analysis = [
            'lines_count'            => count($lines),
            'errors_count'           => 0,
            'skipped_count'          => 0,
            'sent_count'             => 0,
            'start_count'            => 0,
            'end_count'              => 0,
            'users'                  => [],
            'groups'                 => [],
            'reasons'                => [],
            'channels'               => [],
            'notification_channels'  => [],
            'pending_seen'           => false,
            'has_pending_today'      => false,
            'status'                 => 'ok',
            'status_label'           => 'OK',
            'status_style'           => 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;',
        ];

        foreach ($lines as $line) {
            $decoded = $this->decode_log_line($line);
            if (!$decoded) {
                continue;
            }

            $channel = isset($decoded['channel']) ? (string) $decoded['channel'] : '';
            $payload = isset($decoded['payload']) && is_array($decoded['payload']) ? $decoded['payload'] : [];

            $this->increment_counter($analysis['channels'], $channel);

            if (
                stripos($channel, 'error') !== false
                || stripos($channel, 'exception') !== false
                || stripos($channel, 'forbidden') !== false
            ) {
                $analysis['errors_count']++;
            }

            if (
                stripos($channel, 'skipped') !== false
                || stripos($channel, 'ignored') !== false
                || stripos($channel, 'mismatch') !== false
            ) {
                $analysis['skipped_count']++;
            }

            if (stripos($channel, 'start') !== false || $channel === 'cron_trigger') {
                $analysis['start_count']++;
            }

            if (stripos($channel, 'end') !== false) {
                $analysis['end_count']++;
            }

            if (
                stripos($channel, 'sent') !== false
                || stripos($channel, 'send_success') !== false
                || stripos($channel, 'notification_sent') !== false
            ) {
                $analysis['sent_count']++;
                $this->increment_counter($analysis['notification_channels'], $channel);
            }

            $user_ids = [];
            $this->collect_payload_values_recursive($payload, 'user_id', $user_ids);
            foreach ($user_ids as $user_id) {
                $analysis['users'][$user_id] = true;
            }

            $group_ids = [];
            $this->collect_payload_values_recursive($payload, 'group_id', $group_ids);
            foreach ($group_ids as $group_id) {
                $analysis['groups'][$group_id] = true;
            }

            $reasons = [];
            $this->collect_payload_values_recursive($payload, 'reason', $reasons);
            foreach ($reasons as $reason) {
                $this->increment_counter($analysis['reasons'], $reason);
            }

            $pending_values = [];
            $this->collect_payload_values_recursive($payload, 'has_pending_today', $pending_values);
            foreach ($pending_values as $pending_value) {
                $analysis['pending_seen'] = true;

                if ((int) $pending_value === 1) {
                    $analysis['has_pending_today'] = true;
                }
            }
        }

        ksort($analysis['channels']);
        arsort($analysis['reasons']);
        ksort($analysis['notification_channels']);

        $analysis['users'] = array_keys($analysis['users']);
        $analysis['groups'] = array_keys($analysis['groups']);

        sort($analysis['users'], SORT_NATURAL);
        sort($analysis['groups'], SORT_NATURAL);

        if ($analysis['errors_count'] > 0) {
            $analysis['status'] = 'error';
            $analysis['status_label'] = 'Erreur détectée';
            $analysis['status_style'] = 'background:#fef2f2;color:#991b1b;border:1px solid #fecaca;';
        } elseif ($analysis['sent_count'] > 0) {
            $analysis['status'] = 'sent';
            $analysis['status_label'] = 'Notification envoyée';
            $analysis['status_style'] = 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;';
        } elseif ($analysis['pending_seen'] && $analysis['has_pending_today']) {
            $analysis['status'] = 'warning';
            $analysis['status_label'] = 'Warning — pending sans envoi';
            $analysis['status_style'] = 'background:#fffbeb;color:#92400e;border:1px solid #fde68a;';
        } elseif ($analysis['pending_seen'] && !$analysis['has_pending_today']) {
            $analysis['status'] = 'ok_empty';
            $analysis['status_label'] = 'OK — rien à envoyer';
            $analysis['status_style'] = 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;';
        } elseif ($analysis['skipped_count'] > 0) {
            $analysis['status'] = 'skipped';
            $analysis['status_label'] = 'Aucun envoi / skips';
            $analysis['status_style'] = 'background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;';
        }

        return $analysis;
    }

    private function render_analysis_chip($label, $value, $style = '') {
        if ($style === '') {
            $style = 'background:#f6f7f7;color:#2c3338;border:1px solid #dcdcde;';
        }
        ?>
        <span style="display:inline-flex;gap:6px;align-items:center;padding:5px 9px;border-radius:999px;font-weight:600;margin:0 6px 6px 0;<?php echo esc_attr($style); ?>">
            <span><?php echo esc_html($label); ?></span>
            <strong><?php echo esc_html((string) $value); ?></strong>
        </span>
        <?php
    }

    private function render_counter_list(array $items, $empty_label = '—') {
        if (empty($items)) {
            echo esc_html($empty_label);
            return;
        }

        $parts = [];
        foreach ($items as $key => $count) {
            $parts[] = $key . ' (' . $count . ')';
        }

        echo esc_html(implode(', ', $parts));
    }

    private function render_run_analysis(array $group) {
        $analysis = $this->analyze_run_lines($group['lines']);
        ?>
        <div style="padding:12px 14px;border-left:1px solid #dcdcde;border-right:1px solid #dcdcde;background:#fbfcfd;">
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:0;margin-bottom:8px;">
                <?php $this->render_analysis_chip('Statut', $analysis['status_label'], $analysis['status_style']); ?>
                <?php $this->render_analysis_chip('Lignes', $analysis['lines_count']); ?>
                <?php $this->render_analysis_chip('Erreurs', $analysis['errors_count'], $analysis['errors_count'] > 0 ? 'background:#fef2f2;color:#991b1b;border:1px solid #fecaca;' : ''); ?>
                <?php $this->render_analysis_chip('Skips', $analysis['skipped_count'], $analysis['skipped_count'] > 0 ? 'background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;' : ''); ?>
                <?php $this->render_analysis_chip('Envois', $analysis['sent_count'], $analysis['sent_count'] > 0 ? 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;' : ''); ?>
                <?php $this->render_analysis_chip('Users', count($analysis['users'])); ?>
                <?php $this->render_analysis_chip('Groupes', count($analysis['groups'])); ?>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;color:#50575e;">
                <div>
                    <strong>Users détectés :</strong>
                    <?php echo !empty($analysis['users']) ? esc_html(implode(', ', $analysis['users'])) : '—'; ?>
                </div>

                <div>
                    <strong>Groupes détectés :</strong>
                    <?php echo !empty($analysis['groups']) ? esc_html(implode(', ', $analysis['groups'])) : '—'; ?>
                </div>

                <div>
                    <strong>Raisons principales :</strong>
                    <?php $this->render_counter_list($analysis['reasons']); ?>
                </div>

                <div>
                    <strong>Notifications envoyées :</strong>
                    <?php $this->render_counter_list($analysis['notification_channels']); ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_log_row($line) {
        $decoded = $this->decode_log_line($line);

        if (!$decoded) {
            ?>
            <tr>
                <td colspan="7">
                    <code><?php echo esc_html($line); ?></code>
                </td>
            </tr>
            <?php
            return;
        }

        $channel = isset($decoded['channel']) ? (string) $decoded['channel'] : '';
        $payload = isset($decoded['payload']) ? $decoded['payload'] : [];
        $run_id = $this->get_run_id_from_decoded($decoded);
        ?>
        <tr>
            <td><?php echo esc_html($decoded['time'] ?? ''); ?></td>
            <td><?php echo esc_html($decoded['real_time'] ?? '—'); ?></td>
            <td><?php echo esc_html(array_key_exists('simulated_time', $decoded) && $decoded['simulated_time'] !== null ? $decoded['simulated_time'] : '—'); ?></td>
            <td><?php echo !empty($decoded['test_time_enabled']) ? 'Oui' : 'Non'; ?></td>
            <td><?php echo $run_id !== '' ? '<code>' . esc_html($run_id) . '</code>' : '—'; ?></td>
            <td>
                <span style="display:inline-block;padding:4px 8px;border-radius:999px;font-weight:600;<?php echo esc_attr($this->get_channel_badge_style($channel)); ?>">
                    <?php echo esc_html($channel); ?>
                </span>
            </td>
            <td>
                <pre style="white-space:pre-wrap;margin:0;max-width:900px;"><?php echo esc_html(wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)); ?></pre>
            </td>
        </tr>
        <?php
    }

    private function render_log_table(array $lines) {
        ?>
        <table class="widefat striped" style="margin:0;">
            <thead>
                <tr>
                    <th style="width:160px;">Time</th>
                    <th style="width:160px;">Real time</th>
                    <th style="width:160px;">Simulated time</th>
                    <th style="width:80px;">Test</th>
                    <th style="width:140px;">Run ID</th>
                    <th style="width:260px;">Channel</th>
                    <th>Payload</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $line) : ?>
                    <?php $this->render_log_row($line); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_grouped_log_tables(array $lines) {
        $groups = $this->group_lines_by_run($lines);
        ?>

        <?php foreach ($groups as $group) : ?>
            <details open style="margin-bottom:14px;border-radius:10px;overflow:hidden;">
                <summary style="cursor:pointer;padding:10px 12px;font-weight:700;<?php echo esc_attr($this->get_group_header_style($group['run_id'])); ?>">
                    <?php echo esc_html($group['label']); ?>
                    —
                    <?php echo esc_html((string) count($group['lines'])); ?> ligne(s)
                    <?php if ($group['first_time'] !== '') : ?>
                        —
                        <?php echo esc_html($group['first_time']); ?>
                    <?php endif; ?>
                    <?php if ($group['last_time'] !== '' && $group['last_time'] !== $group['first_time']) : ?>
                        → <?php echo esc_html($group['last_time']); ?>
                    <?php endif; ?>
                </summary>

                <?php $this->render_run_analysis($group); ?>

                <div style="overflow:auto;border-left:1px solid #dcdcde;border-right:1px solid #dcdcde;border-bottom:1px solid #dcdcde;">
                    <?php $this->render_log_table($group['lines']); ?>
                </div>
            </details>
        <?php endforeach; ?>

        <?php
    }

    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès refusé.', 'inskill-recall'));
        }

        $files = $this->get_available_log_files();
        $selected_key = !empty($files) ? $this->get_selected_file_key($files) : '';
        $limit = $this->get_line_limit();
        $group_by_run = $this->get_group_by_run_enabled();

        $filters = [
            'channel'  => $this->get_filter_value('channel'),
            'run_id'   => $this->get_filter_value('run_id'),
            'user_id'  => $this->get_filter_value('user_id'),
            'group_id' => $this->get_filter_value('group_id'),
            'search'   => $this->get_filter_value('search'),
        ];

        $selected_file = $selected_key !== '' && isset($files[$selected_key]) ? $files[$selected_key] : null;
        $raw_lines = $selected_file ? $this->read_last_lines($selected_file['path'], $limit) : [];
        $latest_run_id = $this->get_latest_run_id_from_lines($raw_lines);
        $latest_run_url = $this->get_latest_run_url($selected_key, $limit, $latest_run_id);
        $lines = $this->filter_lines($raw_lines, $filters);
        ?>
        <div class="wrap">
            <h1>InSkill Recall — Debug log</h1>

            <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;margin-bottom:24px;">
                <h2 style="margin-top:0;">Lecture du fichier debug</h2>
                <p style="color:#646970;margin-top:0;">
                    Cette page permet de consulter les dernières lignes de <code>inskill-recall-debug.log</code> et de ses fichiers archivés.
                    Les filtres sont appliqués uniquement à l’affichage.
                </p>

                <form method="get" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;align-items:end;margin:16px 0;">
                    <input type="hidden" name="page" value="inskill-recall-debug-log">

                    <div>
                        <label for="log_file"><strong>Fichier</strong></label><br>
                        <select name="log_file" id="log_file" style="width:100%;">
                            <?php if (empty($files)) : ?>
                                <option value="">Aucun fichier disponible</option>
                            <?php else : ?>
                                <?php foreach ($files as $file) : ?>
                                    <option value="<?php echo esc_attr($file['key']); ?>" <?php selected($selected_key, $file['key']); ?>>
                                        <?php
                                        echo esc_html(
                                            $file['label']
                                            . ' — '
                                            . $this->format_bytes($file['size'])
                                            . ' — '
                                            . date_i18n('Y-m-d H:i:s', (int) $file['modified'])
                                        );
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div>
                        <label for="lines"><strong>Lignes lues</strong></label><br>
                        <input type="number" min="10" max="1000" step="10" name="lines" id="lines" value="<?php echo esc_attr($limit); ?>" style="width:100%;">
                    </div>

                    <div>
                        <label for="channel"><strong>Channel contient</strong></label><br>
                        <input type="text" name="channel" id="channel" value="<?php echo esc_attr($filters['channel']); ?>" placeholder="ex: cron_daily" style="width:100%;">
                    </div>

                    <div>
                        <label for="run_id"><strong>Run ID</strong></label><br>
                        <input type="text" name="run_id" id="run_id" value="<?php echo esc_attr($filters['run_id']); ?>" placeholder="ex: 1777143799" style="width:100%;">
                    </div>

                    <div>
                        <label for="user_id"><strong>User ID</strong></label><br>
                        <input type="text" name="user_id" id="user_id" value="<?php echo esc_attr($filters['user_id']); ?>" placeholder="ex: 1" style="width:100%;">
                    </div>

                    <div>
                        <label for="group_id"><strong>Group ID</strong></label><br>
                        <input type="text" name="group_id" id="group_id" value="<?php echo esc_attr($filters['group_id']); ?>" placeholder="ex: 3" style="width:100%;">
                    </div>

                    <div>
                        <label for="search"><strong>Recherche globale</strong></label><br>
                        <input type="text" name="search" id="search" value="<?php echo esc_attr($filters['search']); ?>" placeholder="ex: weekend_blocked" style="width:100%;">
                    </div>

                    <div>
                        <label for="group_by_run"><strong>Affichage</strong></label><br>
                        <select name="group_by_run" id="group_by_run" style="width:100%;">
                            <option value="1" <?php selected($group_by_run, true); ?>>Regrouper par run</option>
                            <option value="0" <?php selected($group_by_run, false); ?>>Liste simple</option>
                        </select>
                    </div>

                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button type="submit" class="button button-primary">Filtrer</button>
                        <a class="button button-secondary" href="<?php echo esc_url($this->get_current_url_without_filters()); ?>">Réinitialiser</a>

                        <?php if ($latest_run_url !== '') : ?>
                            <a class="button button-secondary" href="<?php echo esc_url($latest_run_url); ?>">
                                Afficher uniquement le dernier run
                            </a>
                        <?php else : ?>
                            <button type="button" class="button button-secondary" disabled>
                                Aucun run détecté
                            </button>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if ($selected_file) : ?>
                    <p>
                        <strong>Chemin :</strong>
                        <code><?php echo esc_html($selected_file['path']); ?></code>
                    </p>
                    <p>
                        <strong>Lignes lues :</strong> <?php echo esc_html((string) count($raw_lines)); ?>
                        —
                        <strong>Lignes affichées :</strong> <?php echo esc_html((string) count($lines)); ?>
                        —
                        <strong>Mode :</strong> <?php echo $group_by_run ? 'Regroupé par run' : 'Liste simple'; ?>
                        <?php if ($latest_run_id !== '') : ?>
                            —
                            <strong>Dernier run détecté :</strong> <code><?php echo esc_html($latest_run_id); ?></code>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>

            <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;margin-bottom:24px;">
                <h2 style="margin-top:0;">Résultats</h2>

                <?php if (!$selected_file) : ?>
                    <p>Aucun fichier log lisible.</p>
                <?php elseif (empty($raw_lines)) : ?>
                    <p>Le fichier sélectionné est vide ou illisible.</p>
                <?php elseif (empty($lines)) : ?>
                    <p>Aucune ligne ne correspond aux filtres actuels.</p>
                <?php else : ?>
                    <div style="max-height:780px;overflow:auto;">
                        <?php
                        if ($group_by_run) {
                            $this->render_grouped_log_tables($lines);
                        } else {
                            echo '<div style="overflow:auto;border:1px solid #dcdcde;border-radius:8px;">';
                            $this->render_log_table($lines);
                            echo '</div>';
                        }
                        ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
