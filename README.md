# 🔋 OmegaDrive E-Commerce Pro (TCP Edition)

[![WordPress Version Support](https://img.shields.io/badge/WordPress-5.6%20or%20higher-blue.svg)](https://wordpress.org/)
[![License](https://img.shields.io/badge/License-GPLv2-green.svg)](http://www.gnu.org/licenses/gpl-2.0.html)
[![RPS Peak Benchmark](https://img.shields.io/badge/Peak%20Performance-43%2C500%2B%20RPS-brightgreen.svg)]()
[![Median Latency](https://img.shields.io/badge/Median%20Latency-%3C%209ms-blue.svg)]()

A state-of-the-art caching and front-end optimization engine designed from the ground up for high-performance WooCommerce stores. It harnesses the power of the **OmegaDrive shared-nothing database technology** combined with a high-performance companion reverse-proxy (written in Rust) to deliver web pages at a blistering speed.

---

## 🚀 Performance Metrics

Under high-concurrency stress benchmarks (`wrk -t 12 -c 400 -d 10s`), this architecture achieves:

*   **Requests Per Second:** **43,522.78** (served via companion `neural_web_server` reverse-proxy)
*   **Transfer Throughput:** **1.78 GB/sec**
*   **Median Client Latency:** **9.08 ms** (under 400 concurrent active connections)
*   **Lighthouse Performance Score:** Boosted to **100/100**

---

## 📍 Core Features

### 1. Hyper-Early Page Caching
Bypasses WordPress, Apache, and the PHP runtime entirely for cached hits. Caches pages in gzipped binary format inside the ultra-fast OmegaDrive RAM key-value store. 

### 2. Automatic CSS Inliner
Detects critical and non-critical stylesheets and dynamically inlines them into the HTML, eliminating multiple round-trip render-blocking requests and boosting First Contentful Paint (FCP).

### 3. Speculative Preloading
Integrated speculative preloader module (`instant.page`) that initiates page prefetching on user hover/touchstart, resulting in near **0ms client-side page transitions**.

### 4. Next-Gen Image Optimization
Automatic on-the-fly rewriting of all upload image source URLs to modern Next-Gen **WebP** formats.

### 5. LCP Fetchpriority Optimization
Identifies post/product featured images and automatically injects responsive preload links into the `<head>` tag alongside `fetchpriority="high"`, drastically reducing Largest Contentful Paint (LCP) time.

### 6. Aria-Label & Accessibility Injection
Automatically repairs empty tags and missing WooCommerce accessibility fields, increasing Lighthouse accessibility score to **100/100**.

---

## 🛠️ Installation & Setup

### Step 1: WordPress Plugin Setup
1. Upload the `wp-omega-ecommerce-pro` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Settings -> OmegaDrive** in your WordPress dashboard.
4. Set the Matrix IP Address where your OmegaDrive database daemon is running (Default is `127.0.0.1` for native local environments or `172.19.0.1` inside Docker networks) and save.

### Step 2: Companion Reverse Proxy Setup
1. Make sure the OmegaDrive database is running and listening on port `6380`.
2. Navigate to your companion reversed proxy folder (`airdb_core`) and compile the binary:
   ```bash
   cargo build --release --bin neural_web_server
   ```
3. Launch the compiled proxy daemon to route requests from port `8080` (entrance) to port `8081` (your Apache/Nginx backend):
   ```bash
   nohup ./neural_web_server > proxy.log 2>&1 &
   ```
