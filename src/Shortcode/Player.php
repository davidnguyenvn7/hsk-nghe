<?php

namespace HSKN\Shortcode;

use HSKN\Support\Ids;
use HSKN\Support\Settings;

defined('ABSPATH') || exit;

/**
 * [hskn_player]                       thư viện bài nghe + trình phát (đọc ?track= trên URL)
 * [hskn_player track="topic-03"]      mở sẵn một bài
 * [hskn_player group="03"]            chỉ hiện bài của chủ đề 03 (group="hsk2" | "hsk3" cho từ HSK)
 *
 * JS/CSS chỉ được nạp ở trang có shortcode.
 */
final class Player
{
    public function register(): void
    {
        add_shortcode('hskn_player', [$this, 'render']);
    }

    public function render($atts): string
    {
        $atts = shortcode_atts(['track' => '', 'group' => ''], $atts, 'hskn_player');
        $track = (string) $atts['track'];
        if (isset($_GET['track']) && is_string($_GET['track'])) { // phpcs:ignore WordPress.Security.NonceVerification
            $track = sanitize_key(wp_unslash($_GET['track'])); // phpcs:ignore
        }
        $s = Settings::all();
        $config = [
            'rest'          => esc_url_raw(rest_url('hskn/v1/')),
            'nonce'         => wp_create_nonce('wp_rest'),
            'track'         => Ids::isTrack($track) ? $track : '',
            'group'         => preg_match('/^(\d{2}|hsk[23])$/', (string) $atts['group']) ? $atts['group'] : '',
            'defaults'      => array_intersect_key($s, array_flip(Settings::LISTEN_KEYS)) + ['auto_next' => $s['auto_next']],
            'allowOverride' => $s['allow_user_override'],
        ];

        $ver = static fn(string $f) => HSKN_VERSION . '.' . (int) @filemtime(HSKN_DIR . $f);
        // Admin: nút "Tạo chủ đề" ngay trên trình phát. Script trình tạo chỉ được tải khi bấm.
        if (current_user_can('manage_options')) {
            $config['author'] = [
                'rest'     => esc_url_raw(rest_url('hskn/v1/admin/author')),
                'script'   => esc_url_raw(add_query_arg('ver', $ver('assets/admin-author.js'), HSKN_URL . 'assets/admin-author.js')),
                'settings' => esc_url_raw(admin_url('admin.php?page=hskn-settings')),
                'manage'   => esc_url_raw(admin_url('admin.php?page=hskn-content')),
            ];
        }
        wp_enqueue_style('hskn-player', HSKN_URL . 'assets/player.css', [], $ver('assets/player.css'));
        wp_enqueue_script('hskn-player', HSKN_URL . 'assets/player.js', [], $ver('assets/player.js'), ['in_footer' => true, 'strategy' => 'defer']);

        return sprintf(
            '<div class="hskn" data-config="%s"><div class="hskn-loading">Đang tải trình phát…</div><noscript>Trình phát cần JavaScript.</noscript></div>',
            esc_attr(wp_json_encode($config))
        );
    }
}
