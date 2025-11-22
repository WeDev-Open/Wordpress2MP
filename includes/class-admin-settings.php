<?php
if (!defined('ABSPATH')) { exit; }

class Wechat_Sync_Admin_Settings {
    public static function register() {
        register_setting('wechat_sync_settings', 'wechat_sync_appid');
        register_setting('wechat_sync_settings', 'wechat_sync_appsecret');
        register_setting('wechat_sync_settings', 'wechat_sync_auto');
        register_setting('wechat_sync_settings', 'wechat_sync_publish_mode');
        register_setting('wechat_sync_settings', 'wechat_sync_use_featured');
        register_setting('wechat_sync_settings', 'wechat_sync_use_first_image');
        register_setting('wechat_sync_settings', 'wechat_sync_process_images');
        register_setting('wechat_sync_settings', 'wechat_sync_debug_images');
        register_setting('wechat_sync_settings', 'wechat_sync_digest_length');
        register_setting('wechat_sync_settings', 'wechat_sync_author_source');
        register_setting('wechat_sync_settings', 'wechat_sync_author_custom');
        register_setting('wechat_sync_settings', 'wechat_sync_notify_admin');
        register_setting('wechat_sync_settings', 'wechat_sync_default_cover_id');
        register_setting('wechat_sync_settings', 'wechat_sync_log_limit');
        register_setting('wechat_sync_settings', 'wechat_sync_use_system_cron');
        register_setting('wechat_sync_settings', 'wechat_sync_process_batch');
        register_setting('wechat_sync_settings', 'wechat_sync_poll_batch');

        add_settings_section('wechat_sync_main', __('基本配置', WECHAT_SYNC_TEXTDOMAIN), function(){}, 'wechat-sync-settings');

        add_settings_field('wechat_sync_appid', __('AppID', WECHAT_SYNC_TEXTDOMAIN), [__CLASS__, 'render_field_appid'], 'wechat-sync-settings', 'wechat_sync_main');
        add_settings_field('wechat_sync_appsecret', __('AppSecret', WECHAT_SYNC_TEXTDOMAIN), [__CLASS__, 'render_field_appsecret'], 'wechat-sync-settings', 'wechat_sync_main');
        add_settings_field('wechat_sync_auto', __('自动同步新发布文章', WECHAT_SYNC_TEXTDOMAIN), [__CLASS__, 'render_field_auto'], 'wechat-sync-settings', 'wechat_sync_main');
        add_settings_field('wechat_sync_publish_mode', __('同步模式', WECHAT_SYNC_TEXTDOMAIN), [__CLASS__, 'render_field_publish_mode'], 'wechat-sync-settings', 'wechat_sync_main');
        add_settings_field('wechat_sync_use_featured', __('封面图使用特色图', WECHAT_SYNC_TEXTDOMAIN), [__CLASS__, 'render_field_use_featured'], 'wechat-sync-settings', 'wechat_sync_main');
        add_settings_field('wechat_sync_use_first_image', __('封面图使用文章首图（无图则默认）', WECHAT_SYNC_TEXTDOMAIN), [__CLASS__, 'render_field_use_first_image'], 'wechat-sync-settings', 'wechat_sync_main');
        add_settings_field('wechat_sync_process_images', __('处理文内图片', WECHAT_SYNC_TEXTDOMAIN), [__CLASS__, 'render_field_process_images'], 'wechat-sync-settings', 'wechat_sync_main');
        add_settings_field('wechat_sync_debug_images', __('图片调试日志', WECHAT_SYNC_TEXTDOMAIN), [__CLASS__, 'render_field_debug_images'], 'wechat-sync-settings', 'wechat_sync_main');
        add_settings_field('wechat_sync_digest_length', __('摘要长度', WECHAT_SYNC_TEXTDOMAIN), [__CLASS__, 'render_field_digest_length'], 'wechat-sync-settings', 'wechat_sync_main');
        add_settings_field('wechat_sync_author_source', __('作者来源', WECHAT_SYNC_TEXTDOMAIN), [__CLASS__, 'render_field_author_source'], 'wechat-sync-settings', 'wechat_sync_main');
        add_settings_field('wechat_sync_author_custom', __('自定义作者', WECHAT_SYNC_TEXTDOMAIN), [__CLASS__, 'render_field_author_custom'], 'wechat-sync-settings', 'wechat_sync_main');
        add_settings_field('wechat_sync_notify_admin', __('同步失败提醒管理员', WECHAT_SYNC_TEXTDOMAIN), [__CLASS__, 'render_field_notify_admin'], 'wechat-sync-settings', 'wechat_sync_main');
        add_settings_field('wechat_sync_default_cover_id', __('默认封面图附件ID', WECHAT_SYNC_TEXTDOMAIN), [__CLASS__, 'render_field_default_cover_id'], 'wechat-sync-settings', 'wechat_sync_main');
        add_settings_field('wechat_sync_log_limit', __('异常日志上限', WECHAT_SYNC_TEXTDOMAIN), [__CLASS__, 'render_field_log_limit'], 'wechat-sync-settings', 'wechat_sync_main');
        add_settings_field('wechat_sync_use_system_cron', __('使用系统级 Cron', WECHAT_SYNC_TEXTDOMAIN), [__CLASS__, 'render_field_use_system_cron'], 'wechat-sync-settings', 'wechat_sync_main');
        add_settings_field('wechat_sync_process_batch', __('处理并发批量大小', WECHAT_SYNC_TEXTDOMAIN), [__CLASS__, 'render_field_process_batch'], 'wechat-sync-settings', 'wechat_sync_main');
        add_settings_field('wechat_sync_poll_batch', __('轮询并发批量大小', WECHAT_SYNC_TEXTDOMAIN), [__CLASS__, 'render_field_poll_batch'], 'wechat-sync-settings', 'wechat_sync_main');
        
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap">';
        echo '<h1>' . esc_html(__('微信公众号同步设置', WECHAT_SYNC_TEXTDOMAIN)) . '</h1>';
        $msg = isset($_GET['wechat_sync_msg']) ? sanitize_text_field($_GET['wechat_sync_msg']) : '';
        if ($msg) { echo '<div class="notice notice-info"><p>' . esc_html($msg) . '</p></div>'; }
        echo '<form method="post" action="options.php">';
        settings_fields('wechat_sync_settings');
        do_settings_sections('wechat-sync-settings');
        submit_button();
        echo '</form>';
        $url = wp_nonce_url(admin_url('admin-post.php?action=wechat_sync_test_token'), 'wechat_sync_test_token');
        echo '<p><a class="button" href="' . esc_url($url) . '">' . esc_html(__('测试凭据', WECHAT_SYNC_TEXTDOMAIN)) . '</a> ';
        $check = wp_nonce_url(admin_url('admin-post.php?action=wechat_sync_check_publish'), 'wechat_sync_check_publish');
        echo '<a class="button" href="' . esc_url($check) . '">' . esc_html(__('检测发布权限/IP 白名单', WECHAT_SYNC_TEXTDOMAIN)) . '</a></p>';
        $use_system = get_option('wechat_sync_use_system_cron', '0') === '1' ? __('是', WECHAT_SYNC_TEXTDOMAIN) : __('否', WECHAT_SYNC_TEXTDOMAIN);
        $last = get_transient('wechat_sync_last_cron_run');
        $probe_btn = wp_nonce_url(admin_url('admin-post.php?action=wechat_sync_run_probe'), 'wechat_sync_run_probe');
        echo '<h2>' . esc_html(__('系统 Cron 状态', WECHAT_SYNC_TEXTDOMAIN)) . '</h2>';
        echo '<ul>';
        echo '<li>' . esc_html(__('是否启用', WECHAT_SYNC_TEXTDOMAIN)) . ': ' . esc_html($use_system) . '</li>';
        echo '<li>' . esc_html(__('最近执行时间', WECHAT_SYNC_TEXTDOMAIN)) . ': ' . esc_html($last ? date('Y-m-d H:i:s', $last) : __('未执行', WECHAT_SYNC_TEXTDOMAIN)) . '</li>';
        echo '</ul>';
        echo '<p><a class="button" href="' . esc_url($probe_btn) . '">' . esc_html(__('运行 Cron 探针', WECHAT_SYNC_TEXTDOMAIN)) . '</a></p>';
        echo '</div>';
    }

