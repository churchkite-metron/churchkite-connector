<?php
/**
 * Plugin Name: ChurchKite Connector
 * Description: Registers and verifies the site with ChurchKite Admin and reports plugin inventory + heartbeats.
 * Version: 0.1.0
 * Author: ChurchKite
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
