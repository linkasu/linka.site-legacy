<?php
/**
 * Plugin Name: LINKa Legacy Analytics Control
 * Description: Fail-closed control for Yandex Metrica on the legacy linka.su site.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LINKA_LEGACY_ANALYTICS_OPTION', 'linka_legacy_analytics_preference');
define('LINKA_LEGACY_ANALYTICS_SETTINGS_SLUG', 'linka-legacy-analytics');
define('LINKA_LEGACY_YANDEX_PLUGIN', 'wp-yandex-metrika/wp-yandex-metrika.php');
define('LINKA_LEGACY_VISITOR_CONSENT_COOKIE', 'linka_analytics_consent');
define('LINKA_LEGACY_VISITOR_CONSENT_SECONDS', 15552000);
define('LINKA_LEGACY_VISITOR_CSRF_COOKIE', 'linka_analytics_csrf');
define('LINKA_LEGACY_VISITOR_CSRF_SECONDS', 3600);

add_filter('option_active_plugins', 'linka_legacy_filter_active_plugins', PHP_INT_MAX);
add_filter('site_option_active_sitewide_plugins', 'linka_legacy_filter_sitewide_plugins', PHP_INT_MAX);
add_filter('option_yam_options', 'linka_legacy_force_webvisor_off', PHP_INT_MAX);
add_filter('pre_update_option_yam_options', 'linka_legacy_force_webvisor_off', PHP_INT_MAX);
add_filter('sanitize_option_yam_options', 'linka_legacy_force_webvisor_off', PHP_INT_MAX);
add_action('plugins_loaded', 'linka_legacy_disable_loaded_yandex_frontend', PHP_INT_MAX);
add_action('wp_enqueue_scripts', 'linka_legacy_dequeue_yandex_assets', PHP_INT_MAX);
add_action('admin_notices', 'linka_legacy_first_run_notice');
add_action('admin_menu', 'linka_legacy_register_settings_page');
add_action('admin_post_linka_legacy_set_analytics', 'linka_legacy_save_preference');
add_action('admin_post_linka_legacy_set_visitor_consent', 'linka_legacy_save_visitor_consent');
add_action('admin_post_nopriv_linka_legacy_set_visitor_consent', 'linka_legacy_save_visitor_consent');
add_action('init', 'linka_legacy_ensure_visitor_csrf_cookie', 1);
add_action('wp_footer', 'linka_legacy_render_visitor_consent', PHP_INT_MAX);
add_action('send_headers', 'linka_legacy_send_consent_cache_headers', PHP_INT_MAX);
add_shortcode('linka_analytics_consent', 'linka_legacy_visitor_consent_html');

function linka_legacy_normalize_preference($value)
{
    return in_array($value, array('enabled', 'disabled'), true) ? $value : 'unknown';
}

function linka_legacy_get_preference()
{
    return linka_legacy_normalize_preference(get_option(LINKA_LEGACY_ANALYTICS_OPTION, 'unknown'));
}

function linka_legacy_analytics_available()
{
    return linka_legacy_get_preference() === 'enabled';
}

function linka_legacy_normalize_visitor_consent($value)
{
    return in_array($value, array('granted', 'denied'), true) ? $value : 'unknown';
}

function linka_legacy_get_visitor_consent()
{
    $value = isset($_COOKIE[LINKA_LEGACY_VISITOR_CONSENT_COOKIE]) && is_scalar($_COOKIE[LINKA_LEGACY_VISITOR_CONSENT_COOKIE])
        ? (string) $_COOKIE[LINKA_LEGACY_VISITOR_CONSENT_COOKIE]
        : '';

    return linka_legacy_normalize_visitor_consent($value);
}

function linka_legacy_visitor_settings_requested()
{
    $value = isset($_GET['linka_analytics_settings']) && is_scalar($_GET['linka_analytics_settings'])
        ? sanitize_key(wp_unslash((string) $_GET['linka_analytics_settings']))
        : '';

    return $value === '1';
}

function linka_legacy_request_may_load_analytics()
{
    return linka_legacy_analytics_available()
        && linka_legacy_get_visitor_consent() === 'granted'
        && !linka_legacy_visitor_settings_requested();
}

function linka_legacy_cookie_secure()
{
    return (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'production') || is_ssl();
}

function linka_legacy_valid_csrf_token($value)
{
    return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
}

function linka_legacy_set_private_cookie($name, $value, $expires)
{
    return setcookie($name, $value, array(
        'expires' => $expires,
        'path' => '/',
        'secure' => linka_legacy_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Strict',
    ));
}

function linka_legacy_get_visitor_csrf_token()
{
    $token = isset($_COOKIE[LINKA_LEGACY_VISITOR_CSRF_COOKIE]) && is_scalar($_COOKIE[LINKA_LEGACY_VISITOR_CSRF_COOKIE])
        ? (string) $_COOKIE[LINKA_LEGACY_VISITOR_CSRF_COOKIE]
        : '';

    return linka_legacy_valid_csrf_token($token) ? $token : '';
}

function linka_legacy_ensure_visitor_csrf_cookie()
{
    if (is_admin() || !linka_legacy_analytics_available() || linka_legacy_get_visitor_csrf_token() !== '') {
        return;
    }
    try {
        $token = bin2hex(random_bytes(32));
    } catch (Throwable $error) {
        return;
    }
    if (linka_legacy_set_private_cookie(LINKA_LEGACY_VISITOR_CSRF_COOKIE, $token, time() + LINKA_LEGACY_VISITOR_CSRF_SECONDS)) {
        $_COOKIE[LINKA_LEGACY_VISITOR_CSRF_COOKIE] = $token;
    }
}

function linka_legacy_origin($url)
{
    $parts = parse_url((string) $url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        return '';
    }
    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower((string) $parts['host']);
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

    return $scheme . '://' . $host . $port;
}

function linka_legacy_validate_visitor_consent_request(array $server, array $cookies, array $post)
{
    if (($server['REQUEST_METHOD'] ?? '') !== 'POST'
        || ($server['HTTP_SEC_FETCH_SITE'] ?? '') !== 'same-origin'
        || ($server['HTTP_SEC_FETCH_MODE'] ?? '') !== 'navigate'
        || ($server['HTTP_SEC_FETCH_DEST'] ?? '') !== 'document') {
        return false;
    }
    $expected_origin = linka_legacy_origin(home_url('/'));
    $request_origin = linka_legacy_origin($server['HTTP_ORIGIN'] ?? '');
    if ($expected_origin === '' || $request_origin === '' || !hash_equals($expected_origin, $request_origin)) {
        return false;
    }

    $cookie_token = isset($cookies[LINKA_LEGACY_VISITOR_CSRF_COOKIE]) && is_scalar($cookies[LINKA_LEGACY_VISITOR_CSRF_COOKIE])
        ? (string) $cookies[LINKA_LEGACY_VISITOR_CSRF_COOKIE]
        : '';
    $form_token = isset($post['visitor_csrf']) && is_scalar($post['visitor_csrf']) ? (string) $post['visitor_csrf'] : '';

    return linka_legacy_valid_csrf_token($cookie_token)
        && linka_legacy_valid_csrf_token($form_token)
        && hash_equals($cookie_token, $form_token);
}

function linka_legacy_filter_active_plugins($plugins)
{
    if (linka_legacy_request_may_load_analytics() || !is_array($plugins)) {
        return $plugins;
    }

    return array_values(array_filter($plugins, static function ($plugin) {
        return $plugin !== LINKA_LEGACY_YANDEX_PLUGIN;
    }));
}

function linka_legacy_filter_sitewide_plugins($plugins)
{
    if (linka_legacy_request_may_load_analytics() || !is_array($plugins)) {
        return $plugins;
    }

    unset($plugins[LINKA_LEGACY_YANDEX_PLUGIN]);
    return $plugins;
}

function linka_legacy_force_webvisor_off($options)
{
    if (!is_array($options) || !isset($options['counters']) || !is_array($options['counters'])) {
        return $options;
    }

    foreach ($options['counters'] as $index => $counter) {
        if (is_array($counter)) {
            $options['counters'][$index]['webvisor'] = 0;
        }
    }

    return $options;
}

function linka_legacy_disable_loaded_yandex_frontend()
{
    if (linka_legacy_request_may_load_analytics()) {
        return;
    }

    if (class_exists('YaMetrikaFrontend') && method_exists('YaMetrikaFrontend', 'getInstance')) {
        $frontend = YaMetrikaFrontend::getInstance();
        remove_action('init', array($frontend, 'onInit'), 1);
        remove_action('wp_enqueue_scripts', array($frontend, 'registerAssets'), 1);
        remove_action('wp_enqueue_scripts', array($frontend, 'registerInlineScripts'), 2);
        remove_action('wp_head', array($frontend, 'printPageCounters'), 15);
        remove_action('wp_footer', array($frontend, 'printPagePixels'), 1);
    }

    if (class_exists('YaMetrikaBackend') && method_exists('YaMetrikaBackend', 'getInstance')) {
        $backend = YaMetrikaBackend::getInstance();
        remove_action('wp_head', array($backend, 'addFrontendMeta'));
    }
}

function linka_legacy_dequeue_yandex_assets()
{
    if (linka_legacy_request_may_load_analytics()) {
        return;
    }

    wp_dequeue_script('wp-yandex-metrika_YmEc');
    wp_dequeue_script('wp-yandex-metrika_frontend');
}

function linka_legacy_first_run_notice()
{
    if (!current_user_can('manage_options') || linka_legacy_get_preference() !== 'unknown') {
        return;
    }

    $action = esc_url(admin_url('admin-post.php'));
    ?>
    <div class="notice notice-warning">
      <p><strong>Аналитика linka.su не настроена.</strong></p>
      <p>До явного выбора аналитика недоступна. Разрешение администратора только показывает посетителям выбор: счетчик загружается лишь после cookie-согласия конкретного посетителя. Вебвизор отключен постоянно.</p>
      <form method="post" action="<?php echo $action; ?>" style="display:inline-block;margin:0 0.5em 0.5em 0">
        <input type="hidden" name="action" value="linka_legacy_set_analytics">
        <input type="hidden" name="preference" value="enabled">
        <?php wp_nonce_field('linka_legacy_set_analytics'); ?>
        <?php submit_button('Разрешить посетителям выбор аналитики', 'primary', 'submit', false); ?>
      </form>
      <form method="post" action="<?php echo $action; ?>" style="display:inline-block;margin:0 0 0.5em">
        <input type="hidden" name="action" value="linka_legacy_set_analytics">
        <input type="hidden" name="preference" value="disabled">
        <?php wp_nonce_field('linka_legacy_set_analytics'); ?>
        <?php submit_button('Не использовать аналитику', 'secondary', 'submit', false); ?>
      </form>
    </div>
    <?php
}

function linka_legacy_register_settings_page()
{
    add_options_page(
        'Аналитика linka.su',
        'Аналитика linka.su',
        'manage_options',
        LINKA_LEGACY_ANALYTICS_SETTINGS_SLUG,
        'linka_legacy_render_settings_page'
    );
}

function linka_legacy_render_settings_page()
{
    if (!current_user_can('manage_options')) {
        wp_die('Недостаточно прав.');
    }

    $preference = linka_legacy_get_preference();
    $labels = array(
        'unknown' => 'Решение не принято, Метрика заблокирована',
        'disabled' => 'Аналитика недоступна посетителям',
        'enabled' => 'Посетителям доступен индивидуальный выбор аналитики',
    );
    ?>
    <div class="wrap">
      <h1>Аналитика linka.su</h1>
      <?php if (isset($_GET['updated']) && $_GET['updated'] === '1') : ?>
        <div class="notice notice-success is-dismissible"><p>Настройка сохранена.</p></div>
      <?php endif; ?>
      <p><strong>Текущее состояние:</strong> <?php echo esc_html($labels[$preference]); ?>.</p>
      <p>В состояниях «решение не принято» и «аналитика недоступна» официальный плагин Яндекс Метрики исключается из загрузки для всех посетителей.</p>
      <p>Разрешение выбора не включает счетчик глобально. Vendor-плагин загружается только в запросе посетителя с cookie-согласием <code>granted</code>. Вебвизор всегда принудительно выключен, vendor-файлы не изменяются.</p>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="linka_legacy_set_analytics">
        <?php wp_nonce_field('linka_legacy_set_analytics'); ?>
        <p>
          <button class="button button-primary" type="submit" name="preference" value="enabled">Разрешить посетителям выбор</button>
          <button class="button" type="submit" name="preference" value="disabled">Не использовать аналитику</button>
        </p>
      </form>
    </div>
    <?php
}

function linka_legacy_save_preference()
{
    if (!current_user_can('manage_options')) {
        wp_die('Недостаточно прав.', '', array('response' => 403));
    }
    check_admin_referer('linka_legacy_set_analytics');

    $raw_preference = isset($_POST['preference']) && is_scalar($_POST['preference'])
        ? sanitize_key(wp_unslash((string) $_POST['preference']))
        : '';
    $preference = linka_legacy_normalize_preference($raw_preference);
    if ($preference === 'unknown') {
        wp_die('Некорректное значение.', '', array('response' => 400));
    }

    update_option(LINKA_LEGACY_ANALYTICS_OPTION, $preference, false);
    wp_safe_redirect(add_query_arg(
        array('page' => LINKA_LEGACY_ANALYTICS_SETTINGS_SLUG, 'updated' => '1'),
        admin_url('options-general.php')
    ));
    exit;
}

function linka_legacy_save_visitor_consent()
{
    if (!linka_legacy_validate_visitor_consent_request($_SERVER, $_COOKIE, $_POST)) {
        wp_die('Некорректный источник запроса.', '', array('response' => 403));
    }
    check_admin_referer('linka_legacy_set_visitor_consent');
    $raw_consent = isset($_POST['consent']) && is_scalar($_POST['consent'])
        ? sanitize_key(wp_unslash((string) $_POST['consent']))
        : '';
    $consent = linka_legacy_normalize_visitor_consent($raw_consent);
    if ($consent === 'unknown' || !linka_legacy_analytics_available()) {
        wp_die('Некорректное значение.', '', array('response' => 400));
    }

    $cookie_set = linka_legacy_set_private_cookie(LINKA_LEGACY_VISITOR_CONSENT_COOKIE, $consent, time() + LINKA_LEGACY_VISITOR_CONSENT_SECONDS);
    if (!$cookie_set) {
        wp_die('Не удалось сохранить настройку cookie.', '', array('response' => 500));
    }
    $_COOKIE[LINKA_LEGACY_VISITOR_CONSENT_COOKIE] = $consent;
    try {
        $rotated_csrf = bin2hex(random_bytes(32));
    } catch (Throwable $error) {
        $rotated_csrf = '';
    }
    if ($rotated_csrf !== '' && linka_legacy_set_private_cookie(LINKA_LEGACY_VISITOR_CSRF_COOKIE, $rotated_csrf, time() + LINKA_LEGACY_VISITOR_CSRF_SECONDS)) {
        $_COOKIE[LINKA_LEGACY_VISITOR_CSRF_COOKIE] = $rotated_csrf;
    }

    $redirect = wp_get_referer();
    wp_safe_redirect(is_string($redirect) && $redirect !== '' ? remove_query_arg('linka_analytics_settings', $redirect) : home_url('/'));
    exit;
}

function linka_legacy_render_visitor_consent()
{
    echo linka_legacy_visitor_consent_html();
}

function linka_legacy_visitor_consent_html()
{
    if (!linka_legacy_analytics_available()) {
        return '';
    }

    $show_panel = linka_legacy_get_visitor_consent() === 'unknown' || linka_legacy_visitor_settings_requested();
    $csrf_token = linka_legacy_get_visitor_csrf_token();
    if ($show_panel && $csrf_token === '') {
        return '';
    }
    $request_uri = isset($_SERVER['REQUEST_URI']) && is_scalar($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '/';
    $settings_url = add_query_arg('linka_analytics_settings', '1', home_url($request_uri));

    ob_start();
    if ($show_panel) {
        ?>
        <aside class="linka-analytics-consent" role="dialog" aria-labelledby="linka-analytics-consent-title">
          <h2 id="linka-analytics-consent-title">Настройки аналитики</h2>
          <p>Разрешить Яндекс Метрике собирать обезличенную статистику посещения? Вебвизор и запись действий не используются. До согласия счетчик не загружается.</p>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="linka_legacy_set_visitor_consent">
            <input type="hidden" name="visitor_csrf" value="<?php echo esc_attr($csrf_token); ?>">
            <?php wp_nonce_field('linka_legacy_set_visitor_consent'); ?>
            <button type="submit" name="consent" value="granted">Разрешить аналитику</button>
            <button type="submit" name="consent" value="denied">Не разрешать</button>
          </form>
        </aside>
        <?php
    } else {
        ?>
        <a class="linka-analytics-settings-link" href="<?php echo esc_url($settings_url); ?>">Настройки аналитики</a>
        <?php
    }
    ?>
    <style>
      .linka-analytics-consent { position: fixed; z-index: 100000; right: 1rem; bottom: 1rem; max-width: 32rem; padding: 1rem; color: #1d2327; background: #fff; border: 0.125rem solid #2271b1; box-shadow: 0 0.5rem 2rem rgba(0,0,0,.2); }
      .linka-analytics-consent h2 { margin-top: 0; font-size: 1.25rem; }
      .linka-analytics-consent button { margin: 0.25rem 0.5rem 0.25rem 0; padding: 0.5rem 0.75rem; }
      .linka-analytics-settings-link { display: inline-block; margin: 0.5rem; font-size: 0.875rem; }
    </style>
    <?php

    return (string) ob_get_clean();
}

function linka_legacy_send_consent_cache_headers()
{
    if (!is_admin() && linka_legacy_analytics_available()) {
        nocache_headers();
        header('Vary: Cookie', false);
    }
}