    public static function render_field_appid() {
        $v = esc_attr(get_option('wechat_sync_appid', ''));
        echo '<input type="text" name="wechat_sync_appid" value="' . $v . '" class="regular-text" />';
    }

    public static function render_field_appsecret() {
        $v = esc_attr(get_option('wechat_sync_appsecret', ''));
        echo '<input type="password" name="wechat_sync_appsecret" value="' . $v . '" class="regular-text" />';
    }

    public static function render_field_auto() {
        $v = get_option('wechat_sync_auto', '0');
        echo '<label><input type="checkbox" name="wechat_sync_auto" value="1" ' . checked('1', $v, false) . ' /> ' . esc_html(__('发布时自动同步', WECHAT_SYNC_TEXTDOMAIN)) . '</label>';
    }

    public static function render_field_publish_mode() {
        $v = get_option('wechat_sync_publish_mode', 'draft');
        echo '<select name="wechat_sync_publish_mode">';
        echo '<option value="draft" ' . selected('draft', $v, false) . '>' . esc_html(__('创建草稿', WECHAT_SYNC_TEXTDOMAIN)) . '</option>';
        echo '<option value="publish" ' . selected('publish', $v, false) . '>' . esc_html(__('直接发布', WECHAT_SYNC_TEXTDOMAIN)) . '</option>';
        echo '</select>';
    }

