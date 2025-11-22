<?php
if (!defined('ABSPATH')) { exit; }

class Wechat_Sync_Queue {

    public static function enqueue($post_id, $attempt) {
        update_post_meta($post_id, 'wechat_sync_attempt', intval($attempt));
        update_post_meta($post_id, 'wechat_sync_next_attempt', time());
        $secret = get_option('wechat_sync_cron_secret', '');
        if (!$secret) { $secret = wp_generate_password(32, false); update_option('wechat_sync_cron_secret', $secret, false); }
        $url = admin_url('admin-post.php?action=wechat_sync_cron&key=' . rawurlencode($secret));
        wp_remote_post($url, ['timeout' => 0.01, 'blocking' => false]);
    }

    public static function process($post_id, $attempt) {
        $token = Wechat_Sync_Token_Service::get_access_token();
        if (is_wp_error($token)) {
            update_post_meta($post_id, 'wechat_sync_status', '失败');
            update_post_meta($post_id, 'wechat_sync_error', $token->get_error_message());
            Wechat_Sync_Logger::log_error($post_id, $token->get_error_message(), 'token', $attempt, '失败');
            return;
        }
        $media_id = Wechat_Sync_Provider::create_draft($post_id, $token);
        if (is_wp_error($media_id)) {
            $code = $media_id->get_error_code();
            if ($code === 'wechat_sync_unauthorized' || $code === 'wechat_sync_ip_whitelist') {
                update_post_meta($post_id, 'wechat_sync_status', '失败');
                update_post_meta($post_id, 'wechat_sync_error', $media_id->get_error_message());
                set_transient('wechat_sync_last_error', get_the_title($post_id) . ' - ' . $media_id->get_error_message(), 600);
                Wechat_Sync_Logger::log_error($post_id, $media_id->get_error_message(), 'draft', $attempt, '失败');
                return;
            }
            Wechat_Sync_Logger::log_error($post_id, $media_id->get_error_message(), 'draft', $attempt, '失败');
            self::retry_or_fail($post_id, $attempt, $media_id->get_error_message());
            return;
        }
        update_post_meta($post_id, 'wechat_sync_draft_media_id', $media_id);
        $mode = get_option('wechat_sync_publish_mode', 'draft');
        if ($mode === 'publish') {
            update_post_meta($post_id, 'wechat_sync_status', '发布中');
            $publish_id = Wechat_Sync_Provider::publish($media_id, $token);
            if (is_wp_error($publish_id)) {
                $code = $publish_id->get_error_code();
                if ($code === 'wechat_sync_publish_unauthorized' || $code === 'wechat_sync_unauthorized' || $code === 'wechat_sync_ip_whitelist') {
                    update_post_meta($post_id, 'wechat_sync_status', '已创建草稿');
                    update_post_meta($post_id, 'wechat_sync_error', $publish_id->get_error_message());
                    set_transient('wechat_sync_last_error', get_the_title($post_id) . ' - ' . $publish_id->get_error_message(), 600);
                    Wechat_Sync_Logger::log_error($post_id, $publish_id->get_error_message(), 'publish', $attempt, '警告');
                    return;
                }
                Wechat_Sync_Logger::log_error($post_id, $publish_id->get_error_message(), 'publish', $attempt, '失败');
                self::retry_or_fail($post_id, $attempt, $publish_id->get_error_message());
                return;
            }
            update_post_meta($post_id, 'wechat_sync_publish_id', $publish_id);
            update_post_meta($post_id, 'wechat_sync_next_poll', time() + 30);
            update_post_meta($post_id, 'wechat_sync_poll_count', 0);
        } else {
            update_post_meta($post_id, 'wechat_sync_status', '已创建草稿');
        }
        update_post_meta($post_id, 'wechat_sync_platform', 'WeChatOA');
    }

    public static function poll($post_id, $count) {
        $token = Wechat_Sync_Token_Service::get_access_token();
        if (is_wp_error($token)) {
            update_post_meta($post_id, 'wechat_sync_status', '失败');
            update_post_meta($post_id, 'wechat_sync_error', $token->get_error_message());
            return;
        }
        $publish_id = get_post_meta($post_id, 'wechat_sync_publish_id', true);
        if (!$publish_id) return;
        $res = Wechat_Sync_Provider::check_publish_status($publish_id, $token);
        if (is_wp_error($res)) {
            Wechat_Sync_Logger::log_error($post_id, $res->get_error_message(), 'poll', $count, '失败');
            self::retry_or_fail($post_id, $count, $res->get_error_message());
            return;
        }
        if (!empty($res['publish_status']) && intval($res['publish_status']) === 0) {
            if (!empty($res['article_id'])) update_post_meta($post_id, 'wechat_sync_article_id', $res['article_id']);
            if (!empty($res['news_item'][0]['url'])) update_post_meta($post_id, 'wechat_sync_url', $res['news_item'][0]['url']);
            update_post_meta($post_id, 'wechat_sync_status', '成功');
            delete_post_meta($post_id, 'wechat_sync_next_poll');
            delete_post_meta($post_id, 'wechat_sync_poll_count');
        } else {
            if ($count < 10) {
                $delay = min(300, 30 * (1 + $count));
                update_post_meta($post_id, 'wechat_sync_poll_count', $count + 1);
                update_post_meta($post_id, 'wechat_sync_next_poll', time() + $delay);
            } else {
                update_post_meta($post_id, 'wechat_sync_status', '超时');
                Wechat_Sync_Logger::log_error($post_id, __('发布状态查询超时', WECHAT_SYNC_TEXTDOMAIN), 'poll', $count, '超时');
                delete_post_meta($post_id, 'wechat_sync_next_poll');
                delete_post_meta($post_id, 'wechat_sync_poll_count');
            }
        }
        update_post_meta($post_id, 'wechat_sync_platform', 'WeChatOA');
    }

    private static function retry_or_fail($post_id, $attempt, $message) {
        if ($attempt < 3) {
            $delay = [60, 300, 900][$attempt];
            update_post_meta($post_id, 'wechat_sync_status', '重试中');
            update_post_meta($post_id, 'wechat_sync_error', $message);
            update_post_meta($post_id, 'wechat_sync_attempt', $attempt + 1);
            update_post_meta($post_id, 'wechat_sync_next_attempt', time() + $delay);
        } else {
            update_post_meta($post_id, 'wechat_sync_status', '失败');
            update_post_meta($post_id, 'wechat_sync_error', $message);
            set_transient('wechat_sync_last_error', get_the_title($post_id) . ' - ' . $message, 600);
            if (get_option('wechat_sync_notify_admin', '0') === '1') {
                $to = get_option('admin_email');
                $subj = 'WeChat Sync 失败';
                $body = get_the_title($post_id) . ' - ' . $message;
                if ($to) wp_mail($to, $subj, $body);
            }
            Wechat_Sync_Logger::log_error($post_id, $message, 'retry', $attempt, '失败');
        }
    }
}
