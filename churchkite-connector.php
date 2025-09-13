<?php
/**
 * Plugin Name: ChurchKite Connector
 * Description: Registers and verifies the site with ChurchKite Admin and reports plugin inventory + heartbeats.
 * Version: 0.1.4
 * Author: ChurchKite
 * Update URI: https://github.com/churchkite-metron/churchkite-connector
 */

if (!defined('ABSPATH')) exit;

if (!defined('CHURCHKITE_ADMIN_URL')) {
    define('CHURCHKITE_ADMIN_URL', 'https://churchkite-plugin-admin.netlify.app');
}

register_activation_hook(__FILE__, 'ckc_on_activation');
register_deactivation_hook(__FILE__, 'ckc_on_deactivation');
add_action('rest_api_init', 'ckc_register_proof_route');
add_action('ckc_daily_heartbeat', 'ckc_heartbeat');
// Refresh inventory on plugin changes
add_action('upgrader_process_complete', function($upgrader, $hook_extra) {
    if (isset($hook_extra['type']) && $hook_extra['type'] === 'plugin') {
        ckc_send_inventory();
    }
}, 10, 2);
add_action('activated_plugin', 'ckc_send_inventory', 20, 0);
add_action('deactivated_plugin', 'ckc_send_inventory', 20, 0);

function ckc_get_token() {
    $t = get_option('churchkite_registration_token', '');
    if (!$t) {
        $t = wp_generate_password(40, false, false);
        update_option('churchkite_registration_token', $t, false);
    }
    return $t;
}

function ckc_endpoint($path) {
    $base = rtrim(CHURCHKITE_ADMIN_URL, '/');
    return $base . '/api/registry' . $path;
}

function ckc_register_proof_route() {
    register_rest_route('churchkite/v1', '/proof', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function (\WP_REST_Request $req) {
            $token = $req->get_param('token');
            $saved = get_option('churchkite_registration_token', '');
            $ok = is_string($token) && $token !== '' && hash_equals($saved, $token);
            return new \WP_REST_Response(array('ok' => $ok, 'siteUrl' => get_site_url()), $ok ? 200 : 400);
        }
    ));
}