    public static function render_field_use_featured() {
        $v = get_option('wechat_sync_use_featured', '1');
        echo '<label><input type="checkbox" name="wechat_sync_use_featured" value="1" ' . checked('1', $v, false) . ' /> ' . esc_html(__('使用文章特色图作为封面', WECHAT_SYNC_TEXTDOMAIN)) . '</label>';
    }

    public static function render_field_use_first_image() {
        $v = get_option('wechat_sync_use_first_image', '0');
        echo '<label><input type="checkbox" name="wechat_sync_use_first_image" value="1" ' . checked('1', $v, false) . ' /> ' . esc_html(__('使用文章首张图片作为封面，无图则使用默认封面', WECHAT_SYNC_TEXTDOMAIN)) . '</label>';
    }

    public static function render_field_process_images() {
        $v = get_option('wechat_sync_process_images', '0');
        echo '<label><input type="checkbox" name="wechat_sync_process_images" value="1" ' . checked('1', $v, false) . ' /> ' . esc_html(__('上传并替换文内图片', WECHAT_SYNC_TEXTDOMAIN)) . '</label>';
    }

    public static function render_field_debug_images() {
        $v = get_option('wechat_sync_debug_images', '0');
        echo '<label><input type="checkbox" name="wechat_sync_debug_images" value="1" ' . checked('1', $v, false) . ' /> ' . esc_html(__('记录图片解析与上传映射日志', WECHAT_SYNC_TEXTDOMAIN)) . '</label>';
    }

    public static function render_field_digest_length() {
        $v = esc_attr(get_option('wechat_sync_digest_length', '120'));
        echo '<input type="number" min="10" step="10" name="wechat_sync_digest_length" value="' . $v . '" class="small-text" />';
    }

    public static function render_field_author_source() {
        $v = get_option('wechat_sync_author_source', 'display_name');
        echo '<select name="wechat_sync_author_source">';
        echo '<option value="display_name" ' . selected('display_name', $v, false) . '>' . esc_html(__('用户显示名', WECHAT_SYNC_TEXTDOMAIN)) . '</option>';
        echo '<option value="nicename" ' . selected('nicename', $v, false) . '>' . esc_html(__('用户别名', WECHAT_SYNC_TEXTDOMAIN)) . '</option>';
        echo '<option value="site_name" ' . selected('site_name', $v, false) . '>' . esc_html(__('站点名称', WECHAT_SYNC_TEXTDOMAIN)) . '</option>';
        echo '<option value="custom" ' . selected('custom', $v, false) . '>' . esc_html(__('自定义', WECHAT_SYNC_TEXTDOMAIN)) . '</option>';
        echo '</select>';
    }

    public static function render_field_author_custom() {
        $v = esc_attr(get_option('wechat_sync_author_custom', ''));
        echo '<input type="text" name="wechat_sync_author_custom" value="' . $v . '" class="regular-text" />';
    }

    public static function render_field_notify_admin() {
        $v = get_option('wechat_sync_notify_admin', '0');
        echo '<label><input type="checkbox" name="wechat_sync_notify_admin" value="1" ' . checked('1', $v, false) . ' /> ' . esc_html(__('失败时提醒管理员', WECHAT_SYNC_TEXTDOMAIN)) . '</label>';
    }

    public static function render_field_default_cover_id() {
        $v = esc_attr(get_option('wechat_sync_default_cover_id', ''));
        echo '<input type="number" min="0" name="wechat_sync_default_cover_id" value="' . $v . '" class="small-text" /> ';
        echo '<span class="description">' . esc_html(__('填写媒体库附件ID，用作默认封面', WECHAT_SYNC_TEXTDOMAIN)) . '</span>';
    }

    public static function render_field_log_limit() {
        $v = esc_attr(get_option('wechat_sync_log_limit', '500'));
        echo '<input type="number" min="50" step="50" name="wechat_sync_log_limit" value="' . $v . '" class="small-text" /> ';
        echo '<span class="description">' . esc_html(__('异常日志最大条数', WECHAT_SYNC_TEXTDOMAIN)) . '</span>';
    }

    public static function render_field_use_system_cron() {
        $v = get_option('wechat_sync_use_system_cron', '0');
        echo '<label><input type="checkbox" name="wechat_sync_use_system_cron" value="1" ' . checked('1', $v, false) . ' /> ' . esc_html(__('由系统 Cron 调用插件端点，插件不再站内触发', WECHAT_SYNC_TEXTDOMAIN)) . '</label>';
        $secret = get_option('wechat_sync_cron_secret', '');
        if (!$secret) { $secret = wp_generate_password(32, false); update_option('wechat_sync_cron_secret', $secret, false); }
        $cmd = 'curl -sS -m 60 \'' . esc_url(admin_url('admin-post.php?action=wechat_sync_cron&key=' . rawurlencode($secret))) . '\' > /dev/null 2>&1';
        echo '<p><code>' . esc_html($cmd) . '</code></p>';
    }

