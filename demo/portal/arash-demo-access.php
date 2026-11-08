<?php
/** Demo-only routes and access limits for the public WordPress demo. */
function arash_demo_is_operator(): bool { return in_array('demo_operator', (array) wp_get_current_user()->roles, true); }
function arash_demo_throttle(string $bucket, int $limit, int $seconds, string $scope = ''): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'arash_demo_rl_' . md5($bucket . '|' . $ip . '|' . get_current_user_id() . '|' . $scope);
    $count = (int) get_transient($key);
    if ($count >= $limit) { status_header(429); header('Retry-After: ' . $seconds); wp_die('درخواست‌های پی‌درپی محدود شده‌اند؛ کمی بعد دوباره تلاش کنید.', 'محدودیت درخواست', ['response' => 429]); }
    set_transient($key, $count + 1, $seconds);
}
add_action('admin_menu', function () {
    if (!current_user_can('manage_woocommerce') || !class_exists('Arash_Bulk_Pricing')) return;
    global $submenu;
    foreach (($submenu['woocommerce'] ?? []) as $item) if (($item[2] ?? '') === 'arash-bulk-pricing') return;
    (new ReflectionClass('Arash_Bulk_Pricing'))->newInstanceWithoutConstructor()->admin_menu();
}, 99);
add_action('admin_menu', function () {
    if (!arash_demo_is_operator()) return;
    foreach (['wc-admin', 'coupons-moved', 'wc-settings', 'wc-status', 'wc-addons'] as $slug) remove_submenu_page('woocommerce', $slug);
    foreach (['woocommerce-marketing', 'RTL-RSM', 'export-personal-data.php', 'options-general.php'] as $slug) remove_menu_page($slug);
}, 1000);
add_action('admin_menu', function () {
    if (!class_exists('Arash_Bulk_Pricing')) return;
    global $wp_filter;
    $hook = 'woocommerce_page_arash-bulk-pricing';
    if (empty($wp_filter[$hook]->callbacks)) return;
    $kept_renderer = false;
    foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
        foreach ($callbacks as $entry) {
            $callback = $entry['function'] ?? null;
            if (!is_array($callback) || !($callback[0] ?? null) instanceof Arash_Bulk_Pricing || ($callback[1] ?? '') !== 'render_page') continue;
            if (!$kept_renderer) { $kept_renderer = true; continue; }
            remove_action($hook, $callback, $priority);
        }
    }
}, 1001);
add_action('admin_init', function () {
    if (!arash_demo_is_operator()) return;
    if (wp_doing_ajax()) {
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if (!in_array($action, ['arash_bulk_price_update', 'arash_bulk_price_filter'], true)) wp_die('این حساب فقط برای پیش‌نمایش افزونه است.', 'دسترسی محدود', ['response' => 403]);
        arash_demo_throttle($action === 'arash_bulk_price_update' ? 'update' : 'filter', $action === 'arash_bulk_price_update' ? 10 : 40, 60, $action);
        return;
    }
    if (($GLOBALS['pagenow'] ?? '') === 'admin.php' && sanitize_key(wp_unslash($_GET['page'] ?? '')) === 'arash-bulk-pricing') return;
    wp_safe_redirect(admin_url('admin.php?page=arash-bulk-pricing')); exit;
}, 1);
add_filter('authenticate', function ($user) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if ((int) get_transient('arash_demo_login_' . md5($ip)) >= 8) return new WP_Error('demo_login_throttled', 'تلاش‌های ورود زیاد بوده است؛ ۱۵ دقیقهٔ دیگر دوباره تلاش کنید.');
    return $user;
}, 30, 1);
add_action('wp_login_failed', function () {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown'; $key = 'arash_demo_login_' . md5($ip);
    set_transient($key, (int) get_transient($key) + 1, 15 * MINUTE_IN_SECONDS);
});
add_action('wp_login', function () { $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown'; delete_transient('arash_demo_login_' . md5($ip)); });
add_filter('xmlrpc_enabled', '__return_false');
add_filter('login_redirect', function ($redirect, $requested, $user) {
    return $user instanceof WP_User && in_array('demo_operator', (array) $user->roles, true) ? admin_url('admin.php?page=arash-bulk-pricing') : $redirect;
}, 10, 3);
function arash_demo_serve_static(string $file): void {
    $path = __DIR__ . '/' . $file;
    if (!is_readable($path)) { status_header(503); exit('Demo page is temporarily unavailable.'); }
    status_header(200); nocache_headers(); header('Content-Type: text/html; charset=UTF-8'); readfile($path); exit;
}
add_action('init', function () {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['arash_demo_entry'])) return;
    $provided = (string) wp_unslash($_POST['arash_demo_entry']);
    if (!hash_equals('Q3GvTi23-2FZaonM3ntN6rlbPUtfgX3AxYJIRMR-LAc', $provided)) { status_header(403); wp_die('پیوند ورود به دمو معتبر نیست.', 'دسترسی محدود', ['response' => 403]); }
    $users = get_users(['role' => 'demo_operator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC']);
    if (!$users) { status_header(503); wp_die('حساب آزمایشی آماده نیست.', 'دمو در دسترس نیست', ['response' => 503]); }
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown'; $key = 'arash_demo_entry_' . md5($ip); $count = (int) get_transient($key);
    if ($count >= 60) { status_header(429); header('Retry-After: 60'); wp_die('درخواست‌های پی‌درپی محدود شده‌اند؛ کمی بعد دوباره تلاش کنید.', 'محدودیت درخواست', ['response' => 429]); }
    set_transient($key, $count + 1, MINUTE_IN_SECONDS);
    wp_set_current_user($users[0]->ID); wp_set_auth_cookie($users[0]->ID, true, is_ssl());
    wp_safe_redirect(admin_url('admin.php?page=arash-bulk-pricing')); exit;
}, 0);
add_action('template_redirect', function () {
    if (is_admin()) return;
    $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    if ($path === '') arash_demo_serve_static('index.html');
    if ($path === 'arash-bulk-pricing') arash_demo_serve_static('product-demo.html');
}, 0);