function ckc_collect_inventory() {
    if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    if (!function_exists('is_plugin_active')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plugins = get_plugins();
    $active = get_option('active_plugins', array());
    $updates = get_site_transient('update_plugins');
    $updatesResp = is_object($updates) && isset($updates->response) ? $updates->response : array();

    $out = array();
    foreach ($plugins as $file => $data) {
        $slug = dirname($file);
        $item = array(
            'slug' => $slug,
            'name' => isset($data['Name']) ? $data['Name'] : $slug,
            'version' => isset($data['Version']) ? $data['Version'] : '',
            'active' => in_array($file, $active, true),
        );
        if (isset($updatesResp[$file])) {
            $item['updateAvailable'] = true;
            $item['newVersion'] = isset($updatesResp[$file]->new_version) ? $updatesResp[$file]->new_version : null;
        }
        $out[] = $item;
    }
    /**
     * Allow plugins to contribute extra metadata.
     */
    $manifest = apply_filters('churchkite_connector_manifest', array());
    if (is_array($manifest)) {
        foreach ($out as &$p) {
            if (isset($manifest[$p['slug']]) && is_array($manifest[$p['slug']])) {
                $p = array_merge($p, $manifest[$p['slug']]);
            }
        }
    }
    return $out;
}

function ckc_post($path, $body) {
    $url = ckc_endpoint($path);
    return wp_remote_post($url, array(
        'headers' => array('Content-Type' => 'application/json'),
        'body' => wp_json_encode($body),
        'timeout' => 15,
    ));
}

function ckc_register() {
    $token = ckc_get_token();
    $proof = rest_url('churchkite/v1/proof');
    ckc_post('/register', array(
        'siteUrl' => get_site_url(),
        'pluginSlug' => 'churchkite-connector',
        'wpVersion' => get_bloginfo('version'),
        'phpVersion' => PHP_VERSION,
        'token' => $token,
        'proofEndpoint' => $proof,
    ));
    ckc_send_inventory();
}

function ckc_send_inventory() {
    $token = ckc_get_token();
    $proof = rest_url('churchkite/v1/proof');
    ckc_post('/inventory', array(
        'siteUrl' => get_site_url(),
        'wpVersion' => get_bloginfo('version'),
        'phpVersion' => PHP_VERSION,
        'token' => $token,
        'proofEndpoint' => $proof,
        'plugins' => ckc_collect_inventory(),
    ));
}

function ckc_heartbeat() {
    $token = ckc_get_token();
    $proof = rest_url('churchkite/v1/proof');
    ckc_post('/heartbeat', array(
        'siteUrl' => get_site_url(),
        'pluginSlug' => 'churchkite-connector',
        'wpVersion' => get_bloginfo('version'),
        'phpVersion' => PHP_VERSION,
        'token' => $token,
        'proofEndpoint' => $proof,
    ));
}

function ckc_on_activation() {
    ckc_register();
    if (!wp_next_scheduled('ckc_daily_heartbeat')) {
        wp_schedule_event(time() + 300, 'daily', 'ckc_daily_heartbeat');
    }
}

function ckc_on_deactivation() {
    wp_clear_scheduled_hook('ckc_daily_heartbeat');
    ckc_post('/deregister', array(
        'siteUrl' => get_site_url(),
        'pluginSlug' => 'churchkite-connector',
    ));
}

// --- Self-update via GitHub Releases ---
add_filter('pre_set_site_transient_update_plugins', 'ckc_check_for_update');
add_filter('plugins_api', 'ckc_plugins_api', 10, 3);

function ckc_get_plugin_version() {
    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $data = get_plugin_data(__FILE__, false, false);
    return isset($data['Version']) ? $data['Version'] : '0.0.0';
}

function ckc_latest_release() {
    $cache_key = 'ckc_latest_release_info';
    $cached = get_site_transient($cache_key);
    if ($cached && is_array($cached)) return $cached;
    $url = 'https://api.github.com/repos/churchkite-metron/churchkite-connector/releases/latest';
    $resp = wp_remote_get($url, array('headers' => array('User-Agent' => 'ChurchKite/Connector')));
    if (is_wp_error($resp)) return null;
    $code = wp_remote_retrieve_response_code($resp);
    if ($code !== 200) return null;
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($data) || empty($data['tag_name'])) return null;
    $tag = $data['tag_name'];
    $tag = ltrim($tag, 'vV');
    $zip = '';
    if (!empty($data['assets']) && is_array($data['assets'])) {
        foreach ($data['assets'] as $asset) {
            if (!empty($asset['name']) && $asset['name'] === 'churchkite-connector.zip' && !empty($asset['browser_download_url'])) {
                $zip = $asset['browser_download_url'];
                break;
            }
        }
    }
    if (!$zip) {
        $zip = isset($data['zipball_url']) ? $data['zipball_url'] : ('https://github.com/churchkite-metron/churchkite-connector/archive/refs/tags/' . $data['tag_name'] . '.zip');
    }
    $out = array(
        'version' => $tag,
        'zip' => $zip,
        'url' => isset($data['html_url']) ? $data['html_url'] : 'https://github.com/churchkite-metron/churchkite-connector',
        'changelog' => isset($data['body']) ? $data['body'] : '',
    );
    set_site_transient($cache_key, $out, HOUR_IN_SECONDS);
    return $out;
}

function ckc_check_for_update($transient) {
    if (empty($transient) || !is_object($transient)) return $transient;
    $plugin_file = plugin_basename(__FILE__);
    $current = ckc_get_plugin_version();
    $latest = ckc_latest_release();
    if (!$latest || empty($latest['version'])) return $transient;
    if (version_compare($latest['version'], $current, '>')) {
        $update = (object) array(
            'slug' => 'churchkite-connector',
            'plugin' => $plugin_file,
            'new_version' => $latest['version'],
            'url' => $latest['url'],
            'package' => $latest['zip'],
        );
        $transient->response[$plugin_file] = $update;
    }
    return $transient;
}

function ckc_plugins_api($result, $action, $args) {
    if ($action !== 'plugin_information') return $result;
    if (!isset($args->slug) || $args->slug !== 'churchkite-connector') return $result;
    $current = ckc_get_plugin_version();
    $latest = ckc_latest_release();
    $info = (object) array(
        'name' => 'ChurchKite Connector',
        'slug' => 'churchkite-connector',
        'version' => $latest && !empty($latest['version']) ? $latest['version'] : $current,
        'author' => '<a href="https://github.com/churchkite-metron">ChurchKite</a>',
        'homepage' => 'https://github.com/churchkite-metron/churchkite-connector',
        'sections' => array(
            'description' => 'Registers/verifies the site with ChurchKite Admin and reports plugin inventory and heartbeats.',
            'changelog' => $latest && !empty($latest['changelog']) ? wp_kses_post(nl2br($latest['changelog'])) : 'See GitHub releases.',
        ),
        'download_link' => $latest ? $latest['zip'] : '',
    );
    return $info;
}
