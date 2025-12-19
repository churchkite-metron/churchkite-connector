<?php
/**
 * Plugin Name: ChurchKite Connector
 * Description: Registers and verifies the site with ChurchKite Admin and reports plugin inventory + heartbeats.
 * Version: 0.4.5
 * Author: ChurchKite
 * Update URI: churchkite://churchkite-connector
 */

if (!defined('ABSPATH')) exit;
// Trivial change to trigger release test

if (!defined('CHURCHKITE_ADMIN_URL')) {
    define('CHURCHKITE_ADMIN_URL', 'https://phpstack-962122-6023915.cloudwaysapps.com');
}

if (!defined('CHURCHKITE_REGISTRY_KEY')) {
    define('CHURCHKITE_REGISTRY_KEY', '');
}

if (!defined('CKC_INVENTORY_RETRY_HOOK')) {
    define('CKC_INVENTORY_RETRY_HOOK', 'ckc_inventory_retry');
}

if (!defined('CKC_INVENTORY_RETRY_OPTION')) {
    define('CKC_INVENTORY_RETRY_OPTION', 'ckc_inventory_retry_attempts');
}

if (!defined('CKC_INVENTORY_RETRY_MAX_ATTEMPTS')) {
    define('CKC_INVENTORY_RETRY_MAX_ATTEMPTS', 5);
}

register_activation_hook(__FILE__, 'ckc_on_activation');
register_deactivation_hook(__FILE__, 'ckc_on_deactivation');
add_action('rest_api_init', 'ckc_register_proof_route');
add_action('rest_api_init', function() {
    register_rest_route('churchkite/v1', '/clear-release-cache', array(
        'methods' => 'POST',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
        'callback' => function() {
            delete_site_transient('ckc_latest_release_info');
            return new WP_REST_Response(array('ok' => true, 'message' => 'Release cache cleared'), 200);
        }
    ));
});
add_action('ckc_daily_heartbeat', 'ckc_heartbeat');
add_action(CKC_INVENTORY_RETRY_HOOK, 'ckc_inventory_retry_handler'); // Retry inventory sync until verification succeeds
// Refresh inventory on plugin/theme changes
add_action('upgrader_process_complete', function($upgrader, $hook_extra) {
    if (!isset($hook_extra['type'])) return;
    if ($hook_extra['type'] === 'plugin') {
        ckc_clear_release_cache();
        ckc_send_inventory();
    }
    if ($hook_extra['type'] === 'theme') {
        ckc_send_inventory();
    }
}, 10, 2);
add_action('activated_plugin', function() {
    ckc_clear_release_cache();
    ckc_send_inventory();
}, 20, 0);
add_action('deactivated_plugin', function() {
    ckc_clear_release_cache();
    ckc_send_inventory();
}, 20, 0);
add_action('switch_theme', function() {
    ckc_send_inventory();
}, 20, 0);

// Add admin debug page under Tools
add_action('admin_menu', 'ckc_add_debug_page');

function ckc_add_debug_page() {
    add_management_page(
        'ChurchKite Debug',
        'ChurchKite Debug',
        'manage_options',
        'churchkite-debug',
        'ckc_render_debug_page'
    );
}

