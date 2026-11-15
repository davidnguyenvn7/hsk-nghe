<?php

namespace HSKN\Pwa;

use HSKN\Shortcode\Player;

defined('ABSPATH') || exit;

/**
 * Cài trang nghe thành "app" trên điện thoại / máy tính (Progressive Web App):
 *   /?hskn=app        trang app toàn màn hình, không có header/footer của theme (start_url của manifest)
 *   /?hskn=manifest   web app manifest
 *   /?hskn=sw         service worker (phạm vi = thư mục gốc của site; chỉ can thiệp trang app và dữ liệu bài nghe)
 * Trang có shortcode [hskn_player] / [hskn_phan_tich] cũng gắn manifest, nên cài được từ đó.
 */
final class Pwa
{
    public function register(): void
    {
        add_action('template_redirect', [$this, 'route'], 0);
        add_action('wp_head', [$this, 'head']);
    }

    public static function url(string $what): string
    {
        return add_query_arg('hskn', $what, home_url('/'));
    }

    public static function scope(): string
    {
        return trailingslashit((string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?: '/'));
    }

    public static function icon(string $name): string
    {
        return HSKN_URL . 'assets/icons/' . $name;
    }

    public static function config(): array
    {
        return ['sw' => self::url('sw'), 'scope' => self::scope(), 'app' => self::url('app')];
    }

    public function route(): void
    {
        $what = isset($_GET['hskn']) && is_string($_GET['hskn']) ? sanitize_key(wp_unslash($_GET['hskn'])) : ''; // phpcs:ignore
        match ($what) {
            'app'      => $this->app(),
            'manifest' => $this->manifest(),
            'sw'       => $this->sw(),
            default    => null,
        };
    }

    private static function tags(): string
    {
        return '<link rel="manifest" href="' . esc_url(self::url('manifest')) . '">' . "\n"
            . '<meta name="theme-color" content="#c0392b">' . "\n"
            . '<meta name="mobile-web-app-capable" content="yes">' . "\n"
            . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
            . '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n"
            . '<meta name="apple-mobile-web-app-title" content="HSK Nghe">' . "\n"
            . '<link rel="apple-touch-icon" href="' . esc_url(self::icon('apple-180.png')) . '">' . "\n";
    }

    /** Gắn manifest vào trang có shortcode của plugin. */
    public function head(): void
    {
        $post = get_post();
        if (is_singular() && $post && (has_shortcode($post->post_content, 'hskn_player') || has_shortcode($post->post_content, 'hskn_phan_tich'))) {
            echo self::tags(); // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }

    private function manifest(): void
    {
        $icons = [
            ['src' => self::icon('icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => self::icon('icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
            ['src' => self::icon('maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ];
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        echo wp_json_encode([
            'id'               => self::url('app'),
            'name'             => 'HSK Nghe — nghe tiếng Trung',
            'short_name'       => 'HSK Nghe',
            'description'      => 'Nghe thụ động từ vựng, mẫu câu tiếng Trung; tra câu, lưu sổ tay.',
            'lang'             => 'vi',
            'start_url'        => self::url('app'),
            'scope'            => self::scope(),
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#fbfaf7',
            'theme_color'      => '#c0392b',
            'icons'            => $icons,
            'shortcuts'        => [
                ['name' => 'Tra câu', 'url' => add_query_arg('view', 'lookup', self::url('app'))],
                ['name' => 'Sổ tay', 'url' => add_query_arg('view', 'notebook', self::url('app'))],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function sw(): void
    {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Service-Worker-Allowed: ' . self::scope());
        $vars = [
            'VERSION' => HSKN_VERSION . '.' . (int) @filemtime(HSKN_DIR . 'assets/sw.js') . '.' . (int) @filemtime(HSKN_DIR . 'assets/player.js'),
            'APP'     => self::url('app'),
            'ASSETS'  => wp_parse_url(HSKN_URL . 'assets/', PHP_URL_PATH),
            // permalink "thuần" (?rest_route=…) → sw.js nhận diện REST qua tham số rest_route
            'REST'    => str_contains(rest_url('hskn/v1/'), 'rest_route=') ? '' : wp_parse_url(rest_url('hskn/v1/'), PHP_URL_PATH),
        ];
        echo 'const HSKN_SW = ' . wp_json_encode($vars, JSON_UNESCAPED_SLASHES) . ";\n"; // phpcs:ignore
        readfile(HSKN_DIR . 'assets/sw.js');
        exit;
    }

    /** Trang app: chỉ có trình phát, tự chứa (không tải CSS/JS của theme). */
    private function app(): void
    {
        nocache_headers();
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);   // có nonce + thông tin người dùng: không cho plugin cache lưu trang
        }
        do_action('litespeed_control_set_nocache', 'hskn app');
        $config = Player::config(['app' => true]);
        $v = static fn(string $f) => esc_url(HSKN_URL . $f . '?ver=' . HSKN_VERSION . '.' . (int) @filemtime(HSKN_DIR . $f));
        header('Content-Type: text/html; charset=utf-8');
        ?><!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>HSK Nghe</title>
<?php echo self::tags(); // phpcs:ignore ?>
<link rel="icon" href="<?php echo esc_url(self::icon('icon-192.png')); ?>">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?php echo $v('assets/player.css'); // phpcs:ignore ?>">
<style>html,body{margin:0;background:#fbfaf7}@media (prefers-color-scheme:dark){html,body{background:#161513}}</style>
</head>
<body class="hskn-app-body">
<div class="hskn is-app" data-config="<?php echo esc_attr(wp_json_encode($config)); ?>"><div class="hskn-loading">Đang tải…</div><noscript>Cần bật JavaScript.</noscript></div>
<?php foreach (['assets/me.js', 'assets/analyze.js', 'assets/notebook.js', 'assets/player.js'] as $f) : ?>
<script src="<?php echo $v($f); // phpcs:ignore ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
        <?php
        exit;
    }
}
