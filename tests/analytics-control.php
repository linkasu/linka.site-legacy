<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['linka_test_filters'] = array();
$GLOBALS['linka_test_actions'] = array();
$GLOBALS['linka_test_options'] = array();

function add_filter($hook, $callback)
{
    $GLOBALS['linka_test_filters'][$hook][] = $callback;
}

function add_action($hook, $callback)
{
    $GLOBALS['linka_test_actions'][$hook][] = $callback;
}

function add_shortcode($hook, $callback)
{
    $GLOBALS['linka_test_actions']['shortcode_' . $hook][] = $callback;
}

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['linka_test_options']) ? $GLOBALS['linka_test_options'][$name] : $default;
}

function sanitize_key($value)
{
    return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value));
}

function wp_unslash($value)
{
    return $value;
}

function home_url($path = '/')
{
    return 'https://linka.test' . $path;
}

function wp_get_environment_type()
{
    return 'production';
}

function is_ssl()
{
    return false;
}

require dirname(__DIR__) . '/mu-plugins/linka-legacy-analytics-control.php';

function analytics_assert_same($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$plugins = array('hello.php', LINKA_LEGACY_YANDEX_PLUGIN);
analytics_assert_same(array('hello.php'), linka_legacy_filter_active_plugins($plugins), 'Unknown must block Yandex plugin');

$GLOBALS['linka_test_options'][LINKA_LEGACY_ANALYTICS_OPTION] = 'disabled';
analytics_assert_same(array('hello.php'), linka_legacy_filter_active_plugins($plugins), 'Disabled must block Yandex plugin');

$network_plugins = array(LINKA_LEGACY_YANDEX_PLUGIN => 1, 'hello.php' => 1);
analytics_assert_same(array('hello.php' => 1), linka_legacy_filter_sitewide_plugins($network_plugins), 'Disabled must block network-active Yandex plugin');

$GLOBALS['linka_test_options'][LINKA_LEGACY_ANALYTICS_OPTION] = 'enabled';
unset($_COOKIE[LINKA_LEGACY_VISITOR_CONSENT_COOKIE]);
analytics_assert_same(array('hello.php'), linka_legacy_filter_active_plugins($plugins), 'Admin availability must not enable analytics globally');

$_COOKIE[LINKA_LEGACY_VISITOR_CONSENT_COOKIE] = 'granted';
analytics_assert_same($plugins, linka_legacy_filter_active_plugins($plugins), 'Granted visitor consent must allow Yandex plugin');
$_GET['linka_analytics_settings'] = '1';
analytics_assert_same(array('hello.php'), linka_legacy_filter_active_plugins($plugins), 'Reopened consent settings must suppress analytics for that request');
unset($_GET['linka_analytics_settings']);

$_COOKIE[LINKA_LEGACY_VISITOR_CONSENT_COOKIE] = 'denied';
analytics_assert_same(array('hello.php'), linka_legacy_filter_active_plugins($plugins), 'Denied visitor consent must block Yandex plugin');

$GLOBALS['linka_test_options'][LINKA_LEGACY_ANALYTICS_OPTION] = 'disabled';
$_COOKIE[LINKA_LEGACY_VISITOR_CONSENT_COOKIE] = 'granted';
analytics_assert_same(array('hello.php'), linka_legacy_filter_active_plugins($plugins), 'Disabled global availability must override visitor cookie');

$options = array('counters' => array(
    array('number' => '123', 'webvisor' => true),
    array('number' => '456'),
));
$sanitized = linka_legacy_force_webvisor_off($options);
analytics_assert_same(0, $sanitized['counters'][0]['webvisor'], 'Webvisor true was not removed');
analytics_assert_same(0, $sanitized['counters'][1]['webvisor'], 'Webvisor default was not forced off');
analytics_assert_same('unknown', linka_legacy_normalize_preference('invalid'), 'Invalid preference must fail closed');
analytics_assert_same('unknown', linka_legacy_normalize_visitor_consent('invalid'), 'Invalid visitor consent must fail closed');
analytics_assert_same(true, isset($GLOBALS['linka_test_filters']['option_active_plugins']), 'Active plugin guard was not registered');
analytics_assert_same(true, isset($GLOBALS['linka_test_filters']['option_yam_options']), 'Runtime Webvisor guard was not registered');
analytics_assert_same(true, isset($GLOBALS['linka_test_actions']['admin_notices']), 'First-run notice was not registered');
analytics_assert_same(true, isset($GLOBALS['linka_test_actions']['admin_post_nopriv_linka_legacy_set_visitor_consent']), 'Visitor consent handler was not registered');
analytics_assert_same(true, isset($GLOBALS['linka_test_actions']['send_headers']), 'Consent cache isolation was not registered');
analytics_assert_same(true, isset($GLOBALS['linka_test_actions']['init']), 'Visitor CSRF cookie bootstrap was not registered');

$csrf = str_repeat('a', 64);
$valid_server = array(
    'REQUEST_METHOD' => 'POST',
    'HTTP_SEC_FETCH_SITE' => 'same-origin',
    'HTTP_SEC_FETCH_MODE' => 'navigate',
    'HTTP_SEC_FETCH_DEST' => 'document',
    'HTTP_ORIGIN' => 'https://linka.test',
);
analytics_assert_same(true, linka_legacy_validate_visitor_consent_request($valid_server, array(LINKA_LEGACY_VISITOR_CSRF_COOKIE => $csrf), array('visitor_csrf' => $csrf)), 'Valid visitor-bound CSRF was rejected');
analytics_assert_same(false, linka_legacy_validate_visitor_consent_request(array_merge($valid_server, array('HTTP_SEC_FETCH_SITE' => 'cross-site')), array(LINKA_LEGACY_VISITOR_CSRF_COOKIE => $csrf), array('visitor_csrf' => $csrf)), 'Cross-site Fetch Metadata was accepted');
analytics_assert_same(false, linka_legacy_validate_visitor_consent_request(array_merge($valid_server, array('HTTP_ORIGIN' => 'https://attacker.test')), array(LINKA_LEGACY_VISITOR_CSRF_COOKIE => $csrf), array('visitor_csrf' => $csrf)), 'Foreign origin was accepted');
analytics_assert_same(false, linka_legacy_validate_visitor_consent_request(array_merge($valid_server, array('HTTP_SEC_FETCH_MODE' => 'cors')), array(LINKA_LEGACY_VISITOR_CSRF_COOKIE => $csrf), array('visitor_csrf' => $csrf)), 'Non-navigation Fetch Metadata was accepted');
analytics_assert_same(false, linka_legacy_validate_visitor_consent_request(array_diff_key($valid_server, array('HTTP_ORIGIN' => true)), array(LINKA_LEGACY_VISITOR_CSRF_COOKIE => $csrf), array('visitor_csrf' => $csrf)), 'Missing Origin was accepted');
analytics_assert_same(false, linka_legacy_validate_visitor_consent_request($valid_server, array(LINKA_LEGACY_VISITOR_CSRF_COOKIE => $csrf), array('visitor_csrf' => str_repeat('b', 64))), 'Mismatched double-submit token was accepted');
analytics_assert_same(true, linka_legacy_cookie_secure(), 'Production consent cookies were not forced Secure');

fwrite(STDOUT, "Analytics control tests passed\n");
