<?php
if (!defined('ABSPATH')) { exit; }

class Wechat_Sync_Token_Service {
    public static function get_access_token() {
        $cached = get_transient('wechat_sync_access_token');
        if ($cached) return $cached;
        $appid = trim((string)get_option('wechat_sync_appid', ''));
        $secret = trim((string)get_option('wechat_sync_appsecret', ''));
        if (!$appid || !$secret) return new WP_Error('wechat_sync_creds', __('缺少 AppID 或 AppSecret', WECHAT_SYNC_TEXTDOMAIN));
        $url = 'https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=' . rawurlencode($appid) . '&secret=' . rawurlencode($secret);
        $res = wp_remote_get($url, ['timeout' => 20]);
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        if ($code !== 200) return new WP_Error('wechat_sync_http', __('微信接口错误', WECHAT_SYNC_TEXTDOMAIN));
        $json = json_decode($body, true);
        if (isset($json['errcode']) && intval($json['errcode']) !== 0) {
            delete_transient('wechat_sync_access_token');
            $res2 = wp_remote_get($url, ['timeout' => 20]);
            if (is_wp_error($res2)) return $res2;
            $json2 = json_decode(wp_remote_retrieve_body($res2), true);
            if (!is_array($json2) || empty($json2['access_token'])) {
                $code = isset($json2['errcode']) ? intval($json2['errcode']) : 0;
                if ($code === 40164) {
                    return new WP_Error('wechat_sync_ip_whitelist', __('服务器出口 IP 未加入白名单：请在公众号平台配置 IP 白名单', WECHAT_SYNC_TEXTDOMAIN));
                } else if ($code === 48001) {
                    return new WP_Error('wechat_sync_unauthorized', __('API 未授权：请在公众号平台开通接口权限或升级账号权限', WECHAT_SYNC_TEXTDOMAIN));
                }
                $err = isset($json2['errmsg']) ? $json2['errmsg'] : __('响应无效', WECHAT_SYNC_TEXTDOMAIN);
                return new WP_Error('wechat_sync_token', $err);
            }
            $json = $json2;
        }
        if (!is_array($json) || empty($json['access_token'])) {
            $err = isset($json['errmsg']) ? $json['errmsg'] : __('响应无效', WECHAT_SYNC_TEXTDOMAIN);
            return new WP_Error('wechat_sync_token', $err);
        }
        $token = $json['access_token'];
        $ttl = isset($json['expires_in']) ? intval($json['expires_in']) : 7000;
        set_transient('wechat_sync_access_token', $token, $ttl);
        return $token;
    }
}