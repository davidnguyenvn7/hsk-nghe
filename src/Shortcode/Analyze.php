<?php

namespace HSKN\Shortcode;

use HSKN\Rest\AnalyzeController;

defined('ABSPATH') || exit;

/**
 * [hskn_phan_tich]   ô nhập câu → phân tích ý, từ vựng, pinyin, Hán Việt, loa đọc.
 * Trình phát [hskn_player] cũng mở được khung này (nút "Phân tích câu"), script chỉ tải khi bấm.
 */
final class Analyze
{
    public function register(): void
    {
        add_shortcode('hskn_phan_tich', [$this, 'render']);
    }

    /** Cấu hình cho HSKNAnalyze.mount(); null khi người xem không được dùng. */
    public static function config(): ?array
    {
        if (!AnalyzeController::allowed()) {
            return null;
        }
        return [
            'rest'   => esc_url_raw(rest_url('hskn/v1/analyze')),
            'nonce'  => wp_create_nonce('wp_rest'),
            'script' => esc_url_raw(add_query_arg('ver', self::ver('assets/analyze.js'), HSKN_URL . 'assets/analyze.js')),
        ];
    }

    public static function ver(string $file): string
    {
        return HSKN_VERSION . '.' . (int) @filemtime(HSKN_DIR . $file);
    }

    public function render(): string
    {
        $config = self::config();
        if (!$config) {
            return is_user_logged_in() ? '' : '<p>Hãy đăng nhập để dùng phần phân tích câu.</p>';
        }
        wp_enqueue_style('hskn-player', HSKN_URL . 'assets/player.css', [], self::ver('assets/player.css'));
        wp_enqueue_script('hskn-analyze', HSKN_URL . 'assets/analyze.js', [], self::ver('assets/analyze.js'), ['in_footer' => true, 'strategy' => 'defer']);
        return sprintf(
            '<div class="hskn hskn-an-page" data-analyze="%s"><div class="hskn-loading">Đang tải…</div><noscript>Cần JavaScript.</noscript></div>',
            esc_attr(wp_json_encode($config))
        );
    }
}
