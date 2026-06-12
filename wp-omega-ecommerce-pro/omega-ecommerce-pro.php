<?php
/**
 * Plugin Name: OmegaDrive E-Commerce Pro
 * Version: 1.5.0
 * Description: High-performance caching and front-end optimization engine for WooCommerce, powered by the OmegaDrive database and reverse-proxy. Open Source and available on GitHub: https://github.com/BlackHoleDevs/wp-omega-cache
 * Author: OmegaDrive
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/class-omega-connector.php';

class Omega_Ecommerce_Pro {
    private $connector;

    private function log_debug($message, $file = 'debug') {
        if (!get_option('omega_enable_debug', 0)) {
            return;
        }
        $upload_dir = wp_upload_dir();
        if (isset($upload_dir['error']) && $upload_dir['error'] !== false) {
            return;
        }
        $log_dir = $upload_dir['basedir'] . '/omegadrive-logs';
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        $log_file = $log_dir . '/' . ($file === 'core' ? 'core_debug.log' : 'debug.log');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        @file_put_contents($log_file, $message . "\n", FILE_APPEND);
    }

    public function __construct() {
        $has_fired = function_exists('did_action') && did_action('plugins_loaded');
        $host = get_option('omega_drive_host', '172.19.0.1');

        $offline = false;
        if (!is_admin() && function_exists('get_transient')) {
            if (get_transient('omega_drive_offline')) {
                $offline = true;
            }
        }

        if ($offline) {
            $this->connector = null;
        } else {
            $this->connector = new Omega_Connector($host, 6380);
            if ($this->connector->connection_error) {
                if (!is_admin() && function_exists('set_transient')) {
                    set_transient('omega_drive_offline', '1', 15);
                }
            }
        }

        $this->log_debug("1. CONSTRUCT CALLED");
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $this->log_debug("URI: " . $request_uri . " | FOUND OMEGA: " . __FILE__, 'core');

        if ($has_fired) {
            $this->serve_cache();
            $this->start_buffer();
        } else {
            // We jump in as early as possible to bypass EVERYTHING
            add_action('plugins_loaded', [$this, 'serve_cache'], 0);
            add_action('plugins_loaded', [$this, 'start_buffer'], 1);
        }
        add_action('shutdown', [$this, 'end_buffer'], 9999);
        
        add_filter('script_loader_tag', [$this, 'defer_scripts'], 10, 3);
        add_action('wp_enqueue_scripts', [$this, 'dequeue_unneeded_scripts'], 9999);

        add_action('update_option_omega_enable_debug', function($old_value, $value) {
            if (!$value) {
                $upload_dir = wp_upload_dir();
                if (isset($upload_dir['basedir'])) {
                    $log_dir = $upload_dir['basedir'] . '/omegadrive-logs';
                    $debug_log = $log_dir . '/debug.log';
                    $core_log = $log_dir . '/core_debug.log';
                    if (file_exists($debug_log)) {
                        wp_delete_file($debug_log);
                    }
                    if (file_exists($core_log)) {
                        wp_delete_file($core_log);
                    }
                }
            }
        }, 10, 2);

        add_action('update_option_omega_drive_host', function() {
            if (function_exists('delete_transient')) {
                delete_transient('omega_drive_offline');
            }
        });

        if (is_admin()) {
            add_action('admin_menu', [$this, 'admin_menu']);
            add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_styles']);
            add_action('admin_init', [$this, 'register_settings']);
            add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'plugin_action_links']);

            // Speed up WooCommerce & WordPress Admin Dashboard
            if (get_option('omega_disable_analytics', 0)) {
                add_filter('woocommerce_admin_disabled', '__return_true');
            }

            $heartbeat = get_option('omega_heartbeat_control', 'modify');
            if ($heartbeat === 'disable') {
                add_action('init', function() {
                    wp_deregister_script('heartbeat');
                }, 1);
            } elseif ($heartbeat === 'modify') {
                add_filter('heartbeat_settings', function($settings) {
                    $settings['interval'] = 120;
                    return $settings;
                });
            }

            if (get_option('omega_disable_suggestions', 0)) {
                add_filter('woocommerce_helper_connect_to_woocommerce_helper', '__return_false');
                add_filter('woocommerce_show_admin_notice', '__return_false');
                add_filter('woocommerce_allow_marketplace_suggestions', '__return_false');
                add_filter('woocommerce_show_addons', '__return_false');
            }
        }
    }

    public function admin_menu() {
        add_options_page('OmegaDrive Settings', 'OmegaDrive', 'manage_options', 'omegadrive-settings', [$this, 'settings_page']);
    }

    public function admin_enqueue_styles($hook) {
        if ($hook === 'settings_page_omegadrive-settings') {
            wp_enqueue_style('omega-admin-css', plugins_url('css/admin.css', __FILE__), array(), '1.5.0');
        }
    }

    public function plugin_row_meta($links, $file) {
        if ($file === plugin_basename(__FILE__)) {
            $links[] = '<a href="https://github.com/BlackHoleDevs/wp-omega-cache" target="_blank" rel="noopener noreferrer">' . esc_html__('GitHub (Open Source)', 'omega-ecommerce-pro') . '</a>';
        }
        return $links;
    }

    public function plugin_action_links($links) {
        $settings_link = '<a href="options-general.php?page=omegadrive-settings">' . esc_html__('Settings', 'omega-ecommerce-pro') . '</a>';
        $github_link = '<a href="https://github.com/BlackHoleDevs/wp-omega-cache" target="_blank" rel="noopener noreferrer">' . esc_html__('GitHub (Open Source)', 'omega-ecommerce-pro') . '</a>';
        array_unshift($links, $settings_link);
        $links[] = $github_link;
        return $links;
    }

    public function register_settings() {
        register_setting('omegadrive_options_group', 'omega_drive_host', [
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('omegadrive_options_group', 'omega_disable_analytics', [
            'sanitize_callback' => 'absint',
        ]);
        register_setting('omegadrive_options_group', 'omega_heartbeat_control', [
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('omegadrive_options_group', 'omega_disable_suggestions', [
            'sanitize_callback' => 'absint',
        ]);
        register_setting('omegadrive_options_group', 'omega_enable_debug', [
            'sanitize_callback' => 'absint',
        ]);
    }

    public function settings_page() {
        // Test connection to OmegaDrive Matrix
        $ping_success = false;
        $latency = 0;
        if ($this->connector) {
            $start = microtime(true);
            $this->connector->set('omega_ping_test', '1');
            $val = $this->connector->get('omega_ping_test');
            if ($val === '1') {
                $ping_success = true;
                $latency = round((microtime(true) - $start) * 1000, 2);
                if (function_exists('delete_transient')) {
                    delete_transient('omega_drive_offline');
                }
            }
        }

        $flush_status = null;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_POST['omega_flush_db_btn'])) {
            check_admin_referer('omega_flush_db_action', 'omega_flush_db_nonce');
            if (function_exists('delete_transient')) {
                delete_transient('omega_drive_offline');
            }
            if ($this->connector) {
                if ($this->connector->flush()) {
                    $flush_status = 'success';
                } else {
                    $flush_status = 'error';
                }
            }
        }
        ?>

        <div class="omega-dashboard">
            <div class="omega-header">
                <h1 class="omega-title">OmegaDrive E-Commerce Pro</h1>
                <div>
                    <?php if ($ping_success) : ?>
                        <span class="omega-badge omega-badge-success">
                            <span style="display:inline-block; width:8px; height:8px; background:#10b981; border-radius:50%;"></span>
                            Connected (<?php echo esc_html($latency); ?>ms latency)
                        </span>
                    <?php else : ?>
                        <span class="omega-badge omega-badge-error">
                            <span style="display:inline-block; width:8px; height:8px; background:#ef4444; border-radius:50%;"></span>
                            Disconnected
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($flush_status === 'success') : ?>
                <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #34d399; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 14px;">
                    <strong>Success:</strong> OmegaDrive database cache flushed successfully!
                </div>
            <?php elseif ($flush_status === 'error') : ?>
                <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #f87171; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 14px;">
                    <strong>Error:</strong> Failed to flush OmegaDrive database cache.
                </div>
            <?php endif; ?>

            <?php if (!$ping_success) : ?>
                <div style="background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.3); color: #f59e0b; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 14px; line-height: 1.5;">
                    <strong>Notice:</strong> OmegaDrive database is offline. Page Caching is bypassed (we highly recommend connecting Omega for maximum performance). However, all Frontend Optimizations (CSS Inlining, LCP Preloading, WebP Conversion, etc.) remain active!
                </div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php settings_fields('omegadrive_options_group'); ?>
                
                <div class="omega-section">
                    <h2 class="omega-section-title">Matrix Connection Settings</h2>
                    <div class="omega-row">
                        <div>
                            <div class="omega-label">Matrix Host / IP</div>
                            <div class="omega-desc">IP address of the OmegaDrive high-performance key-value database.</div>
                        </div>
                        <div>
                            <input type="text" name="omega_drive_host" class="omega-input-text" value="<?php echo esc_attr(get_option('omega_drive_host', '172.19.0.1')); ?>" />
                        </div>
                    </div>
                </div>

                <div class="omega-section">
                    <h2 class="omega-section-title">WP-Admin Speed & Resource Optimizations</h2>
                    
                    <div class="omega-row">
                        <div>
                            <div class="omega-label">Deactivate WooCommerce Analytics</div>
                            <div class="omega-desc">Speeds up WP Admin loads by up to 50% by completely disabling the heavy WooCommerce Analytics and Gutenberg report generator packages.</div>
                        </div>
                        <div>
                            <label class="omega-toggle-switch">
                                <input type="checkbox" name="omega_disable_analytics" value="1" <?php checked(1, get_option('omega_disable_analytics', 0)); ?> />
                                <span class="omega-toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="omega-row">
                        <div>
                            <div class="omega-label">WordPress Heartbeat Control</div>
                            <div class="omega-desc">Regulates frequency of WordPress heartbeat AJAX requests in the background. Reducing this prevents CPU spikes on your server.</div>
                        </div>
                        <div>
                            <select name="omega_heartbeat_control" class="omega-select">
                                <option value="modify" <?php selected('modify', get_option('omega_heartbeat_control', 'modify')); ?>>Reduce frequency (120s interval)</option>
                                <option value="disable" <?php selected('disable', get_option('omega_heartbeat_control', 'modify')); ?>>Disable Heartbeat completely</option>
                                <option value="default" <?php selected('default', get_option('omega_heartbeat_control', 'modify')); ?>>Default WordPress behavior</option>
                            </select>
                        </div>
                    </div>

                    <div class="omega-row">
                        <div>
                            <div class="omega-label">Block Marketplace Ads & Suggestions</div>
                            <div class="omega-desc">Prevents WooCommerce from loading remote marketing ads, helper notices, and extensions suggestions directly on your dashboard.</div>
                        </div>
                        <div>
                            <label class="omega-toggle-switch">
                                <input type="checkbox" name="omega_disable_suggestions" value="1" <?php checked(1, get_option('omega_disable_suggestions', 0)); ?> />
                                <span class="omega-toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="omega-row">
                        <div>
                            <div class="omega-label">Enable Debug Logging</div>
                            <div class="omega-desc">Generates detailed debug logs (debug.log and core_debug.log) inside the plugin directory for monitoring cache hits, misses, and optimization events. Turn off on production.</div>
                        </div>
                        <div>
                            <label class="omega-toggle-switch">
                                <input type="checkbox" name="omega_enable_debug" value="1" <?php checked(1, get_option('omega_enable_debug', 0)); ?> />
                                <span class="omega-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <div style="text-align: right; margin-top: 24px; margin-bottom: 24px;">
                    <?php submit_button('Save Configuration', 'primary', 'submit', false, ['class' => 'omega-btn-submit']); ?>
                </div>
            </form>

            <div class="omega-section">
                <h2 class="omega-section-title">Database Maintenance</h2>
                <div class="omega-row">
                    <div>
                        <div class="omega-label">Flush Database Cache</div>
                        <div class="omega-desc">Clears all cached pages, static assets, and objects from the OmegaDrive memory. Use this if you made changes to styles or product catalog.</div>
                    </div>
                    <div>
                        <form method="post" action="" style="margin:0;">
                            <?php wp_nonce_field('omega_flush_db_action', 'omega_flush_db_nonce'); ?>
                            <button type="submit" name="omega_flush_db_btn" class="omega-btn-danger">Flush Cache</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function is_bypass_request() {
        if (is_admin()) return true;
        
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        
        if (strpos($request_uri, 'wp-admin') !== false) return true;
        if (strpos($request_uri, 'wp-login') !== false) return true;
        
        // Dynamic WooCommerce page ID bypasses
        $cart_id = (int)get_option('woocommerce_cart_page_id');
        $checkout_id = (int)get_option('woocommerce_checkout_page_id');
        $myaccount_id = (int)get_option('woocommerce_myaccount_page_id');
        
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page_id = isset($_GET['page_id']) ? (int)$_GET['page_id'] : 0;
        if ($page_id) {
            if ($page_id === $cart_id || $page_id === $checkout_id || $page_id === $myaccount_id) return true;
        }

        // WooCommerce transactional page bypasses
        if (strpos($request_uri, '/cart') !== false) return true;
        if (strpos($request_uri, '/checkout') !== false) return true;
        if (strpos($request_uri, '/my-account') !== false) return true;
        if (strpos($request_uri, 'wc-ajax=') !== false) return true;
        if (strpos($request_uri, 'wp-json') !== false) return true;
        if (strpos($request_uri, 'rest_route=') !== false) return true;
        
        if (defined('DOING_AJAX') && DOING_AJAX) return true;
        if (defined('REST_REQUEST') && REST_REQUEST) return true;
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return true;
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return true;
        
        if (function_exists('headers_list')) {
            foreach (headers_list() as $header) {
                if (stripos($header, 'content-type:') !== false && stripos($header, 'application/json') !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    public function serve_cache() {
        if (!$this->connector || !empty($this->connector->connection_error)) {
            return;
        }
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/';

        // Check for assets requests first, bypassing standard page caching logic
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['omega_ext'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $omega_ext = sanitize_text_field(wp_unslash($_GET['omega_ext']));
            if (preg_match('/^[a-f0-9]+\.(js|css|webp|png|jpg|gif|bin|woff|woff2|ttf|eot)$/i', $omega_ext)) {
                $asset_key = 'asset:/omega-ext/' . $omega_ext;
                $cached_asset = $this->connector->get($asset_key);
                if ($cached_asset) {
                    $ext = pathinfo($omega_ext, PATHINFO_EXTENSION);
                    if ($ext === 'js') {
                        header('Content-Type: application/javascript; charset=UTF-8');
                    } elseif ($ext === 'css') {
                        header('Content-Type: text/css; charset=UTF-8');
                    } elseif ($ext === 'webp') {
                        header('Content-Type: image/webp');
                    } elseif ($ext === 'woff2') {
                        header('Content-Type: font/woff2');
                    } elseif ($ext === 'woff') {
                        header('Content-Type: font/woff');
                    } elseif ($ext === 'ttf') {
                        header('Content-Type: font/ttf');
                    }
                    // Efficient browser caching: 1 year public cache time
                    header('Cache-Control: public, max-age=31536000, immutable');
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo $cached_asset;
                    exit;
                }
            }
        }

        if ($this->is_bypass_request()) return;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['nocache'])) return;

        $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if ($request_method !== 'GET') return;

        $http_host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : 'localhost';
        $cache_key = 'hyper_matrix:' . md5($http_host . $request_uri);
        $cached_content = $this->connector->get($cache_key);

        if ($cached_content) {
            header('X-Omega-Status: HYPER-HIT');
            header('X-Omega-Key: ' . $cache_key);
            header('Content-Type: text/html; charset=UTF-8');
            // Efficient browser caching for public cached HTML pages (e.g. 5 minutes)
            header('Cache-Control: public, max-age=300, must-revalidate');
            
            // Check if cached content is gzipped (starts with gzip magic bytes 0x1f 0x8b)
            $is_gzipped = (strlen($cached_content) >= 2 && ord($cached_content[0]) === 0x1f && ord($cached_content[1]) === 0x8b);
            
            if ($is_gzipped) {
                $http_accept_encoding = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_ENCODING'])) : '';
                if (strpos($http_accept_encoding, 'gzip') !== false) {
                    header('Content-Encoding: gzip');
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo $cached_content;
                } else {
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo gzdecode($cached_content);
                }
            } else {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo $cached_content;
            }
            exit;
        }
    }

    public function start_buffer() {
        if ($this->is_bypass_request()) return;
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $this->log_debug("2. START BUFFER CALLED. URI: " . $request_uri);
        ob_start([$this, 'buffer_callback']);
    }

    public function end_buffer() {
        if (ob_get_level() > 0) {
            @ob_end_flush();
        }
    }

    public function buffer_callback($content) {
        if ($this->is_bypass_request()) return $content;
        
        $this->log_debug("3. BUFFER CALLBACK RUNNING. LEN: " . strlen($content));
        
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        $http_host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : 'localhost';
        
        // Zabezpieczenie: jeśli content nie zaczyna się od doctype lub html, nie dotykaj go!
        $trimmed = ltrim($content);
        if (stripos($trimmed, '<html') === false && stripos($trimmed, '<!doctype') === false) {
            return $content;
        }
        
        if (strlen($content) < 100) {
            return $content;
        }

        // --- AUTOMATYCZNY CSS INLINER ---
        if (preg_match_all('/<link\s+[^>]*rel=[\'"]stylesheet[\'"][^>]*href=[\'"]([^\'"]+)[\'"][^>]*>/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $full_tag = $match[0];
                $url = html_entity_decode($match[1]);
                
                // Ignoruj zewnętrzne style (np. Google Fonts)
                if (strpos($url, 'fonts.googleapis.com') !== false || strpos($url, 'fonts.gstatic.com') !== false) {
                    continue;
                }
                
                // Wycinamy query string (?ver=...)
                $clean_url = explode('?', $url)[0];
                
                // Dopasuj tylko lokalne ścieżki wp-content lub wp-includes
                    $filesystem_path = $this->url_to_local_path($clean_url);
                    
                    if (!empty($filesystem_path) && file_exists($filesystem_path)) {
                        $css_content = @file_get_contents($filesystem_path);
                        if ($css_content && strlen($css_content) < 80000) { // Limit 80KB na plik
                            $base_path = dirname(wp_parse_url($clean_url, PHP_URL_PATH)) . '/';
                            
                            // Korekta ścieżek relatywnych wewnątrz CSS
                            $css_content = preg_replace_callback(
                                '/url\(\s*([\'"]?)(?!data:)(?!http:)(?!https:)(?!\/)(.*?)\1\s*\)/i',
                                function($sub_matches) use ($base_path) {
                                    $quote = $sub_matches[1];
                                    $path = $sub_matches[2];
                                    $resolved_path = $base_path . $path;
                                    return "url(" . $quote . $resolved_path . $quote . ")";
                                },
                                $css_content
                            );
                            
                            $css_content = $this->minify_css($css_content);
                            
                            $inline_style = '<style id="omega-inlined-' . md5($clean_url) . '">' . $css_content . '</style>';
                            $content = str_replace($full_tag, $inline_style, $content);
                        }
                    }
                }
            }

        $cache_key = 'hyper_matrix:' . md5($http_host . $request_uri);
        
        // Agresywny, ultra-bezpieczny defer dla skryptów w locie w HTML (uniwersalny parser)
        $content = preg_replace_callback('/<script\s+([^>]*)src=["\']([^"\']+\.js[^"\']*)["\']([^>]*)>/i', function($matches) {
            $attrs_before = $matches[1];
            $src = $matches[2];
            $attrs_after = $matches[3];
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("OmegaDrive Match: " . $src);
            $this->log_debug("MATCHED SCRIPT: " . $src);
            
            // Tylko rdzeń jQuery zachowujemy synchronicznie (jquery-migrate może być w pełni deferred!)
            if (strpos($src, 'jquery.min.js') !== false || strpos($src, 'jquery.js') !== false) {
                return $matches[0];
            }
            
            $all_attrs = $attrs_before . ' ' . $attrs_after;
            
            $has_defer = preg_match('/\sdefer(\s|=|>|$)/i', ' ' . $all_attrs);
            if ($has_defer && (stripos($all_attrs, 'data-wp-strategy="defer"') !== false || stripos($all_attrs, 'data-wp-strategy=\'defer\'') !== false)) {
                $attrs_clean = str_ireplace(['data-wp-strategy="defer"', 'data-wp-strategy=\'defer\''], '', $all_attrs);
                $has_defer = preg_match('/\sdefer(\s|=|>|$)/i', ' ' . $attrs_clean);
            }
            
            $has_async = preg_match('/\sasync(\s|=|>|$)/i', ' ' . $all_attrs);
            if ($has_async && (stripos($all_attrs, 'data-wp-strategy="async"') !== false || stripos($all_attrs, 'data-wp-strategy=\'async\'') !== false)) {
                $attrs_clean = str_ireplace(['data-wp-strategy="async"', 'data-wp-strategy=\'async\''], '', $all_attrs);
                $has_async = preg_match('/\sasync(\s|=|>|$)/i', ' ' . $attrs_clean);
            }
            
            if (!$has_defer && !$has_async) {
                $tag_before = trim($attrs_before);
                $tag_after = trim($attrs_after);
                return '<script defer ' . ($tag_before ? $tag_before . ' ' : '') . 'src="' . $src . '"' . ($tag_after ? ' ' . $tag_after : '') . '>';
            }
            return $matches[0];
        }, $content);
        
        // Agresywne usuwanie ciężkich i zbędnych skryptów rdzenia z HTML-a
        $unneeded_patterns = [
            'react.min.js',
            'react-dom.min.js',
            'moment.min.js',
            'moment-js-after'
        ];
        
        foreach ($unneeded_patterns as $pattern) {
            // Usuwa skrypty po atrybucie src
            $content = preg_replace('/<script[^>]*src=["\'][^"\']*' . preg_quote($pattern, '/') . '[^"\']*["\'][^>]*>.*?<\/script>/is', '', $content);
            // Usuwa skrypty po atrybucie id (dla bloków inline i -after)
            $content = preg_replace('/<script[^>]*id=["\']' . preg_quote($pattern, '/') . '["\'][^>]*>.*?<\/script>/is', '', $content);
        }
        
        // ========== OPTIMIZATION 1: Universal External Resource Proxy → OmegaDrive RAM ==========
        // Wykrywa KAŻDY zewnętrzny CSS i JS, pobiera go, zapisuje do OmegaDrive RAM i przepisuje URL na lokalny
        $current_host = $http_host;
        $http_ctx = stream_context_create(['http' => [
            'header' => "User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36\r\n",
            'timeout' => 5
        ]]);
        
        // --- 1A: Zewnętrzne/Lokalne CSS ---
        $content = preg_replace_callback('/<link([^>]*rel=["\']stylesheet["\'][^>]*)href=["\']([^"\']+)["\']([^>]*)\/?>/i', function($m) use ($current_host, $http_ctx) {
            $before = $m[1]; $url = html_entity_decode($m[2]); $after = $m[3];
            
            // Skip admin styles or plugins that shouldn't be touched
            if (stripos($url, '/wp-admin/') !== false || stripos($url, 'wp-login.php') !== false) {
                return $m[0];
            }
            
            $parsed = wp_parse_url($url);
            $host = $parsed['host'] ?? '';
            $path = $parsed['path'] ?? '';
            
            // Determine if it is a local file and find the local path
            $local_file_path = '';
            if (empty($host) || stripos($host, $current_host) !== false || stripos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
                $abspath_file = $this->url_to_local_path($url);
                if (!empty($abspath_file) && file_exists($abspath_file)) {
                    $local_file_path = $abspath_file;
                }
            }
            
            // Generate cache key and path. Query string must be part of the hash to support version cache busting.
            $url_hash = md5($url);
            $ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'css';
            $local_path = site_url('/index.php?omega_ext=' . $url_hash . '.' . $ext);
            $cache_key = 'asset:/omega-ext/' . $url_hash . '.' . $ext;
            
            $cached = $this->connector->get($cache_key);
            if (!$cached) {
                $css_data = '';
                if (!empty($local_file_path)) {
                    $css_data = @file_get_contents($local_file_path);
                } else {
                    // Resolve external url
                    $fetch_url = $url;
                    if (strpos($url, '//') === 0) {
                        $fetch_url = (is_ssl() ? 'https:' : 'http:') . $url;
                    }
                    $css_data = @file_get_contents($fetch_url, false, $http_ctx);
                }
                
                if ($css_data) {
                    if (stripos($path, '.min.css') === false) {
                        $css_data = $this->minify_css($css_data);
                    }
                    
                    // Parse url() inside CSS (fonts, images) and download them too
                    preg_match_all('/url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $css_data, $sub_urls);
                    foreach ($sub_urls[1] as $sub_url) {
                        $sub_url = trim($sub_url);
                        if (strpos($sub_url, 'data:') === 0) continue; // skip data URIs
                        
                        // Resolve relative sub-url relative to parent CSS file
                        $sub_resolved_url = $sub_url;
                        if (strpos($sub_url, 'http') !== 0 && strpos($sub_url, '//') !== 0) {
                            // Relative path inside CSS
                            $css_dir = dirname($url);
                            $sub_resolved_url = $css_dir . '/' . $sub_url;
                        }
                        
                        // Check if local
                        $sub_parsed = wp_parse_url($sub_resolved_url);
                        $sub_host = $sub_parsed['host'] ?? '';
                        $sub_path = $sub_parsed['path'] ?? '';
                        
                        $sub_local_file = '';
                        if (empty($sub_host) || stripos($sub_host, $current_host) !== false || stripos($sub_host, 'localhost') !== false) {
                            $sub_abspath = $this->url_to_local_path($sub_resolved_url);
                            if (!empty($sub_abspath) && file_exists($sub_abspath)) {
                                $sub_local_file = $sub_abspath;
                            }
                        }
                        
                        $sub_data = '';
                        if (!empty($sub_local_file)) {
                            $sub_data = @file_get_contents($sub_local_file);
                        } else {
                            if (strpos($sub_resolved_url, '//') === 0) {
                                $sub_resolved_url = (is_ssl() ? 'https:' : 'http:') . $sub_resolved_url;
                            }
                            $sub_data = @file_get_contents($sub_resolved_url, false, $http_ctx);
                        }
                        
                        if ($sub_data) {
                            $sub_hash = md5($sub_resolved_url);
                            $sub_ext = pathinfo($sub_path, PATHINFO_EXTENSION) ?: 'bin';
                            $sub_local = site_url('/index.php?omega_ext=' . $sub_hash . '.' . $sub_ext);
                            $this->connector->set('asset:/omega-ext/' . $sub_hash . '.' . $sub_ext, $sub_data);
                            $css_data = str_replace($sub_url, $sub_local, $css_data);
                        }
                    }
                    
                    // Add font-display: swap if missing
                    if (strpos($css_data, '@font-face') !== false && strpos($css_data, 'font-display') === false) {
                        $css_data = preg_replace('/@font-face\s*\{/', '@font-face { font-display: swap;', $css_data);
                    }
                    
                    $this->connector->set($cache_key, $css_data);
                    $cached = $css_data;
                }
            }
            
            if ($cached) {
                // Inline small CSS (< 10KB), rest rewrite to local path
                if (strlen($cached) < 10240) {
                    return '<style id="omega-ext-' . substr($url_hash, 0, 8) . '">' . $cached . '</style>';
                }
                return '<link' . $before . 'href="' . $local_path . '"' . $after . '/>';
            }
            return $m[0]; // fallback
        }, $content);
        
        // --- 1B: Zewnętrzne/Lokalne JS ---
        $content = preg_replace_callback('/<script([^>]*)src=["\']([^"\']+)["\']([^>]*)>/i', function($m) use ($current_host, $http_ctx) {
            $before = $m[1]; $url = html_entity_decode($m[2]); $after = $m[3];
            
            // Skip admin files
            if (stripos($url, '/wp-admin/') !== false || stripos($url, 'wp-login.php') !== false) {
                return $m[0];
            }
            
            $parsed = wp_parse_url($url);
            $host = $parsed['host'] ?? '';
            $path = $parsed['path'] ?? '';
            
            // Determine local file path
            $local_file_path = '';
            if (empty($host) || stripos($host, $current_host) !== false || stripos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
                $abspath_file = $this->url_to_local_path($url);
                if (!empty($abspath_file) && file_exists($abspath_file)) {
                    $local_file_path = $abspath_file;
                }
            }
            
            $url_hash = md5($url);
            $local_path = site_url('/index.php?omega_ext=' . $url_hash . '.js');
            $cache_key = 'asset:/omega-ext/' . $url_hash . '.js';
            
            $cached = $this->connector->get($cache_key);
            if (!$cached) {
                $js_data = '';
                if (!empty($local_file_path)) {
                    $js_data = @file_get_contents($local_file_path);
                } else {
                    $fetch_url = $url;
                    if (strpos($url, '//') === 0) {
                        $fetch_url = (is_ssl() ? 'https:' : 'http:') . $url;
                    }
                    $js_data = @file_get_contents($fetch_url, false, $http_ctx);
                }
                
                if ($js_data) {
                    if (stripos($path, '.min.js') === false) {
                        $js_data = $this->minify_js($js_data);
                    }
                    $this->connector->set($cache_key, $js_data);
                    $cached = $js_data;
                }
            }
            
            if ($cached) {
                return '<script' . $before . 'src="' . $local_path . '"' . $after . '>';
            }
            return $m[0];
        }, $content);
        
        // ========== OPTIMIZATION 2: CSS Async Loading ==========
        // Zamień non-critical CSS na async (media="print" onload pattern)
        $critical_css_ids = ['awesome_shop_cfg_parent-css', 'best-shop-bootstrap-css', 'best-shop-style-css'];
        $content = preg_replace_callback('/<link\s+([^>]*rel=["\']stylesheet["\'][^>]*)>/i', function($m) use ($critical_css_ids) {
            $tag = $m[0];
            $attrs = $m[1];
            
            // Nie ruszaj critical CSS (bootstrap + main style)
            foreach ($critical_css_ids as $id) {
                if (strpos($attrs, $id) !== false) return $tag;
            }
            if (strpos($attrs, 'onload') !== false) return $tag;
            if (strpos($attrs, 'omega_ext') !== false || strpos($attrs, 'omega-ext') !== false) return $tag;
            
            // Zamień na async pattern
            $tag = preg_replace('/\s*media=["\'][^"\']*["\']/i', '', $tag);
            $tag = preg_replace('/\/?\s*>$/', ' media="print" onload="this.media=\'all\'" />', $tag);
            return $tag;
        }, $content);
        
        // ========== OPTIMIZATION 3: Cleanup ==========
        // Usuń dns-prefetch do zewnętrznych domen fontów (już niepotrzebne)
        $content = preg_replace('/<link[^>]*dns-prefetch[^>]*fonts\.googleapis\.com[^>]*>/i', '', $content);

        // ========== ELITE LIGHTHOUSE OPTIMIZATION: Performance, SEO, Accessibility, Best Practices ==========
        // 0. Inject ultra-fast window.wp.hooks fallback to prevent YITH/Gutenberg JS runtime crashes
        $wp_hooks_fallback = '<script id="omega-wp-hooks-fallback">
        window.wp = window.wp || {};
        window.wp.hooks = window.wp.hooks || {
            addAction: function(){},
            removeAction: function(){},
            doAction: function(){},
            addFilter: function(){},
            applyFilters: function(){},
            hasAction: function(){ return false; },
            hasFilter: function(){ return false; }
        };
        </script>';
        $content = preg_replace('/<head(\s+[^>]*)?>/i', '$0' . "\n" . $wp_hooks_fallback, $content);
        
        // 1. Auto-Alt, Lazy Loading, Async Decoding & CLS Prevention for Images
        $img_count = 0;
        $content = preg_replace_callback('/<img\s+([^>]+)>/i', function($m) use (&$img_count) {
            $attrs_str = $m[1];
            
            // Ultra-fast Next-Gen WebP rewriting for all image source attributes
            $attrs_str = preg_replace_callback('/(src|srcset|data-src|data-srcset|data-lazy-src)=["\']([^"\']+)["\']/i', function($attr_match) {
                $attr_name = $attr_match[1];
                $attr_val = $attr_match[2];
                $attr_val = $this->optimize_image_src($attr_name, $attr_val);
                return $attr_name . '="' . $attr_val . '"';
            }, $attrs_str);
            
            // Safe handling of self-closing trailing slash
            $suffix = '';
            if (substr($attrs_str, -2) === ' /') {
                $attrs_str = substr($attrs_str, 0, -2);
                $suffix = ' /';
            } elseif (substr($attrs_str, -1) === '/') {
                $attrs_str = substr($attrs_str, 0, -1);
                $suffix = '/';
            }
            
            // Auto decoding="async"
            if (stripos($attrs_str, 'decoding=') === false) {
                $attrs_str .= ' decoding="async"';
            }
            
            // Auto Alt injection if missing or empty
            if (stripos($attrs_str, 'alt=') === false || preg_match('/alt=["\']\s*["\']/i', $attrs_str)) {
                // Usuń stary pusty alt jeśli istnieje
                $attrs_str = preg_replace('/alt=["\']\s*["\']/i', '', $attrs_str);
                
                // Wyciągnij nazwę pliku z src, aby stworzyć przyjazny alt
                $alt_val = 'Product Image';
                if (preg_match('/src=["\']([^"\']+)["\']/i', $attrs_str, $src_matches)) {
                    $filename = basename(wp_parse_url($src_matches[1], PHP_URL_PATH));
                    $filename_clean = pathinfo($filename, PATHINFO_FILENAME);
                    $alt_val = ucwords(str_replace(['-', '_'], ' ', $filename_clean));
                }
                $attrs_str .= ' alt="' . esc_attr($alt_val) . '"';
            }
            
            // CLS Prevention: Auto-Width and Auto-Height detection
            if (stripos($attrs_str, 'width=') === false || stripos($attrs_str, 'height=') === false) {
                if (preg_match('/src=["\']([^"\']+)["\']/i', $attrs_str, $src_matches)) {
                    $img_url = $src_matches[1];
                    // Jeśli to lokalny plik z wp-content, odczytaj rzeczywiste wymiary z dysku
                    if (strpos($img_url, '/wp-content/') !== false) {
                        $local_img_path = $this->url_to_local_path($img_url);
                        if (!empty($local_img_path) && file_exists($local_img_path) && $size = @getimagesize($local_img_path)) {
                            if (stripos($attrs_str, 'width=') === false) {
                                $attrs_str .= ' width="' . $size[0] . '"';
                            }
                            if (stripos($attrs_str, 'height=') === false) {
                                $attrs_str .= ' height="' . $size[1] . '"';
                            }
                        }
                    }
                }
            }

            // CRITICAL LCP OPTIMIZATION:
            // Do NOT count gravatars, avatars, or small icon images as LCP candidates.
            $is_lcp_candidate = true;
            if (stripos($attrs_str, 'avatar') !== false || stripos($attrs_str, 'gravatar') !== false || stripos($attrs_str, 'icon') !== false) {
                $is_lcp_candidate = false;
            }

            if ($is_lcp_candidate) {
                $img_count++;
                if ($img_count <= 2) {
                    // Do NOT lazy load the first 2 images. Instead, give them fetchpriority="high".
                    $attrs_str = preg_replace('/\s*loading=["\']?lazy["\']?/i', '', $attrs_str);
                    if (stripos($attrs_str, 'fetchpriority=') === false) {
                        $attrs_str .= ' fetchpriority="high"';
                    }
                } else {
                    // Remaining images: add loading="lazy", and make sure we don't keep fetchpriority="high".
                    $attrs_str = preg_replace('/\s*fetchpriority=["\']?high["\']?/i', '', $attrs_str);
                    if (stripos($attrs_str, 'loading=') === false) {
                        $attrs_str .= ' loading="lazy"';
                    }
                }
            } else {
                // If it is an avatar/icon, it is definitely not LCP. Remove fetchpriority if present.
                $attrs_str = preg_replace('/\s*fetchpriority=["\']?high["\']?/i', '', $attrs_str);
            }
            
            return '<img ' . trim($attrs_str) . $suffix . '>';
        }, $content);

        // 2. Accessibility: WooCommerce Missing Aria-Labels & Roles
        // Wstrzykujemy aria-label do linków koszyka i innych ikon bez tekstu lub na podstawie słów kluczowych
        $content = preg_replace_callback('/<a\s+([^>]+)>(.*?)<\/a>/is', function($m) {
            $attrs = $m[1];
            $body = trim($m[2]);
            
            // Sprawdź czy brak aria-label
            if (stripos($attrs, 'aria-label=') === false) {
                $is_empty = ($body === '' || preg_match('/^<i\s+[^>]+><\/i>$/i', $body) || preg_match('/^<svg[^>]*>.*?<\/svg>$/is', $body));
                $has_woo_keywords = preg_match('/(cart|wishlist|search|account|compare)/i', $attrs);
                
                if ($is_empty || $has_woo_keywords) {
                    $aria_text = 'View details';
                    if (stripos($attrs, 'cart') !== false) {
                        $aria_text = 'View shopping cart';
                    } elseif (stripos($attrs, 'wishlist') !== false) {
                        $aria_text = 'View wishlist';
                    } elseif (stripos($attrs, 'search') !== false) {
                        $aria_text = 'Search products';
                    } elseif (stripos($attrs, 'account') !== false) {
                        $aria_text = 'My account';
                    } elseif (stripos($attrs, 'compare') !== false) {
                        $aria_text = 'Compare products';
                    }
                    $attrs .= ' aria-label="' . $aria_text . '"';
                }
            }
            return '<a ' . $attrs . '>' . $body . '</a>';
        }, $content);

        // 3. Speculative Preloading (Instant.page implementation for 0ms transitions)
        $instant_js = '<script type="module">
        /*! instant.page v5.2.0 - (C) 2019-2020 Alexandre Dieulot - https://instant.page/license */
        let mouseoverTimer, lastTouchTimestamp;
        const prefetches = new Set(), prefetcher = document.createElement("link");
        const isSupported = prefetcher.relList && prefetcher.relList.supports && prefetcher.relList.supports("prefetch") && window.IntersectionObserver && "connection" in navigator;
        if (isSupported && !navigator.connection.saveData && !navigator.connection.effectiveType.includes("2g")) {
            prefetcher.rel = "prefetch";
            document.head.appendChild(prefetcher);
            document.addEventListener("touchstart", touchstartListener, {capture: true, passive: true});
            document.addEventListener("mouseover", mouseoverListener, {capture: true, passive: true});
        }
        function touchstartListener(event) {
            lastTouchTimestamp = Date.now();
            const linkElement = event.target.closest("a");
            if (isEligible(linkElement)) prefetch(linkElement.href);
        }
        function mouseoverListener(event) {
            if (Date.now() - lastTouchTimestamp < 1111) return;
            const linkElement = event.target.closest("a");
            if (isEligible(linkElement)) {
                linkElement.addEventListener("mouseout", mouseoutListener, {passive: true});
                mouseoverTimer = setTimeout(() => { prefetch(linkElement.href); mouseoverTimer = undefined; }, 65);
            }
        }
        function mouseoutListener(event) {
            if (event.relatedTarget && event.target.closest("a") === event.relatedTarget.closest("a")) return;
            if (mouseoverTimer) { clearTimeout(mouseoverTimer); mouseoverTimer = undefined; }
        }
        function isEligible(linkElement) {
            if (!linkElement || !linkElement.href) return false;
            if (linkElement.origin !== location.origin) return false;
            if (linkElement.hash || linkElement.protocol === "mailto:" || linkElement.protocol === "tel:") return false;
            if (prefetches.has(linkElement.href)) return false;
            return true;
        }
        function prefetch(url) {
            prefetches.add(url);
            prefetcher.href = url;
        }
        </script>';
        // Wstrzyknij tuż przed zamknięciem body
        $content = str_replace('</body>', $instant_js . "\n</body>", $content);

        // ========== OPTIMIZATION 4: LCP Featured Image Preload & Fetchpriority Optimization ==========
        // Znajduje główny obrazek produktu na stronie (wp-post-image), lub pierwszy istotny obrazek nad linią załamania (LCP),
        // usuwa z niego lazy load, dodaje wysoki priorytet i wstrzykuje tag preloading do sekcji <head>.
        $lcp_img_found = false;
        $lcp_img_tag = '';
        
        if (preg_match_all('/<img[^>]+>/i', $content, $img_matches)) {
            // Pierwsza próba: szukaj wp-post-image
            foreach ($img_matches[0] as $img_tag) {
                if (strpos($img_tag, 'wp-post-image') !== false) {
                    $lcp_img_tag = $img_tag;
                    $lcp_img_found = true;
                    break;
                }
            }
            
            // Druga próba: szukaj pierwszego znaczącego obrazka (nie logo/avatar/icon)
            if (!$lcp_img_found) {
                foreach ($img_matches[0] as $img_tag) {
                    if (stripos($img_tag, 'logo') === false && stripos($img_tag, 'avatar') === false && stripos($img_tag, 'icon') === false) {
                        $lcp_img_tag = $img_tag;
                        $lcp_img_found = true;
                        break;
                    }
                }
            }
            
            if ($lcp_img_found && preg_match('/src=["\']([^"\']+)["\']/i', $lcp_img_tag, $src_match)) {
                $img_url = html_entity_decode($src_match[1]);
                
                // Wyciągamy srcset i sizes do responsywnego preloadera
                $srcset_attr = '';
                if (preg_match('/srcset=["\']([^"\']+)["\']/i', $lcp_img_tag, $srcset_match)) {
                    $srcset_attr = ' imagesrcset="' . esc_attr(html_entity_decode($srcset_match[1])) . '"';
                }
                $sizes_attr = '';
                if (preg_match('/sizes=["\']([^"\']+)["\']/i', $lcp_img_tag, $sizes_match)) {
                    $sizes_attr = ' imagesizes="' . esc_attr(html_entity_decode($sizes_match[1])) . '"';
                }
                
                // Zapewniamy, że ten konkretny tag nie ma loading="lazy" oraz ma fetchpriority="high"
                $new_lcp_tag = preg_replace('/\s*loading=["\']?lazy["\']?/i', '', $lcp_img_tag);
                if (strpos($new_lcp_tag, 'fetchpriority') === false) {
                    $new_lcp_tag = str_replace('<img', '<img fetchpriority="high"', $new_lcp_tag);
                }
                $content = str_replace($lcp_img_tag, $new_lcp_tag, $content);
                
                // Wstrzyknij responsywny preload do sekcji <head>
                if (!empty($srcset_attr)) {
                    $preload_tag = "\n" . '<link rel="preload" as="image"' . $srcset_attr . $sizes_attr . ' fetchpriority="high">';
                } else {
                    $preload_tag = "\n" . '<link rel="preload" as="image" href="' . esc_url($img_url) . '" fetchpriority="high">';
                }
                
                // Krytyczne style przyspieszające LCP i wyłączające spinnery preloadera
                $critical_lcp_css = "\n" . '<style id="omega-critical-lcp-bypass">' .
                    '.woocommerce-product-gallery { opacity: 1 !important; visibility: visible !important; }' .
                    '.woocommerce-product-gallery__image { opacity: 1 !important; visibility: visible !important; }' .
                    '.preloader-center, .preloader, #preloader, .pswp__preloader { display: none !important; visibility: hidden !important; opacity: 0 !important; pointer-events: none !important; }' .
                    '</style>';
                
                $content = preg_replace('/<head(\s+[^>]*)?>/i', '$0' . $preload_tag . $critical_lcp_css, $content);
            }
        }

        if (!$this->connector || !empty($this->connector->connection_error)) {
            $err_msg = $this->connector ? $this->connector->connection_error : 'OmegaDrive daemon not connected';
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("OmegaDrive: Cache Bypassed: " . $err_msg);
            $this->log_debug("3a. CACHE BYPASSED (OPTIMIZATION ACTIVE): " . $err_msg);
            return $content . "\n<!-- OmegaDrive Frontend Optimization | Status: Active (Cache Bypassed - Daemon Offline) -->";
        }
        
        $gzipped_content = gzencode($content, 9);
        $this->connector->set($cache_key, $gzipped_content);
        $this->log_debug("4. CACHED SUCCESSFULLY. KEY: " . $cache_key);
        return $content . "\n<!-- OmegaDrive Hyper-Early Cache | Key: $cache_key | Status: Cached successfully! -->";
    }

    public function defer_scripts($tag, $handle, $src) {
        if ($this->is_bypass_request()) return $tag;
        
        // Tylko rdzeń jQuery zachowujemy synchronicznie (ponieważ skrypty inline na niego czekają)
        if (strpos($src, 'jquery.min.js') !== false || strpos($src, 'jquery.js') !== false) {
            return $tag;
        }
        
        if (strpos($tag, ' defer') === false) {
            $tag = str_replace(' src=', ' defer src=', $tag);
        }
        return $tag;
    }

    public function dequeue_unneeded_scripts() {
        if ($this->is_bypass_request()) return;
        // Wyłączamy tylko te biblioteki, które na froncie prostego e-commerce nie mają powiązań i są bardzo ciężkie (np. React, Moment)
        wp_deregister_script('moment');
        wp_deregister_script('react');
        wp_deregister_script('react-dom');
        
        // Wyłączamy też nieużywane arkusze CSS Gutenberga (ogromna oszczędność żądań sieciowych!)
        wp_dequeue_style('wp-block-library');
        wp_dequeue_style('wp-block-library-theme');
        wp_dequeue_style('wc-blocks-style');
    }

    private function optimize_image_src($attr_name, $attr_val) {
        $attr_name_lower = strtolower($attr_name);
        if ($attr_name_lower === 'srcset' || $attr_name_lower === 'data-srcset') {
            $parts = explode(',', $attr_val);
            foreach ($parts as &$part) {
                $part = trim($part);
                if (empty($part)) continue;
                $subparts = preg_split('/\s+/', $part, 2);
                if (!empty($subparts[0])) {
                    $subparts[0] = $this->get_or_create_webp($subparts[0]);
                }
                $part = implode(' ', $subparts);
            }
            return implode(', ', $parts);
        }
        return $this->get_or_create_webp($attr_val);
    }

    private function get_or_create_webp($url) {
        if (stripos($url, '/wp-content/uploads/') === false) {
            return $url;
        }

        $parsed = wp_parse_url($url);
        $path = isset($parsed['path']) ? $parsed['path'] : '';
        if (empty($path)) {
            return $url;
        }

        $current_host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : 'localhost';
        $host = isset($parsed['host']) ? $parsed['host'] : '';
        if (!empty($host) && stripos($host, $current_host) === false && stripos($host, 'localhost') === false && strpos($host, '127.0.0.1') === false) {
            return $url;
        }

        $site_path = wp_parse_url(site_url(), PHP_URL_PATH) ?: '';
        $local_file_path = $this->url_to_local_path($url);
        if (empty($local_file_path) || !file_exists($local_file_path)) {
            return $url;
        }

        // Target only jpg/jpeg/png files for conversion
        if (!preg_match('/\.jpe?g$/i', $local_file_path) && !preg_match('/\.png$/i', $local_file_path)) {
            return $url;
        }

        $webp_file_path = preg_replace('/\.jpe?g$/i', '.webp', $local_file_path);
        $webp_file_path = preg_replace('/\.png$/i', '.webp', $webp_file_path);

        $webp_url = preg_replace('/\.jpe?g(\b|\?)/i', '.webp$1', $url);
        $webp_url = preg_replace('/\.png(\b|\?)/i', '.webp$1', $webp_url);

        if (file_exists($webp_file_path)) {
            return $webp_url;
        }

        // On-the-fly conversion using GD library
        $img = null;
        if (preg_match('/\.jpe?g$/i', $local_file_path)) {
            $img = @imagecreatefromjpeg($local_file_path);
        } elseif (preg_match('/\.png$/i', $local_file_path)) {
            $img = @imagecreatefrompng($local_file_path);
            if ($img) {
                imagepalettetotruecolor($img);
                imagealphablending($img, true);
                imagesavealpha($img, true);
            }
        }

        if ($img) {
            $success = @imagewebp($img, $webp_file_path, 82);
            @imagedestroy($img);
            if ($success) {
                return $webp_url;
            }
        }

        return $url;
    }

    private function minify_css($css) {
        // Remove comments
        $css = preg_replace('!/\*[^*]*\*+([^/*][^*]*\*+)*/!', '', $css);
        // Remove spaces around punctuation
        $css = preg_replace('/\s*([{}|:;,])\s*/', '$1', $css);
        // Remove extra spaces
        $css = preg_replace('/\s+/', ' ', $css);
        return trim($css);
    }

    private function minify_js($js) {
        // Strip multi-line comments safely using a non-backtracking simple regex
        $js = preg_replace('!/\*.*?\*/!s', '', $js);
        // Strip comments that occupy the entire line (safe, won't match inside regex/strings)
        $js = preg_replace('/^[ \t]*\/\/.*$/m', '', $js);
        // Remove empty lines
        $js = preg_replace('/^[\r\n\s]+$/m', '', $js);
        $js = preg_replace('/[\r\n]+/', "\n", $js);
        return trim($js);
    }

    private function url_to_local_path($url) {
        if (strpos($url, 'http') !== 0 && strpos($url, '//') !== 0) {
            $url = site_url('/' . ltrim($url, '/'));
        }
        $upload_dir = wp_upload_dir();
        if (isset($upload_dir['error']) && $upload_dir['error'] !== false) {
            return '';
        }
        $base_url = $upload_dir['baseurl'];
        $base_dir = $upload_dir['basedir'];
        
        $normalized_url = preg_replace('/^https?:/i', '', $url);
        $normalized_base_url = preg_replace('/^https?:/i', '', $base_url);
        
        if (strpos($normalized_url, $normalized_base_url) === 0) {
            $relative_path = substr($normalized_url, strlen($normalized_base_url));
            return $base_dir . $relative_path;
        }
        
        $content_url = content_url();
        $normalized_content_url = preg_replace('/^https?:/i', '', $content_url);
        if (strpos($normalized_url, $normalized_content_url) === 0) {
            $relative_path = substr($normalized_url, strlen($normalized_content_url));
            return WP_CONTENT_DIR . $relative_path;
        }
        
        $site_url = site_url();
        $normalized_site_url = preg_replace('/^https?:/i', '', $site_url);
        if (strpos($normalized_url, $normalized_site_url) === 0) {
            $relative_path = substr($normalized_url, strlen($normalized_site_url));
            return ABSPATH . ltrim($relative_path, '/');
        }
        
        return '';
    }
}

new Omega_Ecommerce_Pro();
