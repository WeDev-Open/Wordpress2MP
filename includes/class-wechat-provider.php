<?php
if (!defined('ABSPATH')) { exit; }

class Wechat_Sync_Provider {
    private static function encode_json($data, $flags = 0) {
        if (function_exists('wp_json_encode')) return wp_json_encode($data, $flags);
        return json_encode($data, $flags);
    }
    public static function upload_content_image($file_path, $token) {
        $url = 'https://api.weixin.qq.com/cgi-bin/media/uploadimg?access_token=' . rawurlencode($token);
        $res = self::multipart_request($url, $file_path, 'media');
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            $err = __('响应无效', WECHAT_SYNC_TEXTDOMAIN);
            return new WP_Error('wechat_sync_uploadimg', 'HTTP:' . intval($code) . ' ' . $err);
        }
        if (isset($json['errcode']) && intval($json['errcode']) !== 0) {
            $err = (isset($json['errmsg']) ? $json['errmsg'] : __('上传失败', WECHAT_SYNC_TEXTDOMAIN));
            return new WP_Error('wechat_sync_uploadimg', 'HTTP:' . intval($code) . ' errcode:' . intval($json['errcode']) . ' ' . $err);
        }
        if (empty($json['url'])) {
            return new WP_Error('wechat_sync_uploadimg', 'HTTP:' . intval($code) . ' ' . __('上传失败', WECHAT_SYNC_TEXTDOMAIN));
        }
        return $json['url'];
    }

    public static function process_images_in_content($post_id, $content, $token) {
        if (!$content) return $content;
        
        if (!class_exists('DOMDocument')) {
            return new WP_Error('wechat_sync_dom', __('服务器缺少 DOM 扩展，无法解析图片', WECHAT_SYNC_TEXTDOMAIN));
        }

        // Use DOMDocument to parse HTML
        $dom = new DOMDocument();
        // Suppress errors due to malformed HTML
        libxml_use_internal_errors(true);
        // Hack to handle UTF-8 correctly
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $figures = $dom->getElementsByTagName('figure');
        if ($figures->length > 0) {
            for ($i = $figures->length - 1; $i >= 0; $i--) {
                $f = $figures->item($i);
                $imgNode = null;
                $captionText = '';
                foreach ($f->childNodes as $child) {
                    if ($child->nodeName === 'img') { $imgNode = $child; }
                    if ($child->nodeName === 'figcaption') { $captionText = trim($child->textContent); }
                }
                $parent = $f->parentNode;
                if ($parent && $imgNode) {
                    if (!$imgNode->getAttribute('alt') && $captionText) { $imgNode->setAttribute('alt', $captionText); }
                    $p1 = $dom->createElement('p');
                    $p1->appendChild($imgNode->cloneNode(true));
                    $parent->insertBefore($p1, $f);
                    if ($captionText) {
                        $p2 = $dom->createElement('p', $captionText);
                        $parent->insertBefore($p2, $f);
                    }
                    $parent->removeChild($f);
                }
            }
        }
        $images = $dom->getElementsByTagName('img');
        if ($images->length === 0) return $content;

        $map = [];
        foreach ($images as $img) {
            $src = $img->getAttribute('src');
            $data_src = $img->getAttribute('data-src');
            $data_original = $img->getAttribute('data-original');
            $data_actualsrc = $img->getAttribute('data-actualsrc');
            $data_lazy_src = $img->getAttribute('data-lazy-src');
            $srcset_val = $img->getAttribute('srcset');
            $candidate = '';
            if ($data_src) $candidate = $data_src; else if ($data_original) $candidate = $data_original; else if ($data_actualsrc) $candidate = $data_actualsrc; else if ($data_lazy_src) $candidate = $data_lazy_src;
            if (!$candidate || strpos($candidate, 'data:') === 0) { $candidate = $src; }
            if ((!$candidate || strpos($candidate, 'data:') === 0 || preg_match('/\.svg(\?|$)/i', $candidate)) && $srcset_val) {
                $best = '';
                $bestw = 0;
                $list = explode(',', $srcset_val);
                foreach ($list as $entry) {
                    $entry = trim($entry);
                    if (!$entry) continue;
                    $parts = preg_split('/\s+/', $entry);
                    $u = $parts[0];
                    $w = 0;
                    foreach ($parts as $pp) {
                        if (preg_match('/^(\d+)w$/', $pp, $m)) { $w = intval($m[1]); }
                        else if (preg_match('/^(\d+)x$/', $pp, $m)) { $w = max($w, intval($m[1]) * 1000); }
                    }
                    if ($w >= $bestw) { $best = $u; $bestw = $w; }
                }
                if ($best) { $candidate = $best; }
            }
            $raw = $candidate;
            $candidate = self::sanitize_candidate_url($candidate);
            $debug = get_option('wechat_sync_debug_images', '0') === '1';
            if ($debug) {
                $msg = '解析图片地址 candidate:' . $candidate . ' raw:' . $raw . ' src:' . $src . ' data-src:' . $data_src . ' data-original:' . $data_original . ' srcset:' . ($srcset_val ? '[有]' : '[无]');
                Wechat_Sync_Logger::log_info($post_id, $msg, 'uploadimg');
            }
            if (!$candidate) continue;
            if (isset($map[$candidate])) continue;
            $img->removeAttribute('srcset');
            $img->removeAttribute('data-src');
            $img->removeAttribute('data-original');
            $img->removeAttribute('data-actualsrc');
            $img->removeAttribute('data-lazy-src');
            $img->removeAttribute('data-lazyload');
            $img->removeAttribute('data-lazy');
            $img->removeAttribute('loading');
            $img->removeAttribute('decoding');

            $norm = $candidate;
            if (strpos($norm, '://') === false) {
                if (substr($norm, 0, 2) === '//') { $norm = (is_ssl() ? 'https:' : 'http:') . $norm; }
                else { $norm = (substr($norm, 0, 1) === '/') ? home_url($norm) : home_url('/' . ltrim($norm, '/')); }
            }
            
            $attachment_id = attachment_url_to_postid($norm);
            $file = '';
            $tmp = '';
            
            if ($attachment_id) {
                $file = get_attached_file($attachment_id);
                if ($file && file_exists($file)) {
                    $mime0 = self::get_mime($file);
                    if ($mime0 === 'image/svg+xml') {
                        Wechat_Sync_Logger::log_error($post_id, '跳过SVG附件 URL:' . $norm, 'uploadimg', 0, '警告');
                        continue;
                    }
                    $normed = self::normalize_image_for_upload($file, 'uploadimg');
                    if (is_wp_error($normed)) {
                        Wechat_Sync_Logger::log_error($post_id, $normed->get_error_message() . ' URL:' . $norm, 'uploadimg', 0, '警告');
                        continue;
                    }
                    if (is_string($normed) && file_exists($normed)) {
                        $file = $normed;
                    }
                }
            } else {
                $tmp = self::fetch_image_to_temp($norm);
                if (is_wp_error($tmp)) {
                    Wechat_Sync_Logger::log_error($post_id, $tmp->get_error_message(), 'uploadimg', 0, '警告');
                    continue;
                } else if (file_exists($tmp)) {
                    $mime1 = self::get_mime($tmp);
                    if ($mime1 === 'image/svg+xml') {
                        Wechat_Sync_Logger::log_error($post_id, '跳过SVG URL:' . $norm, 'uploadimg', 0, '警告');
                        @unlink($tmp);
                        continue;
                    }
                    $normed = self::normalize_image_for_upload($tmp, 'uploadimg');
                    if (is_wp_error($normed)) {
                        Wechat_Sync_Logger::log_error($post_id, $normed->get_error_message() . ' URL:' . $norm, 'uploadimg', 0, '警告');
                        @unlink($tmp);
                        continue;
                    }
                    if (is_string($normed) && file_exists($normed)) {
                        $file = $normed;
                    } else {
                        $file = $tmp;
                    }
                }
            }

            if ($file && file_exists($file)) {
                $uploaded = self::upload_content_image($file, $token);
                if (is_wp_error($uploaded)) {
                    Wechat_Sync_Logger::log_error($post_id, $uploaded->get_error_message() . ' URL:' . $norm, 'uploadimg', 0, '警告');
                } else if (is_string($uploaded) && $uploaded) {
                    $map[$candidate] = $uploaded;
                    $img->setAttribute('src', $uploaded);
                    if ($debug) { Wechat_Sync_Logger::log_info($post_id, '上传成功映射 ' . $candidate . ' -> ' . $uploaded, 'uploadimg'); }
                    if (!$img->getAttribute('alt')) {
                        $bn = basename(parse_url($norm, PHP_URL_PATH));
                        $bn = preg_replace('/\.[^.]+$/', '', $bn);
                        $alt = $bn ? $bn : get_bloginfo('name');
                        $img->setAttribute('alt', $alt);
                    }
                }
            }

            if (!$attachment_id) {
                if ($tmp && file_exists($tmp)) @unlink($tmp);
                if (isset($normed) && $normed && file_exists($normed) && $normed !== $tmp) @unlink($normed);
            }
        }

        self::decode_named_entities_dom($dom);
        $new_content = $dom->saveHTML();
        $new_content = str_replace('<?xml encoding="utf-8" ?>', '', $new_content);
        return $new_content;
    }
    public static function upload_thumb($attachment_id, $token, $force = false) {
        $appid = trim((string)get_option('wechat_sync_appid', ''));
        $key = 'wechat_thumb_media_id_' . md5($appid);
        $cached = get_post_meta($attachment_id, $key, true);
        if (!$force && $cached) return $cached;
        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) return new WP_Error('wechat_sync_thumb', __('封面图不存在', WECHAT_SYNC_TEXTDOMAIN));
        $normed = self::normalize_image_for_upload($file, 'material');
        $send = (is_wp_error($normed) || !is_string($normed)) ? $file : $normed;
        $url = 'https://api.weixin.qq.com/cgi-bin/material/add_material?access_token=' . rawurlencode($token) . '&type=image';
        $res = self::multipart_request($url, $send, 'media');
        if (is_wp_error($res)) return $res;
        $body = wp_remote_retrieve_body($res);
        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['media_id'])) {
            $err = isset($json['errmsg']) ? $json['errmsg'] : __('上传失败', WECHAT_SYNC_TEXTDOMAIN);
            return new WP_Error('wechat_sync_thumb', $err);
        }
        if ($send !== $file && file_exists($send)) { @unlink($send); }
        $media_id = $json['media_id'];
        $appid = trim((string)get_option('wechat_sync_appid', ''));
        $key = 'wechat_thumb_media_id_' . md5($appid);
        update_post_meta($attachment_id, $key, $media_id);
        return $media_id;
    }

    public static function create_draft($post_id, $token) {
        $post = get_post($post_id);
        if (!$post) return new WP_Error('wechat_sync_post', __('帖子不存在', WECHAT_SYNC_TEXTDOMAIN));
        $title = get_the_title($post);
        $src = get_option('wechat_sync_author_source', 'display_name');
        $author = $src === 'custom' ? (string)get_option('wechat_sync_author_custom', '') : ($src === 'site_name' ? get_bloginfo('name') : ($src === 'nicename' ? get_the_author_meta('user_nicename', $post->post_author) : get_the_author_meta('display_name', $post->post_author)));
        $len = max(10, intval(get_option('wechat_sync_digest_length', '120')));
        if (has_excerpt($post)) {
            $digest = wp_strip_all_tags(get_the_excerpt($post));
            $digest = function_exists('mb_substr') ? mb_substr($digest, 0, $len) : substr($digest, 0, $len);
        } else {
            $plain = wp_strip_all_tags($post->post_content);
            $digest = function_exists('mb_substr') ? mb_substr($plain, 0, $len) : substr($plain, 0, $len);
        }
        $title = self::clamp_text($title, 128);
        $author = self::clamp_text($author, 32);
        $digest = self::clamp_text($digest, 512);
        $content = apply_filters('the_content', $post->post_content);
        $content = wp_kses_post($content);
        $stats = self::analyze_quote_entities($content);
        if ($stats) { Wechat_Sync_Logger::log_info($post_id, $stats, 'content'); }
        if (get_option('wechat_sync_process_images', '0') === '1') {
            $content = self::process_images_in_content($post_id, $content, $token);
            if (is_wp_error($content)) return $content;
        } else {
            $content = self::decode_entities_in_html($content);
        }
        $source = get_permalink($post);
        $thumb_id = get_post_thumbnail_id($post);
        $thumb_media_id = '';
        $prefer_first = get_option('wechat_sync_use_first_image', '0') === '1';
        if ($prefer_first) {
            $first = self::find_first_image_url_in_html($post->post_content);
            if ($first) {
                $tu = self::upload_thumb_from_url($first, $token);
                if (is_wp_error($tu)) {
                    $default_id = intval(get_option('wechat_sync_default_cover_id', 0));
                    if ($default_id) {
                        $t = self::upload_thumb($default_id, $token);
                        if (is_wp_error($t)) return $t;
                        $thumb_media_id = $t;
                    }
                } else {
                    $thumb_media_id = $tu;
                }
            } else {
                $default_id = intval(get_option('wechat_sync_default_cover_id', 0));
                if ($default_id) {
                    $t = self::upload_thumb($default_id, $token);
                    if (is_wp_error($t)) return $t;
                    $thumb_media_id = $t;
                }
            }
        } else {
            if (get_option('wechat_sync_use_featured', '1') === '1' && $thumb_id) {
                $t = self::upload_thumb($thumb_id, $token);
                if (is_wp_error($t)) return $t;
                $thumb_media_id = $t;
            } else {
                $default_id = intval(get_option('wechat_sync_default_cover_id', 0));
                if ($default_id) {
                    $t = self::upload_thumb($default_id, $token);
                    if (is_wp_error($t)) return $t;
                    $thumb_media_id = $t;
                }
            }
        }
        $payload = [
            'articles' => [[
                'title' => $title,
                'author' => $author,
                'digest' => $digest,
                'content' => $content,
                'content_source_url' => $source,
                'thumb_media_id' => $thumb_media_id,
            ]]
        ];
        $url = 'https://api.weixin.qq.com/cgi-bin/draft/add?access_token=' . rawurlencode($token);
        $res = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body' => self::encode_json($payload, defined('JSON_UNESCAPED_UNICODE') ? (JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 0),
        ]);
        if (is_wp_error($res)) return $res;
        $body = wp_remote_retrieve_body($res);
        $json = json_decode($body, true);
        if (isset($json['errcode']) && intval($json['errcode']) !== 0) {
            $code = intval($json['errcode']);
            if ($code === 40007 && $thumb_media_id) {
                $re_id = $thumb_id ? $thumb_id : intval(get_option('wechat_sync_default_cover_id', 0));
                $new = self::upload_thumb($re_id, $token, true);
                if (is_wp_error($new)) return $new;
                $thumb_media_id = $new;
                $payload['articles'][0]['thumb_media_id'] = $thumb_media_id;
                $res = wp_remote_post($url, [
                    'timeout' => 30,
                    'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                    'body' => self::encode_json($payload, defined('JSON_UNESCAPED_UNICODE') ? (JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 0),
                ]);
                if (is_wp_error($res)) return $res;
                $json = json_decode(wp_remote_retrieve_body($res), true);
            } else if (in_array($code, [40001, 40014, 42001], true)) {
                delete_transient('wechat_sync_access_token');
                $new_token = Wechat_Sync_Token_Service::get_access_token();
                if (is_wp_error($new_token)) return $new_token;
                $url = 'https://api.weixin.qq.com/cgi-bin/draft/add?access_token=' . rawurlencode($new_token);
                $res = wp_remote_post($url, [
                    'timeout' => 30,
                    'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                    'body' => self::encode_json($payload, defined('JSON_UNESCAPED_UNICODE') ? (JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 0),
                ]);
                if (is_wp_error($res)) return $res;
                $json = json_decode(wp_remote_retrieve_body($res), true);
            } else if (in_array($code, [48001, 40164], true)) {
                if ($code === 48001) {
                    return new WP_Error('wechat_sync_unauthorized', __('API 未授权：请在公众号平台开通素材/草稿接口权限或检查账号权限', WECHAT_SYNC_TEXTDOMAIN));
                } else {
                    return new WP_Error('wechat_sync_ip_whitelist', __('服务器出口 IP 未加入白名单：请在公众号平台配置 IP 白名单', WECHAT_SYNC_TEXTDOMAIN));
                }
            }
        }
        if (!is_array($json) || empty($json['media_id'])) {
            $err = isset($json['errmsg']) ? $json['errmsg'] : __('草稿创建失败', WECHAT_SYNC_TEXTDOMAIN);
            return new WP_Error('wechat_sync_draft', $err);
        }
        return $json['media_id'];
    }

    public static function publish($media_id, $token) {
        $url = 'https://api.weixin.qq.com/cgi-bin/freepublish/submit?access_token=' . rawurlencode($token);
        $res = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body' => self::encode_json(['media_id' => $media_id]),
        ]);
        if (is_wp_error($res)) return $res;
        $body = wp_remote_retrieve_body($res);
        $json = json_decode($body, true);
        if (isset($json['errcode']) && intval($json['errcode']) !== 0) {
            $code = intval($json['errcode']);
            if ($code === 48001) {
                return new WP_Error('wechat_sync_publish_unauthorized', __('发布接口未授权：请在公众号平台开通发布能力或升级账号权限', WECHAT_SYNC_TEXTDOMAIN));
            } else if ($code === 40164) {
                return new WP_Error('wechat_sync_ip_whitelist', __('服务器出口 IP 未加入白名单：请在公众号平台配置 IP 白名单', WECHAT_SYNC_TEXTDOMAIN));
            } else {
                return new WP_Error('wechat_sync_publish', isset($json['errmsg']) ? $json['errmsg'] : __('发布提交失败', WECHAT_SYNC_TEXTDOMAIN));
            }
        }
        if (!is_array($json) || empty($json['publish_id'])) {
            $err = isset($json['errmsg']) ? $json['errmsg'] : __('发布提交失败', WECHAT_SYNC_TEXTDOMAIN);
            return new WP_Error('wechat_sync_publish', $err);
        }
        return $json['publish_id'];
    }

    public static function check_publish_status($publish_id, $token) {
        $url = 'https://api.weixin.qq.com/cgi-bin/freepublish/get?access_token=' . rawurlencode($token);
        $res = wp_remote_post($url, [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
            'body' => self::encode_json(['publish_id' => $publish_id]),
        ]);
        if (is_wp_error($res)) return $res;
        $body = wp_remote_retrieve_body($res);
        $json = json_decode($body, true);
        if (!is_array($json)) return new WP_Error('wechat_sync_status', __('状态查询失败', WECHAT_SYNC_TEXTDOMAIN));
        if (isset($json['errcode']) && intval($json['errcode']) !== 0) {
            $code = intval($json['errcode']);
            if ($code === 48001) {
                return new WP_Error('wechat_sync_publish_unauthorized', __('发布接口未授权：请在公众号平台开通发布能力或升级账号权限', WECHAT_SYNC_TEXTDOMAIN));
            } else if ($code === 40164) {
                return new WP_Error('wechat_sync_ip_whitelist', __('服务器出口 IP 未加入白名单：请在公众号平台配置 IP 白名单', WECHAT_SYNC_TEXTDOMAIN));
            }
            return new WP_Error('wechat_sync_status', isset($json['errmsg']) ? $json['errmsg'] : __('状态查询失败', WECHAT_SYNC_TEXTDOMAIN));
        }
        return $json;
    }

    private static function multipart_request($url, $file_path, $file_field_name = 'media') {
        $boundary = wp_generate_password(24);
        $headers = [
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
        ];
        $payload = '';
        $payload .= '--' . $boundary . "\r\n";
        $payload .= 'Content-Disposition: form-data; name="' . $file_field_name . '"; filename="' . basename($file_path) . '"' . "\r\n";
        $mime = self::get_mime($file_path);
        $payload .= 'Content-Type: ' . $mime . "\r\n\r\n";
        $payload .= file_get_contents($file_path) . "\r\n";
        $payload .= '--' . $boundary . '--' . "\r\n";

        $args = [
            'timeout' => 60,
            'headers' => $headers,
            'body' => $payload,
        ];
        return wp_remote_post($url, $args);
    }

    private static function get_mime($file_path) {
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f) { $m = finfo_file($f, $file_path); finfo_close($f); if ($m) return $m; }
        }
        $type = wp_check_filetype($file_path);
        if (!empty($type['type'])) return $type['type'];
        return 'application/octet-stream';
    }

    private static function normalize_image_for_upload($file, $target = 'uploadimg') {
        $mime = self::get_mime($file);
        $allowed_uploadimg = ['image/jpeg','image/png'];
        $allowed_material = ['image/jpeg','image/png','image/gif'];
        $allowed = ($target === 'material') ? $allowed_material : $allowed_uploadimg;
        if (!in_array($mime, $allowed, true)) {
            $data = file_get_contents($file);
            if (!$data) return new WP_Error('wechat_sync_invalid_type', __('文件类型不支持，转换失败', WECHAT_SYNC_TEXTDOMAIN));
            if ($mime === 'image/svg+xml' && !class_exists('Imagick')) {
                return new WP_Error('wechat_sync_invalid_type', __('SVG 无法转换，请安装 Imagick 或改用 PNG/JPG', WECHAT_SYNC_TEXTDOMAIN));
            }
            $prefer_png = in_array($mime, ['image/png','image/webp','image/gif','image/svg+xml','image/bmp'], true);
            $im = null;
            if (function_exists('imagecreatefromstring')) {
                $im = @imagecreatefromstring($data);
                if ($im) {
                    $tmp = tempnam(get_temp_dir(), 'wximg_');
                    if ($prefer_png && function_exists('imagesavealpha') && function_exists('imagepng')) {
                        imagesavealpha($im, true);
                        @imagepng($im, $tmp, 6);
                        if (file_exists($tmp)) { $file = $tmp; $mime = 'image/png'; }
                    } else if (function_exists('imagejpeg')) {
                        $bg = imagecreatetruecolor(imagesx($im), imagesy($im));
                        $white = imagecolorallocate($bg, 255, 255, 255);
                        imagefilledrectangle($bg, 0, 0, imagesx($im), imagesy($im), $white);
                        imagecopy($bg, $im, 0, 0, 0, 0, imagesx($im), imagesy($im));
                        @imagejpeg($bg, $tmp, 85);
                        imagedestroy($bg);
                        if (file_exists($tmp)) { $file = $tmp; $mime = 'image/jpeg'; }
                    }
                    imagedestroy($im);
                }
            }
            if (!in_array($mime, $allowed, true) && class_exists('Imagick')) {
                try {
                    $img = new Imagick();
                    $img->readImageBlob($data);
                    $fmt = $prefer_png ? 'png' : 'jpeg';
                    $img->setImageFormat($fmt);
                    $tmp = tempnam(get_temp_dir(), 'wximg_');
                    $img->writeImage($tmp);
                    $img->clear();
                    $img->destroy();
                    if (file_exists($tmp)) { $file = $tmp; $mime = $prefer_png ? 'image/png' : 'image/jpeg'; }
                } catch (Exception $e) {}
            }
        }
        $max = ($target === 'material') ? 2097152 : 2097152;
        $size = @filesize($file);
        if ($size && $size > $max) {
            if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
                $im = @imagecreatefromjpeg($file);
                if ($im) {
                    $q = 80;
                    while ($q >= 50) {
                        $tmp = tempnam(get_temp_dir(), 'wximg_');
                        @imagejpeg($im, $tmp, $q);
                        if (file_exists($tmp) && @filesize($tmp) < $max) { @unlink($file); $file = $tmp; break; }
                        if (file_exists($tmp)) @unlink($tmp);
                        $q -= 10;
                    }
                    imagedestroy($im);
                }
            } else if ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
                $im = @imagecreatefrompng($file);
                if ($im) {
                    $tmp = tempnam(get_temp_dir(), 'wximg_');
                    imagesavealpha($im, true);
                    @imagepng($im, $tmp, 7);
                    if (file_exists($tmp) && @filesize($tmp) < $max) { @unlink($file); $file = $tmp; }
                    else if (file_exists($tmp)) { @unlink($tmp); }
                    imagedestroy($im);
                }
                $sz = @filesize($file);
                if ($sz && $sz > $max && function_exists('imagecreatefrompng') && function_exists('imagejpeg')) {
                    $im2 = @imagecreatefrompng($file);
                    if ($im2) {
                        $bg = imagecreatetruecolor(imagesx($im2), imagesy($im2));
                        $white = imagecolorallocate($bg, 255, 255, 255);
                        imagefilledrectangle($bg, 0, 0, imagesx($im2), imagesy($im2), $white);
                        imagecopy($bg, $im2, 0, 0, 0, 0, imagesx($im2), imagesy($im2));
                        $q = 80;
                        while ($q >= 50) {
                            $tmp2 = tempnam(get_temp_dir(), 'wximg_');
                            @imagejpeg($bg, $tmp2, $q);
                            if (file_exists($tmp2) && @filesize($tmp2) < $max) { @unlink($file); $file = $tmp2; $mime = 'image/jpeg'; break; }
                            if (file_exists($tmp2)) @unlink($tmp2);
                            $q -= 10;
                        }
                        imagedestroy($bg);
                        imagedestroy($im2);
                    }
                }
            }
        }
        return $file;
    }

    private static function sanitize_candidate_url($url) {
        if (!is_string($url)) return '';
        $u = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $u = trim($u);
        $u = trim($u, " \t\n\r\0\x0B\"'`()[]`");
        if (stripos($u, 'url(') === 0 && substr($u, -1) === ')') {
            $u = trim(substr($u, 4, -1), " \t\n\r\0\x0B\"'`()[]`");
        }
        return $u;
    }

    private static function find_first_image_url_in_html($html) {
        if (!is_string($html) || $html === '') return '';
        if (!class_exists('DOMDocument')) {
            $m = [];
            if (preg_match('/<img[^>]+(data-src|data-original|data-actualsrc|data-lazy-src|src)\s*=\s*\"([^\"]+)\"/i', $html, $m)) {
                $cand = $m[2];
            } else if (preg_match("/<img[^>]+(data-src|data-original|data-actualsrc|data-lazy-src|src)\s*=\s*'([^']+)'/i", $html, $m)) {
                $cand = $m[2];
            } else {
                $cand = '';
            }
            $cand = self::sanitize_candidate_url($cand);
            return $cand;
        }
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $images = $dom->getElementsByTagName('img');
        if ($images->length === 0) return '';
        $img = $images->item(0);
        $src = $img->getAttribute('src');
        $data_src = $img->getAttribute('data-src');
        $data_original = $img->getAttribute('data-original');
        $data_actualsrc = $img->getAttribute('data-actualsrc');
        $data_lazy_src = $img->getAttribute('data-lazy-src');
        $srcset_val = $img->getAttribute('srcset');
        $candidate = '';
        if ($data_src) $candidate = $data_src; else if ($data_original) $candidate = $data_original; else if ($data_actualsrc) $candidate = $data_actualsrc; else if ($data_lazy_src) $candidate = $data_lazy_src;
        if (!$candidate || strpos($candidate, 'data:') === 0) { $candidate = $src; }
        if ((!$candidate || strpos($candidate, 'data:') === 0 || preg_match('/\.svg(\?|$)/i', $candidate)) && $srcset_val) {
            $best = '';
            $bestw = 0;
            $list = explode(',', $srcset_val);
            foreach ($list as $entry) {
                $entry = trim($entry);
                if (!$entry) continue;
                $parts = preg_split('/\s+/', $entry);
                $u = $parts[0];
                $w = 0;
                foreach ($parts as $pp) {
                    if (preg_match('/^(\d+)w$/', $pp, $m)) { $w = intval($m[1]); }
                    else if (preg_match('/^(\d+)x$/', $pp, $m)) { $w = max($w, intval($m[1]) * 1000); }
                }
                if ($w >= $bestw) { $best = $u; $bestw = $w; }
            }
            if ($best) { $candidate = $best; }
        }
        $candidate = self::sanitize_candidate_url($candidate);
        return $candidate;
    }

    private static function decode_entities_in_html($html) {
        if (!class_exists('DOMDocument')) {
            $flags = defined('ENT_HTML5') ? (ENT_QUOTES | ENT_HTML5) : (ENT_QUOTES | ENT_HTML401);
            $v = html_entity_decode($html, $flags, 'UTF-8');
            $v = html_entity_decode($v, $flags, 'UTF-8');
            $v = self::decode_quote_entities_str($v);
            return $v;
        }
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        self::decode_named_entities_dom($dom);
        $new = $dom->saveHTML();
        $new = str_replace('<?xml encoding="utf-8" ?>', '', $new);
        return $new;
    }

    private static function analyze_quote_entities($html) {
        $pairs = ['&quot;','&apos;','&ldquo;','&rdquo;','&lsquo;','&rsquo;'];
        $counts = [];
        foreach ($pairs as $e) {
            $c1 = preg_match_all('/' . preg_quote($e, '/') . '/i', $html);
            $c2 = preg_match_all('/' . preg_quote('&amp;' . substr($e, 1), '/') . '/i', $html);
            $n = intval($c1) + intval($c2);
            if ($n > 0) { $counts[$e] = $n; }
        }
        if (empty($counts)) return '';
        $parts = [];
        foreach ($counts as $k => $v) { $parts[] = $k . ':' . $v; }
        return '引号实体检测 ' . implode(' ', $parts);
    }

    private static function decode_named_entities_dom($dom) {
        $xp = new DOMXPath($dom);
        $nodes = $xp->query('//text()');
        if (!$nodes) return;
        foreach ($nodes as $n) {
            $p = $n->parentNode;
            $skip = false;
            while ($p) {
                $nn = strtolower($p->nodeName);
                if ($nn === 'pre' || $nn === 'code' || $nn === 'script' || $nn === 'style') { $skip = true; break; }
                $p = $p->parentNode;
            }
            if ($skip) continue;
            $orig = $n->nodeValue;
            $flags = defined('ENT_HTML5') ? (ENT_QUOTES | ENT_HTML5) : (ENT_QUOTES | ENT_HTML401);
            $val = $orig;
            for ($i = 0; $i < 3; $i++) {
                $prev = $val;
                $val = html_entity_decode($val, $flags, 'UTF-8');
                $val = self::decode_quote_entities_str($val);
                if ($val === $prev) break;
            }
            if ($val !== $orig) { $n->nodeValue = $val; }
        }
    }

    private static function decode_quote_entities_str($s) {
        if (!is_string($s) || $s === '') return $s;
        $keys = ['&ldquo;','&rdquo;','&lsquo;','&rsquo;','&quot;','&apos;'];
        $vals = ['“','”','‘','’','"',"'"];
        for ($i = 0; $i < 3; $i++) {
            $prev = $s;
            $s = str_replace($keys, $vals, $s);
            $amp = array_map(function($k){ return '&amp;' . $k; }, $keys);
            $s = str_replace($amp, $vals, $s);
            if ($s === $prev) break;
        }
        return $s;
    }

    private static function fetch_image_to_temp($url) {
        $res = wp_remote_get($url, ['timeout' => 30, 'headers' => ['Referer' => home_url(), 'User-Agent' => 'WordPress WechatSync']]);
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        if ($code !== 200) return new WP_Error('wechat_sync_download', 'HTTP:' . intval($code) . ' URL:' . $url . ' ' . __('图片下载失败', WECHAT_SYNC_TEXTDOMAIN));
        $body = wp_remote_retrieve_body($res);
        if (!$body) return new WP_Error('wechat_sync_download', 'HTTP:' . intval($code) . ' URL:' . $url . ' ' . __('图片内容为空', WECHAT_SYNC_TEXTDOMAIN));
        $tmp = tempnam(get_temp_dir(), 'wximg_');
        if (!$tmp) return new WP_Error('wechat_sync_download', 'URL:' . $url . ' ' . __('临时文件创建失败', WECHAT_SYNC_TEXTDOMAIN));
        file_put_contents($tmp, $body);
        return $tmp;
    }

    private static function upload_thumb_from_url($url, $token) {
        $candidate = self::sanitize_candidate_url($url);
        if (!$candidate) return new WP_Error('wechat_sync_thumb', __('封面图不存在', WECHAT_SYNC_TEXTDOMAIN));
        $norm = $candidate;
        if (strpos($norm, '://') === false) {
            if (substr($norm, 0, 2) === '//') { $norm = (is_ssl() ? 'https:' : 'http:') . $norm; }
            else { $norm = (substr($norm, 0, 1) === '/') ? home_url($norm) : home_url('/' . ltrim($norm, '/')); }
        }
        $attachment_id = attachment_url_to_postid($norm);
        if ($attachment_id) {
            $t = self::upload_thumb($attachment_id, $token);
            return $t;
        }
        $tmp = self::fetch_image_to_temp($norm);
        if (is_wp_error($tmp)) return $tmp;
        if (!file_exists($tmp)) return new WP_Error('wechat_sync_thumb', __('上传失败', WECHAT_SYNC_TEXTDOMAIN));
        $mime = self::get_mime($tmp);
        if ($mime === 'image/svg+xml') { @unlink($tmp); return new WP_Error('wechat_sync_thumb', __('文件类型不支持，转换失败', WECHAT_SYNC_TEXTDOMAIN)); }
        $normed = self::normalize_image_for_upload($tmp, 'material');
        if (is_wp_error($normed)) { @unlink($tmp); return $normed; }
        $send = (is_string($normed) && file_exists($normed)) ? $normed : $tmp;
        $api = 'https://api.weixin.qq.com/cgi-bin/material/add_material?access_token=' . rawurlencode($token) . '&type=image';
        $res = self::multipart_request($api, $send, 'media');
        if (is_wp_error($res)) { if ($send !== $tmp && file_exists($send)) @unlink($send); @unlink($tmp); return $res; }
        $json = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($json) || empty($json['media_id'])) {
            $err = isset($json['errmsg']) ? $json['errmsg'] : __('上传失败', WECHAT_SYNC_TEXTDOMAIN);
            if ($send !== $tmp && file_exists($send)) @unlink($send);
            @unlink($tmp);
            return new WP_Error('wechat_sync_thumb', $err);
        }
        if ($send !== $tmp && file_exists($send)) @unlink($send);
        @unlink($tmp);
        return $json['media_id'];
    }

    private static function convert_webp_if_needed($file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext !== 'webp') return $file;
        if (function_exists('imagecreatefromwebp')) {
            $im = @imagecreatefromwebp($file);
            if ($im) {
                $tmp = tempnam(get_temp_dir(), 'wximg_');
                @imagejpeg($im, $tmp, 85);
                imagedestroy($im);
                if (file_exists($tmp)) return $tmp;
            }
        }
        if (class_exists('Imagick')) {
            try {
                $img = new Imagick($file);
                $img->setImageFormat('jpeg');
                $tmp = tempnam(get_temp_dir(), 'wximg_');
                $img->writeImage($tmp);
                $img->clear();
                $img->destroy();
                if (file_exists($tmp)) return $tmp;
            } catch (Exception $e) {}
        }
        return $file;
    }

    private static function clamp_text($text, $byte_limit) {
        if (!is_string($text)) $text = '';
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/[\x{1F000}-\x{1FFFF}\x{20000}-\x{2FFFF}]/u', '', $text);
        $text = preg_replace('/[\x00-\x1F\x7F]/', '', $text);
        $text = trim($text);
        if (function_exists('mb_strcut')) return mb_strcut($text, 0, $byte_limit, 'UTF-8');
        return substr($text, 0, $byte_limit);
    }
}
