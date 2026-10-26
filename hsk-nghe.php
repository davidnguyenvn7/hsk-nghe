<?php
/**
 * Plugin Name:       HSK Nghe Thụ Động
 * Description:       Nghe thụ động từ vựng HSK 2–3 và mẫu câu theo chủ đề. Import gói nội dung (JSON + audio) từ repo hsk-nghe-thu-dong.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            HSK Nghe
 * Text Domain:       hsk-nghe
 */

defined('ABSPATH') || exit;

define('HSKN_VERSION', '0.1.0');
define('HSKN_FILE', __FILE__);
define('HSKN_DIR', plugin_dir_path(__FILE__));
define('HSKN_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'HSKN\\')) {
        return;
    }
    $path = HSKN_DIR . 'src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

register_activation_hook(__FILE__, [HSKN\Install\Schema::class, 'activate']);

add_action('plugins_loaded', static function (): void {
    HSKN\Plugin::instance()->boot();
});
