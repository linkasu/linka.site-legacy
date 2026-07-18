<?php

$root = dirname(__DIR__);
$files = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile() && strpos($file->getPathname(), DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR) === false) {
        $files[] = $file->getPathname();
    }
}

foreach ($files as $file) {
    if (strpos($file, DIRECTORY_SEPARATOR . 'mu-plugins' . DIRECTORY_SEPARATOR) === false) {
        continue;
    }
    $content = file_get_contents($file);
    if (is_string($content) && preg_match('/webvisor\s*:\s*true/i', $content)) {
        fwrite(STDERR, "Webvisor-enable source marker found\n");
        exit(1);
    }
}

$vendor_paths = array_filter($files, static function ($file) {
    return strpos($file, 'wp-yandex-metrika') !== false && strpos($file, 'linka-legacy-analytics-control.php') === false;
});
if ($vendor_paths !== array()) {
    fwrite(STDERR, "Vendor plugin files must not be stored or patched in this repository\n");
    exit(1);
}

$plugin = file_get_contents($root . '/mu-plugins/linka-legacy-analytics-control.php');
foreach (['LINKA_LEGACY_VISITOR_CONSENT_COOKIE', 'LINKA_LEGACY_VISITOR_CSRF_COOKIE', 'linka_legacy_request_may_load_analytics', 'linka_legacy_validate_visitor_consent_request', 'HTTP_SEC_FETCH_SITE', 'HTTP_ORIGIN', 'random_bytes(32)', 'admin_post_nopriv_linka_legacy_set_visitor_consent'] as $required_marker) {
    if (!is_string($plugin) || strpos($plugin, $required_marker) === false) {
        fwrite(STDERR, "Visitor-level consent guard is missing\n");
        exit(1);
    }
}

$deploy = file_get_contents($root . '/scripts/deploy.sh');
$remote_lint = is_string($deploy) ? strpos($deploy, 'docker exec linka-php php -l') : false;
$atomic_install = is_string($deploy) ? strpos($deploy, 'mv -f') : false;
if (!is_string($deploy) || strpos($deploy, 'LINKA_LEGACY_RELEASE_GO') === false || strpos($deploy, 'XXXXXXXX.tmp') === false || $remote_lint === false || $atomic_install === false || $remote_lint > $atomic_install) {
    fwrite(STDERR, "Deploy must remote-lint a random temporary file before atomic install\n");
    exit(1);
}

fwrite(STDOUT, "Source policy tests passed\n");
