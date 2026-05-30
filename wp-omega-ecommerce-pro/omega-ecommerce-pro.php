<?php
/**
 * Plugin Name: OmegaDrive E-Commerce Pro (TCP Edition)
 * Version: 1.5.0 (Hyper-Early Cache)
 */

if (!defined('ABSPATH')) exit;

error_log("🚀 OMEGA ECO PRO LOADED");
opcache_reset();

require_once plugin_dir_path(__FILE__) . 'includes/class-omega-connector.php';

class Omega_Ecommerce_Pro {
    private $connector;

    public function __construct() {
        $has_fired = function_exists('did_action') && did_action('plugins_loaded');
        $host = get_option('omega_drive_host', '172.19.0.1');
        $this->connector = new Omega_Connector($host, 6380);

        if ($has_fired) {
            $this->serve_cache();
            $this->start_buffer();
        } else {
            // We jump in as early as possible to bypass EVERYTHING
            add_action('plugins_loaded', [$this, 'serve_cache'], 0);
            add_action('plugins_loaded', [$this, 'start_buffer'], 1);
        }
        
        add_filter('script_loader_tag', [$this, 'defer_scripts'], 10, 3);
        add_action('wp_enqueue_scripts', [$this, 'dequeue_unneeded_scripts'], 9999);

        if (is_admin()) {
            add_action('admin_menu', [$this, 'admin_menu']);
            add_action('admin_init', [$this, 'register_settings']);
        }
    }

    public function admin_menu() {
        add_options_page('OmegaDrive Settings', 'OmegaDrive', 'manage_options', 'omegadrive-settings', [$this, 'settings_page']);
    }

    public function register_settings() {
        register_setting('omegadrive_options_group', 'omega_drive_host');
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h2>OmegaDrive Configuration</h2>
            <form method="post" action="options.php">
                <?php settings_fields('omegadrive_options_group'); ?>
                <table class="form-table">
                    <tr valign="top">
                    <th scope="row">Matrix IP Address</th>
                    <td><input type="text" name="omega_drive_host" value="<?php echo esc_attr(get_option('omega_drive_host', '172.19.0.1')); ?>" />
                    <p class="description">Default for Docker is <code>172.19.0.1</code>. For native local installs use <code>127.0.0.1</code>.</p></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    private function is_bypass_request() {
        if (is_admin()) return true;
        if (strpos($_SERVER['REQUEST_URI'], 'wp-admin') !== false) return true;
        if (strpos($_SERVER['REQUEST_URI'], 'wp-login') !== false) return true;
        
        // Dynamic WooCommerce page ID bypasses
        $cart_id = (int)get_option('woocommerce_cart_page_id');
        $checkout_id = (int)get_option('woocommerce_checkout_page_id');
        $myaccount_id = (int)get_option('woocommerce_myaccount_page_id');
        
        if (isset($_GET['page_id'])) {
            $pid = (int)$_GET['page_id'];
            if ($pid === $cart_id || $pid === $checkout_id || $pid === $myaccount_id) return true;
        }

        // WooCommerce transactional page bypasses
        if (strpos($_SERVER['REQUEST_URI'], '/cart') !== false) return true;
        if (strpos($_SERVER['REQUEST_URI'], '/checkout') !== false) return true;
        if (strpos($_SERVER['REQUEST_URI'], '/my-account') !== false) return true;
        if (strpos($_SERVER['REQUEST_URI'], 'wc-ajax=') !== false) return true;
        if (strpos($_SERVER['REQUEST_URI'], 'wp-json') !== false) return true;
        if (strpos($_SERVER['REQUEST_URI'], 'rest_route=') !== false) return true;
        
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
        if ($this->is_bypass_request()) return;
        if (isset($_GET['nocache'])) return;
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') return;

        $uri = $_SERVER['REQUEST_URI'] ?: '/';
        $cache_key = 'hyper_matrix:' . md5($_SERVER['HTTP_HOST'] . $uri);
        $cached_content = $this->connector->get($cache_key);

        if ($cached_content) {
            header('X-Omega-Status: HYPER-HIT');
            header('X-Omega-Key: ' . $cache_key);
            header('Content-Type: text/html; charset=UTF-8');
            
            // Check if cached content is gzipped (starts with gzip magic bytes 0x1f 0x8b)
            $is_gzipped = (strlen($cached_content) >= 2 && ord($cached_content[0]) === 0x1f && ord($cached_content[1]) === 0x8b);
            
            if ($is_gzipped) {
                if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) {
                    header('Content-Encoding: gzip');
                    echo $cached_content;
                } else {
                    echo gzdecode($cached_content);
                }
            } else {
                echo $cached_content;
            }
            exit;
        }
    }