    public static function render_field_process_batch() {
        $v = esc_attr(get_option('wechat_sync_process_batch', '10'));
        echo '<input type="number" min="1" max="50" step="1" name="wechat_sync_process_batch" value="' . $v . '" class="small-text" /> ';
        echo '<span class="description">' . esc_html(__('每次调度批量触发的处理任务数（1-50）', WECHAT_SYNC_TEXTDOMAIN)) . '</span>';
    }

    public static function render_field_poll_batch() {
        $v = esc_attr(get_option('wechat_sync_poll_batch', '10'));
        echo '<input type="number" min="1" max="50" step="1" name="wechat_sync_poll_batch" value="' . $v . '" class="small-text" /> ';
        echo '<span class="description">' . esc_html(__('每次调度批量触发的轮询任务数（1-50）', WECHAT_SYNC_TEXTDOMAIN)) . '</span>';
    }

    
}

add_action('admin_post_wechat_sync_test_token', function(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wechat_sync_test_token');
    $token = Wechat_Sync_Token_Service::get_access_token();
    if (is_wp_error($token)) {
        wp_redirect(add_query_arg(['settings-updated' => 'false', 'wechat_sync_msg' => urlencode($token->get_error_message())], admin_url('admin.php?page=wechat-sync-settings')));
    } else {
        wp_redirect(add_query_arg(['settings-updated' => 'true', 'wechat_sync_msg' => urlencode(__('凭据有效', WECHAT_SYNC_TEXTDOMAIN))], admin_url('admin.php?page=wechat-sync-settings')));
    }
    exit;
});

add_action('admin_post_wechat_sync_run_probe', function(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wechat_sync_run_probe');
    if (class_exists('Wechat_Sync_Plugin')) { Wechat_Sync_Plugin::run_scheduler(); set_transient('wechat_sync_last_cron_run', time(), 600); }
    wp_redirect(admin_url('admin.php?page=wechat-sync-settings'));
    exit;
});

add_action('admin_post_wechat_sync_check_publish', function(){
    if (!current_user_can('manage_options')) wp_die('');
    check_admin_referer('wechat_sync_check_publish');
    $token = Wechat_Sync_Token_Service::get_access_token();
    if (is_wp_error($token)) {
        wp_redirect(add_query_arg(['settings-updated' => 'false', 'wechat_sync_msg' => urlencode($token->get_error_message())], admin_url('admin.php?page=wechat-sync-settings')));
        exit;
    }
    $ip_ok = true;
    $draft_ok = false;
    $publish_ok = false;
    $u1 = 'https://api.weixin.qq.com/cgi-bin/draft/count?access_token=' . rawurlencode($token);
    $r1 = wp_remote_get($u1, ['timeout' => 20]);
    if (!is_wp_error($r1)) {
        $j1 = json_decode(wp_remote_retrieve_body($r1), true);
        if (is_array($j1) && isset($j1['errcode'])) {
            $c1 = intval($j1['errcode']);
            if ($c1 === 0) { $draft_ok = true; }
            else if ($c1 === 40164) { $ip_ok = false; }
            else if ($c1 === 48001) { $draft_ok = false; }
        }
    }
    $u2 = 'https://api.weixin.qq.com/cgi-bin/freepublish/get?access_token=' . rawurlencode($token);
    $r2 = wp_remote_post($u2, [
        'timeout' => 20,
        'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
        'body' => wp_json_encode(['publish_id' => 'TEST']),
    ]);
    if (!is_wp_error($r2)) {
        $j2 = json_decode(wp_remote_retrieve_body($r2), true);
        if (is_array($j2) && isset($j2['errcode'])) {
            $c2 = intval($j2['errcode']);
            if ($c2 === 48001) { $publish_ok = false; }
            else if ($c2 === 40164) { $ip_ok = false; }
            else { $publish_ok = true; }
        }
    }
    $msg = '凭据: 有效 | 草稿接口: ' . ($draft_ok ? '可用' : '未授权') . ' | 发布接口: ' . ($publish_ok ? '可用' : '未授权') . ' | IP 白名单: ' . ($ip_ok ? '正常' : '未配置');
    wp_redirect(add_query_arg(['settings-updated' => 'true', 'wechat_sync_msg' => urlencode($msg)], admin_url('admin.php?page=wechat-sync-settings')));
    exit;
});

//