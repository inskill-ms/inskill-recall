<?php
if (!defined('ABSPATH')) {
    exit;
}

abstract class InSkill_Recall_V2_Cron_Notifications extends InSkill_Recall_V2_Cron_Runner {
    protected static function send_daily_notifications() {
        if (!class_exists('InSkill_Recall_Push') || !class_exists('InSkill_Recall_Auth')) {
            self::debug_log('cron_daily_notifications_skipped_missing_classes', [
                'has_push' => class_exists('InSkill_Recall_Push'),
                'has_auth' => class_exists('InSkill_Recall_Auth'),
            ]);
            return;
        }

        global $wpdb;

        $today = InSkill_Recall_V2_Progress_Service::today_date();
        $groups = InSkill_Recall_V2_Engine::get_active_groups();

        self::debug_log('cron_daily_notifications_start', [
            'today'        => $today,
            'groups_count' => is_array($groups) ? count($groups) : 0,
        ]);

        foreach ($groups as $group) {
            $members = InSkill_Recall_V2_Engine::get_group_members((int) $group->id);

            self::debug_log('cron_daily_notifications_group', [
                'group_id'      => (int) $group->id,
                'group_name'    => isset($group->name) ? (string) $group->name : '',
                'members_count' => is_array($members) ? count($members) : 0,
            ]);

            foreach ($members as $member) {
                $user = InSkill_Recall_Auth::get_user((int) $member->id);
                if (!$user) {
                    self::debug_log('cron_daily_notifications_member_skipped_missing_user', [
                        'group_id'  => (int) $group->id,
                        'member_id' => isset($member->id) ? (int) $member->id : 0,
                    ]);
                    continue;
                }

                $hasPendingToday = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM " . InSkill_Recall_DB::table('question_occurrences') . "
                     WHERE group_id = %d
                       AND recall_user_id = %d
                       AND scheduled_date = %s
                       AND status = 'pending'",
                    (int) $group->id,
                    (int) $member->id,
                    $today
                ));

                $decision = self::get_daily_notification_decision($user);

                self::debug_log('cron_daily_notifications_member_check', [
                    'group_id'          => (int) $group->id,
                    'group_name'        => isset($group->name) ? (string) $group->name : '',
                    'user_id'           => (int) $member->id,
                    'user_email'        => !empty($user->email) ? (string) $user->email : '',
                    'has_pending_today' => $hasPendingToday,
                    'decision'          => $decision,
                ]);

                if ($hasPendingToday <= 0) {
                    self::debug_log('cron_daily_notifications_member_skipped', [
                        'group_id' => (int) $group->id,
                        'user_id'  => (int) $member->id,
                        'reason'   => 'no_pending_today',
                    ]);
                    continue;
                }

                if (empty($decision['ok'])) {
                    self::debug_log('cron_daily_notifications_member_skipped', [
                        'group_id' => (int) $group->id,
                        'user_id'  => (int) $member->id,
                        'reason'   => isset($decision['reason']) ? (string) $decision['reason'] : 'unknown',
                        'decision' => $decision,
                    ]);
                    continue;
                }

                $payload = [
                    'title' => 'InSkill Recall',
                    'body'  => 'Vos questions du jour sont disponibles.',
                    'url'   => self::get_user_target_url($user),
                    'tag'   => 'inskill-recall-daily-' . (int) $group->id . '-' . (int) $member->id,
                ];

                $sent = InSkill_Recall_Push::send_to_user((int) $member->id, $payload);

                self::debug_log('cron_daily_notifications_member_send', [
                    'group_id' => (int) $group->id,
                    'user_id'  => (int) $member->id,
                    'sent'     => (bool) $sent,
                    'payload'  => $payload,
                ]);

                if ($sent) {
                    self::debug_log('cron_daily_notification_sent', [
                        'group_id'          => (int) $group->id,
                        'group_name'        => isset($group->name) ? (string) $group->name : '',
                        'user_id'           => (int) $member->id,
                        'user_email'        => !empty($user->email) ? (string) $user->email : '',
                        'notification_type' => 'daily_routine',
                        'payload'           => $payload,
                    ]);
                } else {
                    self::debug_log('cron_daily_notification_send_error', [
                        'group_id'          => (int) $group->id,
                        'group_name'        => isset($group->name) ? (string) $group->name : '',
                        'user_id'           => (int) $member->id,
                        'user_email'        => !empty($user->email) ? (string) $user->email : '',
                        'notification_type' => 'daily_routine',
                        'error_message'     => 'push_send_failed',
                        'payload'           => $payload,
                    ]);
                }

                self::log_notification(
                    (int) $member->id,
                    (int) $group->id,
                    'daily_routine',
                    $payload,
                    $sent ? 'sent' : 'error',
                    $sent ? null : 'push_send_failed'
                );
            }
        }

        self::debug_log('cron_daily_notifications_end', [
            'today' => $today,
        ]);
    }

    protected static function send_downgrade_alert_notifications() {
        if (!class_exists('InSkill_Recall_Push') || !class_exists('InSkill_Recall_Auth')) {
            self::debug_log('cron_downgrade_notifications_skipped_missing_classes', [
                'has_push' => class_exists('InSkill_Recall_Push'),
                'has_auth' => class_exists('InSkill_Recall_Auth'),
            ]);
            return;
        }

        global $wpdb;

        $today = InSkill_Recall_V2_Progress_Service::today_date();

        $rows = $wpdb->get_results(
            "SELECT DISTINCT group_id, recall_user_id, downgrade_on_date
             FROM " . InSkill_Recall_DB::table('user_question_progress') . "
             WHERE downgrade_on_date IS NOT NULL
               AND current_level NOT IN ('nv0', 'mastered')"
        );

        self::debug_log('cron_downgrade_notifications_start', [
            'today'      => $today,
            'rows_count' => is_array($rows) ? count($rows) : 0,
        ]);

        foreach ($rows as $row) {
            $user = InSkill_Recall_Auth::get_user((int) $row->recall_user_id);
            if (!$user) {
                self::debug_log('cron_downgrade_notifications_row_skipped_missing_user', [
                    'group_id' => (int) $row->group_id,
                    'user_id'  => (int) $row->recall_user_id,
                ]);
                continue;
            }

            $targetDate = self::get_next_program_alert_date_for_user($user, $today);

            if ((string) $row->downgrade_on_date !== (string) $targetDate) {
                self::debug_log('cron_downgrade_notifications_row_skipped', [
                    'group_id'          => (int) $row->group_id,
                    'user_id'           => (int) $row->recall_user_id,
                    'downgrade_on_date' => (string) $row->downgrade_on_date,
                    'target_date'       => (string) $targetDate,
                    'reason'            => 'target_date_mismatch',
                ]);
                continue;
            }

            $decision = self::get_downgrade_notification_decision($user, (int) $row->group_id);

            self::debug_log('cron_downgrade_notifications_row_check', [
                'group_id'          => (int) $row->group_id,
                'user_id'           => (int) $row->recall_user_id,
                'downgrade_on_date' => (string) $row->downgrade_on_date,
                'target_date'       => (string) $targetDate,
                'decision'          => $decision,
            ]);

            if (empty($decision['ok'])) {
                self::debug_log('cron_downgrade_notifications_row_skipped', [
                    'group_id' => (int) $row->group_id,
                    'user_id'  => (int) $row->recall_user_id,
                    'reason'   => isset($decision['reason']) ? (string) $decision['reason'] : 'unknown',
                    'decision' => $decision,
                ]);
                continue;
            }

            $payload = [
                'title' => 'InSkill Recall',
                'body'  => 'Certaines questions risquent de rétrograder demain. Pensez à les revoir.',
                'url'   => self::get_user_target_url($user),
                'tag'   => 'inskill-recall-downgrade-' . (int) $row->group_id . '-' . (int) $row->recall_user_id,
            ];

            $sent = InSkill_Recall_Push::send_to_user((int) $row->recall_user_id, $payload);

            self::debug_log('cron_downgrade_notifications_row_send', [
                'group_id' => (int) $row->group_id,
                'user_id'  => (int) $row->recall_user_id,
                'sent'     => (bool) $sent,
                'payload'  => $payload,
            ]);

            if ($sent) {
                self::debug_log('cron_downgrade_notification_sent', [
                    'group_id'          => (int) $row->group_id,
                    'user_id'           => (int) $row->recall_user_id,
                    'notification_type' => 'downgrade_alert',
                    'downgrade_on_date' => (string) $row->downgrade_on_date,
                    'target_date'       => (string) $targetDate,
                    'payload'           => $payload,
                ]);
            } else {
                self::debug_log('cron_downgrade_notification_send_error', [
                    'group_id'          => (int) $row->group_id,
                    'user_id'           => (int) $row->recall_user_id,
                    'notification_type' => 'downgrade_alert',
                    'downgrade_on_date' => (string) $row->downgrade_on_date,
                    'target_date'       => (string) $targetDate,
                    'error_message'     => 'push_send_failed',
                    'payload'           => $payload,
                ]);
            }

            self::log_notification(
                (int) $row->recall_user_id,
                (int) $row->group_id,
                'downgrade_alert',
                $payload,
                $sent ? 'sent' : 'error',
                $sent ? null : 'push_send_failed'
            );
        }

        self::debug_log('cron_downgrade_notifications_end', [
            'today' => $today,
        ]);
    }

    protected static function log_notification($recall_user_id, $group_id, $type, array $payload, $status = 'sent', $error_message = null) {
        global $wpdb;

        $now = InSkill_Recall_Time::now_mysql();

        $wpdb->insert(
            InSkill_Recall_DB::table('notification_logs'),
            [
                'recall_user_id'    => (int) $recall_user_id,
                'group_id'          => $group_id ? (int) $group_id : null,
                'notification_type' => sanitize_key($type),
                'title'             => isset($payload['title']) ? sanitize_text_field((string) $payload['title']) : '',
                'body'              => isset($payload['body']) ? sanitize_textarea_field((string) $payload['body']) : '',
                'payload_json'      => wp_json_encode($payload),
                'sent_at'           => $now,
                'status'            => $status === 'error' ? 'error' : 'sent',
                'error_message'     => $error_message ? sanitize_textarea_field((string) $error_message) : null,
                'created_at'        => $now,
            ]
        );
    }
}