    public function start_buffer() {
        if ($this->is_bypass_request()) return;
        ob_start([$this, 'buffer_callback']);
    }

    public function buffer_callback($content) {
        if ($this->is_bypass_request()) return $content;
        
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
                if (strpos($clean_url, '/wp-content/') !== false || strpos($clean_url, '/wp-includes/') !== false) {
                    $local_subpath = '';
                    if (strpos($clean_url, '/wp-content/') !== false) {
                        $local_subpath = '/wp-content/' . explode('/wp-content/', $clean_url)[1];
                    } else if (strpos($clean_url, '/wp-includes/') !== false) {
                        $local_subpath = '/wp-includes/' . explode('/wp-includes/', $clean_url)[1];
                    }
                    
                    $filesystem_path = '/var/www/html' . $local_subpath;
                    
                    if (file_exists($filesystem_path)) {
                        $css_content = @file_get_contents($filesystem_path);
                        if ($css_content && strlen($css_content) < 80000) { // Limit 80KB na plik
                            $base_path = dirname($local_subpath) . '/';
                            
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
                            
                            // Agresywna minifikacja CSS
                            $css_content = preg_replace('!/\*[^*]*\*+([^/*][^*]*\*+)*/!', '', $css_content);
                            $css_content = str_replace(array("\r\n", "\r", "\n", "\t"), '', $css_content);
                            $css_content = preg_replace('/ {2,}/', ' ', $css_content);
                            $css_content = str_replace(array(' {', '{ '), '{', $css_content);
                            $css_content = str_replace(array(' }', '} ', ';}'), '}', $css_content);
                            $css_content = str_replace(array(' :', ': '), ':', $css_content);
                            $css_content = str_replace(array(' ,', ', '), ',', $css_content);
                            $css_content = str_replace(array(' ;', '; '), ';', $css_content);
                            
                            $inline_style = '<style id="omega-inlined-' . md5($clean_url) . '">' . $css_content . '</style>';
                            $content = str_replace($full_tag, $inline_style, $content);
                        }
                    }
                }
            }
        }

        $uri = $_SERVER['REQUEST_URI'] ?: '/';
        $cache_key = 'hyper_matrix:' . md5($_SERVER['HTTP_HOST'] . $uri);
        
        // Agresywny, ultra-bezpieczny defer dla skryptów w locie w HTML (uniwersalny parser)
        $content = preg_replace_callback('/<script\s+([^>]*)src=["\']([^"\']+\.js[^"\']*)["\']([^>]*)>/i', function($matches) {
            $attrs_before = $matches[1];
            $src = $matches[2];
            $attrs_after = $matches[3];
            @file_put_contents(__DIR__ . '/debug.log', "MATCHED SCRIPT: " . $src . "\n", FILE_APPEND);
            
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
        $current_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $http_ctx = stream_context_create(['http' => [
            'header' => "User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36\r\n",
            'timeout' => 5
        ]]);
        
        // --- 1A: Zewnętrzne CSS (link stylesheet) ---
        $content = preg_replace_callback('/<link([^>]*rel=["\']stylesheet["\'][^>]*)href=["\']([^"\']+)["\']([^>]*)\/?>/i', function($m) use ($current_host, $http_ctx) {
            $before = $m[1]; $url = html_entity_decode($m[2]); $after = $m[3];
            
            // Sprawdź czy to URL zewnętrzny (http/https z innym hostem)
            $parsed = parse_url($url);
            if (!isset($parsed['host'])) return $m[0]; // relatywny URL = lokalny
            if (stripos($parsed['host'], 'localhost') !== false || strpos($parsed['host'], '127.0.0.1') !== false) return $m[0];
            if (stripos($parsed['host'], $current_host) !== false) return $m[0];
            
            // To jest zewnętrzny CSS! Pobierz do OmegaDrive
            $url_hash = md5($url);
            $ext = pathinfo($parsed['path'] ?? 'style.css', PATHINFO_EXTENSION) ?: 'css';
            $local_path = '/omega-ext/' . $url_hash . '.' . $ext;
            $cache_key = 'asset:' . $local_path;
            
            $cached = $this->connector->get($cache_key);
            if (!$cached) {
                $css_data = @file_get_contents($url, false, $http_ctx);
                if ($css_data) {
                    // Parsuj url() wewnątrz CSS (fonty, obrazki) i pobierz je też
                    preg_match_all('/url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $css_data, $sub_urls);
                    foreach ($sub_urls[1] as $sub_url) {
                        $sub_url = trim($sub_url);
                        if (strpos($sub_url, 'data:') === 0) continue; // skip data URIs
                        
                        // Rozwiąż relatywny URL
                        if (strpos($sub_url, '//') === 0) $sub_url = 'https:' . $sub_url;
                        elseif (strpos($sub_url, 'http') !== 0) continue;
                        
                        $sub_data = @file_get_contents($sub_url, false, $http_ctx);
                        if ($sub_data) {
                            $sub_hash = md5($sub_url);
                            $sub_ext = pathinfo(parse_url($sub_url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'bin';
                            $sub_local = '/omega-ext/' . $sub_hash . '.' . $sub_ext;
                            $this->connector->set('asset:' . $sub_local, $sub_data);
                            $css_data = str_replace($sub_url, $sub_local, $css_data);
                        }
                    }
                    
                    // Dodaj font-display: swap jeśli brak
                    if (strpos($css_data, '@font-face') !== false && strpos($css_data, 'font-display') === false) {
                        $css_data = preg_replace('/@font-face\s*\{/', '@font-face { font-display: swap;', $css_data);
                    }
                    
                    $this->connector->set($cache_key, $css_data);
                    $cached = $css_data;
                }
            }
            
            if ($cached) {
                // Inline mały CSS (< 10KB), reszta rewrite na lokalny path
                if (strlen($cached) < 10240) {
                    return '<style id="omega-ext-' . substr($url_hash, 0, 8) . '">' . $cached . '</style>';
                }
                return '<link' . $before . 'href="' . $local_path . '"' . $after . '/>';
            }
            return $m[0]; // fallback: nie ruszaj oryginału
        }, $content);
        
        // --- 1B: Zewnętrzne JS (script src) ---
        $content = preg_replace_callback('/<script([^>]*)src=["\']([^"\']+)["\']([^>]*)>/i', function($m) use ($current_host, $http_ctx) {
            $before = $m[1]; $url = html_entity_decode($m[2]); $after = $m[3];
            
            $parsed = parse_url($url);
            if (!isset($parsed['host'])) return $m[0];
            if (stripos($parsed['host'], 'localhost') !== false || strpos($parsed['host'], '127.0.0.1') !== false) return $m[0];
            if (stripos($parsed['host'], $current_host) !== false) return $m[0];
            
            // Zewnętrzny JS! Pobierz do OmegaDrive
            $url_hash = md5($url);
            $local_path = '/omega-ext/' . $url_hash . '.js';
            $cache_key = 'asset:' . $local_path;
            
            $cached = $this->connector->get($cache_key);
            if (!$cached) {
                $js_data = @file_get_contents($url, false, $http_ctx);
                if ($js_data) {
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
            if (strpos($attrs, 'omega-ext') !== false) return $tag;
            
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
        $content = preg_replace_callback('/<img\s+([^>]+)>/i', function($m) {
            $attrs_str = $m[1];
            
            // Ultra-fast Next-Gen WebP rewriting for all image source attributes
            $attrs_str = preg_replace_callback('/(src|srcset|data-src|data-srcset|data-lazy-src)=["\']([^"\']+)["\']/i', function($attr_match) {
                $attr_name = $attr_match[1];
                $attr_val = $attr_match[2];
                if (stripos($attr_val, '/wp-content/uploads/') !== false) {
                    $attr_val = preg_replace('/\.jpe?g(\b|\?)/i', '.webp$1', $attr_val);
                    $attr_val = preg_replace('/\.png(\b|\?)/i', '.webp$1', $attr_val);
                }
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
            
            // Auto loading="lazy"
            if (stripos($attrs_str, 'loading=') === false) {
                $attrs_str .= ' loading="lazy"';
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
                    $filename = basename(parse_url($src_matches[1], PHP_URL_PATH));
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
                        $parsed_img = parse_url($img_url);
                        $local_img_path = ABSPATH . ltrim($parsed_img['path'] ?? '', '/');
                        if (file_exists($local_img_path) && $size = @getimagesize($local_img_path)) {
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
        // Znajduje główny obrazek produktu na stronie (wp-post-image), usuwa lazy load, dodaje wysoki priorytet
        // i wstrzykuje tag preloading do sekcji <head>, dzięki czemu przeglądarka pobiera go natychmiast
        if (preg_match_all('/<img[^>]+>/i', $content, $img_matches)) {
            foreach ($img_matches[0] as $img_tag) {
                if (strpos($img_tag, 'wp-post-image') !== false) {
                    if (preg_match('/src=["\']([^"\']+)["\']/i', $img_tag, $src_match)) {
                        $img_url = html_entity_decode($src_match[1]);
                        
                        // Wyciągamy srcset i sizes do responsywnego preloadera
                        $srcset_attr = '';
                        if (preg_match('/srcset=["\']([^"\']+)["\']/i', $img_tag, $srcset_match)) {
                            $srcset_attr = ' imagesrcset="' . esc_attr(html_entity_decode($srcset_match[1])) . '"';
                        }
                        $sizes_attr = '';
                        if (preg_match('/sizes=["\']([^"\']+)["\']/i', $img_tag, $sizes_match)) {
                            $sizes_attr = ' imagesizes="' . esc_attr(html_entity_decode($sizes_match[1])) . '"';
                        }
                        
                        // Usuń loading="lazy" lub loading='lazy' (również z ukośnikiem)
                        $new_img_tag = preg_replace('/\s*\/?\s*loading=["\']lazy["\']/i', '', $img_tag);
                        // Dodaj fetchpriority="high"
                        if (strpos($new_img_tag, 'fetchpriority') === false) {
                            $new_img_tag = str_replace('<img', '<img fetchpriority="high"', $new_img_tag);
                        }
                        $content = str_replace($img_tag, $new_img_tag, $content);
                        
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
                        break;
                    }
                }
            }
        }

        if ($this->connector->connection_error) {
            @file_put_contents(__DIR__ . '/debug.log', "3a. CONNECTION ERROR: " . $this->connector->connection_error . "\n", FILE_APPEND);
            return $content . "\n<!-- OmegaDrive Hyper-Early Cache | ERROR: " . $this->connector->connection_error . " -->";
        }
        
        $gzipped_content = gzencode($content, 9);
        $this->connector->set($cache_key, $gzipped_content);
        @file_put_contents(__DIR__ . '/debug.log', "4. CACHED SUCCESSFULLY. KEY: " . $cache_key . "\n", FILE_APPEND);
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
}

new Omega_Ecommerce_Pro();
