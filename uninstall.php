<?php
/**
 * Chỉ xoá dữ liệu khi admin đã bật "Xoá toàn bộ … khi gỡ plugin" trong Cài đặt.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

$settings = get_option('hskn_settings', []);
wp_clear_scheduled_hook('hskn_cleanup');
if (empty($settings['delete_on_uninstall'])) {
    return;
}

require_once __DIR__ . '/src/Install/Schema.php';
\HSKN\Install\Schema::drop();
foreach (['hskn_settings', 'hskn_data_version', 'hskn_import_job', 'hskn_import_lock'] as $opt) {
    delete_option($opt);
}

$dir = trailingslashit(wp_upload_dir(null, false)['basedir']) . 'hskn';
if (is_dir($dir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}