function ckc_render_debug_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    echo '<div class="wrap">';
    echo '<h1>ChurchKite Connector Debug</h1>';
    
    // Handle test actions
    if (isset($_POST['ckc_test_register']) && check_admin_referer('ckc_debug_action')) {
        echo '<div class="notice notice-info"><h2>Testing Registration...</h2>';
        $response = ckc_register();
        echo '<pre>';
        echo 'Response Code: ' . esc_html(wp_remote_retrieve_response_code($response)) . "\n";
        echo 'Response Body: ' . esc_html(wp_remote_retrieve_body($response)) . "\n";
        if (is_wp_error($response)) {
            echo 'Error: ' . esc_html($response->get_error_message());
        }
        echo '</pre></div>';
    }
    
    if (isset($_POST['ckc_test_inventory']) && check_admin_referer('ckc_debug_action')) {
        echo '<div class="notice notice-info"><h2>Testing Inventory...</h2>';
        ckc_send_inventory();
        echo '<p>Inventory sent. Check your admin server at <a href="' . esc_url(CHURCHKITE_ADMIN_URL . '/inventory') . '" target="_blank">Inventory Page</a></p>';
        echo '</div>';
    }
    
    if (isset($_POST['ckc_test_heartbeat']) && check_admin_referer('ckc_debug_action')) {
        echo '<div class="notice notice-info"><h2>Testing Heartbeat...</h2>';
        $response = ckc_heartbeat();
        echo '<pre>';
        echo 'Response Code: ' . esc_html(wp_remote_retrieve_response_code($response)) . "\n";
        echo 'Response Body: ' . esc_html(wp_remote_retrieve_body($response)) . "\n";
        if (is_wp_error($response)) {
            echo 'Error: ' . esc_html($response->get_error_message());
        }
        echo '</pre></div>';
    }
    
    // Display current settings
    echo '<h2>Current Configuration</h2>';
    echo '<table class="widefat" style="max-width: 800px;"><tbody>';
    echo '<tr><td style="width: 200px;"><strong>Admin Server URL</strong></td><td>' . esc_html(CHURCHKITE_ADMIN_URL) . '</td></tr>';
    echo '<tr><td><strong>Site URL</strong></td><td>' . esc_html(home_url()) . '</td></tr>';
    echo '<tr><td><strong>Site Title</strong></td><td>' . esc_html(get_bloginfo('name')) . '</td></tr>';
    echo '<tr><td><strong>Registration Token</strong></td><td><code>' . esc_html(ckc_get_token()) . '</code></td></tr>';
    echo '<tr><td><strong>Proof Endpoint</strong></td><td>' . esc_html(rest_url('churchkite/v1/proof')) . '</td></tr>';
    echo '<tr><td><strong>Registry Key Set</strong></td><td>' . (CHURCHKITE_REGISTRY_KEY ? 'Yes' : 'No') . '</td></tr>';
    echo '<tr><td><strong>Plugin Version</strong></td><td>' . esc_html(ckc_get_plugin_version()) . '</td></tr>';
    echo '</tbody></table>';

    // Update debug for connector itself
    echo '<h2>Update Debug</h2>';
    $slug = 'churchkite-connector';
    $info = ckc_ck_check($slug);
    $pkg = ckc_ck_download_url($slug, $info);
    $adminDownload = (is_array($info) && isset($info['download']) && is_string($info['download'])) ? trim($info['download']) : '';
    $adminHost = wp_parse_url(ckc_admin_base(), PHP_URL_HOST);
    $blockExternal = defined('WP_HTTP_BLOCK_EXTERNAL') ? var_export(WP_HTTP_BLOCK_EXTERNAL, true) : '(not defined)';
    $accessibleHosts = defined('WP_ACCESSIBLE_HOSTS') ? (string) WP_ACCESSIBLE_HOSTS : '(not defined)';

    echo '<table class="widefat" style="max-width: 800px;"><tbody>';
    echo '<tr><td style="width: 200px;"><strong>Admin host</strong></td><td><code>' . esc_html($adminHost ?: '(unknown)') . '</code></td></tr>';
    echo '<tr><td><strong>WP_HTTP_BLOCK_EXTERNAL</strong></td><td><code>' . esc_html($blockExternal) . '</code></td></tr>';
    echo '<tr><td><strong>WP_ACCESSIBLE_HOSTS</strong></td><td><code>' . esc_html($accessibleHosts) . '</code></td></tr>';
    echo '<tr><td style="width: 200px;"><strong>Admin check JSON</strong></td><td><pre style="white-space:pre-wrap;">' . esc_html(wp_json_encode($info, JSON_PRETTY_PRINT)) . '</pre></td></tr>';
    echo '<tr><td><strong>Admin download</strong></td><td><code>' . esc_html($adminDownload ?: '(none)') . '</code></td></tr>';
    echo '<tr><td><strong>Computed package</strong></td><td><code>' . esc_html($pkg ?: '(empty)') . '</code></td></tr>';
    echo '<tr><td><strong>wp_http_validate_url()</strong></td><td><code>' . esc_html(var_export((bool) wp_http_validate_url($pkg), true)) . '</code></td></tr>';

    $headStatus = '';
    $head = $pkg ? wp_remote_head($pkg, array('timeout' => 15, 'redirection' => 3)) : null;
    if (!$pkg) {
        $headStatus = '(no package URL)';
    } elseif (is_wp_error($head)) {
        $headStatus = $head->get_error_message();
    } else {
        $headStatus = (string) wp_remote_retrieve_response_code($head);
    }
    echo '<tr><td><strong>HEAD status</strong></td><td><code>' . esc_html($headStatus) . '</code></td></tr>';

    $safeHeadStatus = '';
    if (!$pkg) {
        $safeHeadStatus = '(no package URL)';
    } elseif (!function_exists('wp_safe_remote_head')) {
        $safeHeadStatus = '(wp_safe_remote_head unavailable)';
    } else {
        $safeHead = wp_safe_remote_head($pkg, array('timeout' => 15, 'redirection' => 3));
        if (is_wp_error($safeHead)) {
            $safeHeadStatus = $safeHead->get_error_message();
        } else {
            $safeHeadStatus = (string) wp_remote_retrieve_response_code($safeHead);
        }
    }
    echo '<tr><td><strong>Safe HEAD status</strong></td><td><code>' . esc_html($safeHeadStatus) . '</code></td></tr>';
    echo '</tbody></table>';
    
    // Display ChurchKite-managed plugins
    echo '<h2>ChurchKite-Managed Plugins</h2>';
    $ck_plugins = ckc_scan_ck_managed();
    if (empty($ck_plugins)) {
        echo '<p>No ChurchKite-managed plugins found.</p>';
    } else {
        echo '<table class="widefat" style="max-width: 800px;"><thead><tr>';
        echo '<th>Plugin</th><th>Version</th><th>Update URI</th>';
        echo '</tr></thead><tbody>';
        foreach ($ck_plugins as $plugin) {
            echo '<tr>';
            echo '<td>' . esc_html($plugin['name']) . '</td>';
            echo '<td>' . esc_html($plugin['version']) . '</td>';
            echo '<td><code>' . esc_html($plugin['update_uri']) . '</code></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    
    echo '<h2>ChurchKite-Managed Themes</h2>';
    $ck_themes = ckc_scan_ck_managed_themes();
    if (empty($ck_themes)) {
        echo '<p>No ChurchKite-managed themes found.</p>';
    } else {
        echo '<table class="widefat" style="max-width: 800px;"><thead><tr>';
        echo '<th>Theme</th><th>Version</th><th>Update URI</th>';
        echo '</tr></thead><tbody>';
        foreach ($ck_themes as $theme) {
            echo '<tr>';
            echo '<td>' . esc_html($theme['name']) . '</td>';
            echo '<td>' . esc_html($theme['version']) . '</td>';
            echo '<td><code>churchkite://' . esc_html($theme['slug']) . '</code></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    // Test action buttons
    echo '<h2>Test Actions</h2>';
    echo '<p>Use these buttons to manually trigger connector actions and see the responses.</p>';
    
    echo '<form method="post" style="display:inline-block; margin-right:10px;">';
    wp_nonce_field('ckc_debug_action');
    echo '<input type="hidden" name="ckc_test_register" value="1">';
    submit_button('Test Registration', 'primary', 'submit', false);
    echo '</form>';
    
    echo '<form method="post" style="display:inline-block; margin-right:10px;">';
    wp_nonce_field('ckc_debug_action');
    echo '<input type="hidden" name="ckc_test_inventory" value="1">';
    submit_button('Test Inventory', 'secondary', 'submit', false);
    echo '</form>';
    
    echo '<form method="post" style="display:inline-block;">';
    wp_nonce_field('ckc_debug_action');
    echo '<input type="hidden" name="ckc_test_heartbeat" value="1">';
    submit_button('Test Heartbeat', 'secondary', 'submit', false);
    echo '</form>';
    
    echo '</div>';
}

function ckc_get_token() {
    $t = get_option('churchkite_registration_token', '');
    if (!$t) {
        $t = wp_generate_password(40, false, false);
        update_option('churchkite_registration_token', $t, false);
    }
    return $t;
}

function ckc_site_title() {
    return get_bloginfo('name');
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
            // Include site title so Admin can display a friendly name
            return new \WP_REST_Response(array(
                'ok' => $ok,
                'siteUrl' => get_site_url(),
                'siteTitle' => get_bloginfo('name'),
            ), $ok ? 200 : 400);
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
            'updateUri' => isset($data['UpdateURI']) ? trim($data['UpdateURI']) : '',
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

function ckc_collect_themes() {
    if (!function_exists('wp_get_themes')) require_once ABSPATH . 'wp-includes/theme.php';
    $themes = wp_get_themes();
    $active = wp_get_theme();
    $active_slug = $active ? $active->get_stylesheet() : '';
    $updates = get_site_transient('update_themes');
    $updatesResp = is_object($updates) && isset($updates->response) ? $updates->response : array();

    $out = array();
    foreach ($themes as $slug => $theme) {
        $parent = $theme->parent();
        $item = array(
            'slug' => $slug,
            'name' => $theme->get('Name') ?: $slug,
            'version' => $theme->get('Version'),
            'active' => strtolower($slug) === strtolower($active_slug),
            'parent' => $parent ? $parent->get_stylesheet() : '',
            'updateUri' => trim((string) $theme->get('UpdateURI')),
        );
        if (isset($updatesResp[$slug])) {
            $item['updateAvailable'] = true;
            $item['newVersion'] = isset($updatesResp[$slug]['new_version']) ? $updatesResp[$slug]['new_version'] : null;
        }
        $out[] = $item;
    }

    return $out;
}

function ckc_post($path, $body) {
    $url = ckc_endpoint($path);
    $headers = array('Content-Type' => 'application/json');
    
    // Add authentication for registry endpoints
    if (strpos($path, '/register') === 0 || strpos($path, '/heartbeat') === 0 || 
        strpos($path, '/inventory') === 0 || strpos($path, '/deregister') === 0) {
        $key = defined('CHURCHKITE_REGISTRY_KEY') ? CHURCHKITE_REGISTRY_KEY : '';
        if ($key) {
            $headers['x-registration-key'] = $key;
        }
    }
    
    return wp_remote_post($url, array(
        'headers' => $headers,
        'body' => wp_json_encode($body),
        'timeout' => 15,
    ));
}

function ckc_register() {
    $token = ckc_get_token();
    $proof = rest_url('churchkite/v1/proof');
    $response = ckc_post('/register', array(
        'siteUrl' => get_site_url(),
        'siteTitle' => ckc_site_title(),
        'pluginSlug' => 'churchkite-connector',
        'wpVersion' => get_bloginfo('version'),
        'phpVersion' => PHP_VERSION,
        'token' => $token,
        'proofEndpoint' => $proof,
    ));
    ckc_send_inventory();
    return $response;
}

function ckc_send_inventory($is_retry = false) {
    $token = ckc_get_token();
    $proof = rest_url('churchkite/v1/proof');
    $response = ckc_post('/inventory', array(
        'siteUrl' => get_site_url(),
        'siteTitle' => ckc_site_title(),
        'wpVersion' => get_bloginfo('version'),
        'phpVersion' => PHP_VERSION,
        'token' => $token,
        'proofEndpoint' => $proof,
        'plugins' => ckc_collect_inventory(),
        'themes' => ckc_collect_themes(),
    ));

    if (!$is_retry) {
        if (ckc_is_inventory_success($response)) {
            ckc_finish_inventory_retry();
        } else {
            ckc_start_inventory_retry_cycle();
        }
    }

    return $response;
}

function ckc_is_inventory_success($response) {
    if (is_wp_error($response)) {
        return false;
    }
    $code = wp_remote_retrieve_response_code($response);
    return is_numeric($code) && $code >= 200 && $code < 300;
}

function ckc_start_inventory_retry_cycle($delay = 60) {
    if (!function_exists('wp_schedule_single_event')) {
        return;
    }
    $delay = max(30, (int) $delay);
    ckc_finish_inventory_retry();
    update_option(CKC_INVENTORY_RETRY_OPTION, 0, false);
    wp_schedule_single_event(time() + $delay, CKC_INVENTORY_RETRY_HOOK);
}

function ckc_finish_inventory_retry($unschedule = true) {
    if ($unschedule) {
        ckc_unschedule_inventory_retry();
    }
    delete_option(CKC_INVENTORY_RETRY_OPTION);
}

function ckc_unschedule_inventory_retry() {
    if (!function_exists('wp_next_scheduled') || !function_exists('wp_unschedule_event')) {
        return;
    }
    $timestamp = wp_next_scheduled(CKC_INVENTORY_RETRY_HOOK);
    while ($timestamp) {
        wp_unschedule_event($timestamp, CKC_INVENTORY_RETRY_HOOK);
        $timestamp = wp_next_scheduled(CKC_INVENTORY_RETRY_HOOK);
    }
}

function ckc_inventory_retry_delay_for_attempt($attempt) {
    $attempt = max(1, (int) $attempt);
    $delay = $attempt * 60; // back off in 1-minute increments
    return (int) min(900, max(60, $delay));
}

function ckc_inventory_retry_handler() {
    $attempts = (int) get_option(CKC_INVENTORY_RETRY_OPTION, 0);
    $attempts++;
    update_option(CKC_INVENTORY_RETRY_OPTION, $attempts, false);

    $response = ckc_send_inventory(true);

    if (ckc_is_inventory_success($response)) {
        ckc_finish_inventory_retry();
        return;
    }

    if ($attempts >= CKC_INVENTORY_RETRY_MAX_ATTEMPTS) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $message = is_wp_error($response)
                ? $response->get_error_message()
                : sprintf('Last response code: %s', wp_remote_retrieve_response_code($response));
            error_log(sprintf('[ChurchKite Connector] Inventory sync failed after %d attempts. %s', $attempts, $message));
        }
        ckc_finish_inventory_retry();
        return;
    }

    $delay = ckc_inventory_retry_delay_for_attempt($attempts);
    wp_schedule_single_event(time() + $delay, CKC_INVENTORY_RETRY_HOOK);
}

function ckc_heartbeat() {
    $token = ckc_get_token();
    $proof = rest_url('churchkite/v1/proof');
    return ckc_post('/heartbeat', array(
        'siteUrl' => get_site_url(),
        'siteTitle' => ckc_site_title(),
        'pluginSlug' => 'churchkite-connector',
        'wpVersion' => get_bloginfo('version'),
        'phpVersion' => PHP_VERSION,
        'token' => $token,
        'proofEndpoint' => $proof,
    ));
}

function ckc_clear_release_cache() {
    delete_site_transient('ckc_latest_release_info');
}

function ckc_on_activation() {
    ckc_clear_release_cache();
    ckc_finish_inventory_retry();
    ckc_register();
    if (!wp_next_scheduled('ckc_daily_heartbeat')) {
        wp_schedule_event(time() + 300, 'daily', 'ckc_daily_heartbeat');
    }
}

function ckc_on_deactivation() {
    ckc_clear_release_cache();
    ckc_finish_inventory_retry();
    wp_clear_scheduled_hook('ckc_daily_heartbeat');
    ckc_post('/deregister', array(
        'siteUrl' => get_site_url(),
        'siteTitle' => ckc_site_title(),
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

// --- Centralized updater for ChurchKite-managed and GitHub-declared plugins ---
add_filter('pre_set_site_transient_update_plugins', 'ckc_ck_managed_updates', 12);
add_filter('plugins_api', 'ckc_ck_plugins_api', 12, 3);
// --- ChurchKite-managed themes ---
add_filter('pre_set_site_transient_update_themes', 'ckc_ck_managed_theme_updates', 12);
add_filter('themes_api', 'ckc_ck_themes_api', 12, 3);

// Allow WordPress "safe" HTTP requests to the configured ChurchKite Admin host.
// This prevents update downloads failing with "A valid URL was not provided" when
// a site blocks external HTTP requests via WP_HTTP_BLOCK_EXTERNAL.
add_filter('http_request_host_is_external', 'ckc_allow_admin_http_host', 10, 2);

function ckc_allow_admin_http_host($is_external, $host) {
    $adminHost = wp_parse_url(ckc_admin_base(), PHP_URL_HOST);
    if (is_string($adminHost) && $adminHost !== '' && is_string($host) && $host !== '') {
        if (strtolower($host) === strtolower($adminHost)) {
            return true;
        }
    }
    return $is_external;
}

function ckc_admin_base() {
    return defined('CHURCHKITE_ADMIN_URL') ? rtrim(CHURCHKITE_ADMIN_URL, '/') : 'https://phpstack-962122-6023915.cloudwaysapps.com';
}

function ckc_scan_ck_managed() {
    if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plugins = get_plugins();
    $out = array();
    foreach ($plugins as $file => $data) {
        $uri = isset($data['UpdateURI']) ? trim($data['UpdateURI']) : '';
        if ($uri && stripos($uri, 'churchkite://') === 0) {
            $slug = substr($uri, strlen('churchkite://'));
            $out[$slug] = array(
                'file'    => $file,
                'slug'    => $slug,
                'name'    => isset($data['Name']) ? $data['Name'] : $slug,
                'version' => isset($data['Version']) ? $data['Version'] : '',
                'update_uri' => $uri,
            );
        }
    }
    return $out;
}

function ckc_scan_ck_managed_themes() {
    if (!function_exists('wp_get_themes')) require_once ABSPATH . 'wp-includes/theme.php';
    $themes = wp_get_themes();
    $out = array();
    foreach ($themes as $slug => $theme) {
        $uri = trim((string) $theme->get('UpdateURI'));
        if ($uri && stripos($uri, 'churchkite://') === 0) {
            $out[$slug] = array(
                'slug'    => $slug,
                'name'    => $theme->get('Name') ?: $slug,
                'version' => $theme->get('Version'),
                'update_uri' => $uri,
            );
        }
    }
    return $out;
}

function ckc_ck_check($slug) {
    $url = ckc_admin_base() . '/api/updates/check?slug=' . rawurlencode($slug);
    $resp = wp_remote_get($url, array('timeout' => 15, 'headers' => array('User-Agent' => 'ChurchKite/Connector')));
    if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) return null;
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    return is_array($data) ? $data : null;
}

function ckc_ck_download_url($slug, $info = null) {
    $base = ckc_admin_base();
    $fallback = $base . '/api/updates/download?slug=' . rawurlencode($slug);

    $download = '';
    if (is_array($info) && isset($info['download']) && is_string($info['download'])) {
        $download = trim($info['download']);
    }

    if ($download === '') {
        return esc_url_raw($fallback);
    }

    // Relative URL -> make absolute.
    if (strpos($download, '/') === 0) {
        $download = $base . $download;
    }

    $safe = esc_url_raw($download);
    if (!$safe) {
        return esc_url_raw($fallback);
    }

    return $safe;
}

function ckc_ck_managed_updates($transient) {
    if (empty($transient) || !is_object($transient)) return $transient;
    $managed = ckc_scan_ck_managed();
    foreach ($managed as $slug => $p) {
        $info = ckc_ck_check($slug);
        if (!$info || empty($info['version'])) continue;
        if (version_compare($info['version'], $p['version'], '>')) {
            $pkg = ckc_ck_download_url($slug, $info);
            $transient->response[$p['file']] = (object) array(
                'slug'        => $slug,
                'plugin'      => $p['file'],
                'new_version' => $info['version'],
                'url'         => isset($info['url']) ? $info['url'] : '',
                'package'     => $pkg,
            );
        }
    }
    return $transient;
}

function ckc_ck_managed_theme_updates($transient) {
    if (empty($transient) || !is_object($transient)) return $transient;
    if (!isset($transient->response) || !is_array($transient->response)) {
        $transient->response = array();
    }
    $managed = ckc_scan_ck_managed_themes();
    foreach ($managed as $slug => $t) {
        $info = ckc_ck_check($slug);
        if (!$info || empty($info['version'])) continue;
        if (version_compare($info['version'], $t['version'], '>')) {
            $pkg = ckc_ck_download_url($slug, $info);
            $transient->response[$slug] = array(
                'theme'       => $slug,
                'new_version' => $info['version'],
                'url'         => isset($info['url']) ? $info['url'] : '',
                'package'     => $pkg,
            );
        }
    }
    return $transient;
}

function ckc_ck_plugins_api($result, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug)) return $result;
    $managed = ckc_scan_ck_managed();
    if (!isset($managed[$args->slug])) return $result;
    $p = $managed[$args->slug];
    $info = ckc_ck_check($args->slug);
    $version = $info && !empty($info['version']) ? $info['version'] : $p['version'];
    $pkg = ckc_ck_download_url($args->slug, $info);
    return (object) array(
        'name'         => $p['name'],
        'slug'         => $args->slug,
        'version'      => $version,
        'author'       => '<a href="https://github.com/churchkite-metron">ChurchKite</a>',
        'sections'     => array(
            'description' => 'Managed by ChurchKite',
            'changelog'   => isset($info['changelog']) ? wp_kses_post(nl2br($info['changelog'])) : '',
        ),
        'download_link'=> $pkg,
        'homepage'     => isset($info['url']) ? $info['url'] : '',
    );
}

function ckc_ck_themes_api($result, $action, $args) {
    if ($action !== 'theme_information' || empty($args->slug)) return $result;
    $managed = ckc_scan_ck_managed_themes();
    if (!isset($managed[$args->slug])) return $result;
    $t = $managed[$args->slug];
    $info = ckc_ck_check($args->slug);
    $version = $info && !empty($info['version']) ? $info['version'] : $t['version'];
    $pkg = ckc_ck_download_url($args->slug, $info);
    return (object) array(
        'name'         => $t['name'],
        'slug'         => $args->slug,
        'version'      => $version,
        'author'       => '<a href="https://github.com/churchkite-metron">ChurchKite</a>',
        'sections'     => array(
            'description' => 'Managed by ChurchKite',
            'changelog'   => isset($info['changelog']) ? wp_kses_post(nl2br($info['changelog'])) : '',
        ),
        'download_link'=> $pkg,
        'homepage'     => isset($info['url']) ? $info['url'] : '',
        'requires'     => isset($info['requires']) ? $info['requires'] : '',
        'requires_php' => isset($info['requires_php']) ? $info['requires_php'] : '',
    );
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
    // Prefer Admin-managed updates for the connector
    $adminInfo = ckc_ck_check('churchkite-connector');
    if (is_array($adminInfo) && !empty($adminInfo['version']) && version_compare($adminInfo['version'], $current, '>')) {
        $pkg = ckc_ck_download_url('churchkite-connector', $adminInfo);
        $transient->response[$plugin_file] = (object) array(
            'slug'        => 'churchkite-connector',
            'plugin'      => $plugin_file,
            'new_version' => $adminInfo['version'],
            'url'         => isset($adminInfo['url']) ? $adminInfo['url'] : '',
            'package'     => $pkg,
        );
        return $transient;
    }
    // Fallback to GitHub latest release if Admin not available
    $latest = ckc_latest_release();
    if ($latest && !empty($latest['version']) && version_compare($latest['version'], $current, '>')) {
        $transient->response[$plugin_file] = (object) array(
            'slug'        => 'churchkite-connector',
            'plugin'      => $plugin_file,
            'new_version' => $latest['version'],
            'url'         => $latest['url'],
            'package'     => $latest['zip'],
        );
    }
    return $transient;
}

function ckc_plugins_api($result, $action, $args) {
    if ($action !== 'plugin_information') return $result;
    if (!isset($args->slug) || $args->slug !== 'churchkite-connector') return $result;
    $current = ckc_get_plugin_version();
    $adminInfo = ckc_ck_check('churchkite-connector');
    if (is_array($adminInfo) && !empty($adminInfo['version'])) {
        $pkg = ckc_ck_download_url('churchkite-connector', $adminInfo);
        return (object) array(
            'name'         => 'ChurchKite Connector',
            'slug'         => 'churchkite-connector',
            'version'      => $adminInfo['version'],
            'author'       => '<a href="https://github.com/churchkite-metron">ChurchKite</a>',
            'sections'     => array(
                'description' => 'Registers/verifies the site with ChurchKite Admin and reports plugin inventory and heartbeats.',
                'changelog'   => isset($adminInfo['changelog']) ? wp_kses_post(nl2br($adminInfo['changelog'])) : '',
            ),
            'download_link'=> $pkg,
            'homepage'     => isset($adminInfo['url']) ? $adminInfo['url'] : 'https://github.com/churchkite-metron/churchkite-connector',
        );
    }
    $latest = ckc_latest_release();
    return (object) array(
        'name'         => 'ChurchKite Connector',
        'slug'         => 'churchkite-connector',
        'version'      => $latest && !empty($latest['version']) ? $latest['version'] : $current,
        'author'       => '<a href="https://github.com/churchkite-metron">ChurchKite</a>',
        'sections'     => array(
            'description' => 'Registers/verifies the site with ChurchKite Admin and reports plugin inventory and heartbeats.',
            'changelog'   => $latest && !empty($latest['changelog']) ? wp_kses_post(nl2br($latest['changelog'])) : 'See GitHub releases.',
        ),
        'download_link'=> $latest ? $latest['zip'] : '',
        'homepage'     => 'https://github.com/churchkite-metron/churchkite-connector',
    );
}
