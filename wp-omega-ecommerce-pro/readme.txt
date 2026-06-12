=== OmegaDrive E-Commerce Pro ===
Contributors: exmoond
Tags: woocommerce, performance, cache, speed, omega-drive
Requires at least: 5.6
Tested up to: 7.0
Stable tag: 1.5.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Page caching and front-end optimization engine integrated with the OmegaDrive key-value database and a companion reverse-proxy server.

== Description ==

**OmegaDrive E-Commerce Pro** is a caching and front-end optimization engine designed for WooCommerce stores. It integrates with the **OmegaDrive key-value database** and a companion reverse-proxy (written in Rust) to optimize page delivery and reduce load times.

Under standard benchmark testing, this architecture can serve up to **43,000+ Requests Per Second (RPS)** with a median latency of **under 9ms** on a single core.

### Key Features:
*   **Page Caching:** Serves pages directly from the OmegaDrive in-memory key-value store in gzipped binary format.
*   **Automatic CSS Inliner:** Detects critical and non-critical stylesheets and dynamically inlines them into the HTML, reducing render-blocking requests and improving First Contentful Paint (FCP).
*   **Speculative Preloading:** Integrates a preloader module (instant.page) that initiates page prefetching on user hover/touchstart.
*   **Next-Gen Image Optimization:** Automates rewriting of upload image source URLs to WebP format.
*   **LCP Fetchpriority Optimization:** Identifies post/product featured images and injects responsive preload links into the `<head>` tag with `fetchpriority="high"`, reducing Largest Contentful Paint (LCP) time.
*   **Aria-Label & Accessibility Injection:** Automates empty tags and missing WooCommerce accessibility fields cleanup to improve accessibility scores.

== Installation ==

Installing **OmegaDrive E-Commerce Pro** requires both the WordPress plugin and the companion reverse-proxy daemon running.

### 1. WordPress Plugin Setup
1. Upload the `wp-omega-ecommerce-pro` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Settings -> OmegaDrive** in your WordPress dashboard.
4. Set the Matrix IP Address where your OmegaDrive database daemon is running (Default is `127.0.0.1` for native local environments or `172.19.0.1` inside Docker networks) and save.

### 2. OmegaDrive Daemon & Companion Reverse Proxy Setup
1. Make sure the OmegaDrive database is running and listening on port `6380`.
2. Compile and run the companion Reverse Proxy server:
   `cargo build --release --bin neural_web_server`
3. Launch the compiled proxy daemon to route requests from port `8080` (entrance) to port `8081` (your Apache/Nginx backend):
   `nohup ./neural_web_server > proxy.log 2>&1 &`

== FAQ ==

= Do I need the separate Reverse Proxy to use this plugin? =
While the plugin will perform inlining and front-end optimizations on its own, the Hyper-Early cache hit performance (43K+ RPS) is only achieved when the companion `neural_web_server` Reverse Proxy is active to intercept incoming traffic before it hits Apache/PHP.

= How does the plugin handle dynamic checkout pages? =
The plugin has an integrated bypass engine (`is_bypass_request`) that automatically detects and excludes WooCommerce Cart, Checkout, My Account, AJAX fragments (`wc-ajax=`), and REST API requests from being cached, ensuring a fully transactional and bug-free shopping experience for customers.

== Troubleshooting Connection Issues ==

If the plugin indicates "OmegaDrive database disconnected", it means the WordPress server cannot reach the OmegaDrive daemon. Here are quick steps to resolve this:
1.  **Verify the Daemon is Running:**
    Ensure the Rust server is active:
    ```bash
    ps aux | grep neural_web_server
    ```
2.  **Check Network Reachability:**
    From your WordPress server (or Docker container), try to ping the database host:
    ```bash
    ping <omega_database_host>
    ```
    If using Docker, ensure the WordPress container can communicate with the OmegaDrive container (e.g., by using the internal Docker network alias).
3.  **Verify Port Access:**
    Use `telnet` or `nc` to check if port `6380` is open:
    ```bash
    nc -zv <omega_database_host> 6380
    ```

== Changelog ==

= 1.5.0 =
*   Introduced robust connection state tracking (RespResult) in the Reverse Proxy to eliminate connection lease leaks under extreme workloads.
*   Fixed caching validation issue where WordPress canonical redirections generated cache misses under specific host routing.
*   Optimized asset loading by injecting specpreloader module directly in the footers.
*   Improved WebP next-gen image rewriting regex.
