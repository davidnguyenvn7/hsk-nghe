<?php

namespace HSKN\Shortcode;

use HSKN\Pwa\Pwa;
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

    /**
     * Cấu hình cho player.js — dùng chung cho shortcode và trang app (/?hskn=app).
     * @param array $opt track, group, app
     */
    public static function config(array $opt = []): array
    {
        $track = (string) ($opt['track'] ?? '');
        if (isset($_GET['track']) && is_string($_GET['track'])) { // phpcs:ignore WordPress.Security.NonceVerification
            $track = sanitize_key(wp_unslash($_GET['track'])); // phpcs:ignore
        }
        $view = isset($_GET['view']) && is_string($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : ''; // phpcs:ignore
        $group = (string) ($opt['group'] ?? '');
        $s = Settings::all();
        $user = wp_get_current_user();
        $config = [
            'rest'          => esc_url_raw(rest_url('hskn/v1/')),
            'nonce'         => wp_create_nonce('wp_rest'),
            'ajax'          => esc_url_raw(admin_url('admin-ajax.php')),
            'track'         => Ids::isTrack($track) ? $track : '',
            'view'          => in_array($view, ['listen', 'lookup', 'notebook'], true) ? $view : '',
            'group'         => preg_match('/^(\d{2}|hsk[23])$/', $group) ? $group : '',
            'defaults'      => array_intersect_key($s, array_flip(Settings::LISTEN_KEYS)) + ['auto_next' => $s['auto_next']],
            'allowOverride' => $s['allow_user_override'],
            'app'           => !empty($opt['app']),
            'pwa'           => Pwa::config(),
            'user'          => $user->exists() ? [
                'id' => $user->ID, 'name' => $user->display_name, 'avatar' => get_avatar_url($user->ID, ['size' => 64]),
                'logout' => esc_url_raw(wp_logout_url(!empty($opt['app']) ? Pwa::url('app') : (get_permalink() ?: home_url('/')))),
            ] : null,
            'auth'          => [
                'register' => (bool) get_option('users_can_register'),
                'lost'     => esc_url_raw(wp_lostpassword_url()),
            ],
        ];
        // Admin: "Tạo chủ đề bằng AI" ngay trên trình phát. Script trình tạo chỉ được tải khi bấm.
        if (current_user_can('manage_options')) {
            $config['author'] = [
                'rest'     => esc_url_raw(rest_url('hskn/v1/admin/author')),
                'script'   => esc_url_raw(add_query_arg('ver', Analyze::ver('assets/admin-author.js'), HSKN_URL . 'assets/admin-author.js')),
                'settings' => esc_url_raw(admin_url('admin.php?page=hskn-settings')),
                'manage'   => esc_url_raw(admin_url('admin.php?page=hskn-content')),
            ];
        }
        if ($an = Analyze::config()) {
            $config['analyze'] = $an;
        }
        return $config;
    }

    /** JS của trình phát (me → analyze → notebook → player). */
    public static function enqueue(): void
    {
        wp_enqueue_style('hskn-player', HSKN_URL . 'assets/player.css', [], Analyze::ver('assets/player.css'));
        $args = ['in_footer' => true, 'strategy' => 'defer'];
        wp_enqueue_script('hskn-me', HSKN_URL . 'assets/me.js', [], Analyze::ver('assets/me.js'), $args);
        wp_enqueue_script('hskn-analyze', HSKN_URL . 'assets/analyze.js', ['hskn-me'], Analyze::ver('assets/analyze.js'), $args);
        wp_enqueue_script('hskn-notebook', HSKN_URL . 'assets/notebook.js', ['hskn-me'], Analyze::ver('assets/notebook.js'), $args);
        wp_enqueue_script('hskn-player', HSKN_URL . 'assets/player.js', ['hskn-me', 'hskn-analyze', 'hskn-notebook'], Analyze::ver('assets/player.js'), $args);
    }

    public function render($atts): string
    {
        $atts = shortcode_atts(['track' => '', 'group' => ''], $atts, 'hskn_player');
        self::enqueue();
        return sprintf(
            '<div class="hskn" data-config="%s"><div class="hskn-loading">Đang tải trình phát…</div><noscript>Trình phát cần JavaScript.</noscript></div>',
            esc_attr(wp_json_encode(self::config(['track' => $atts['track'], 'group' => $atts['group']])))
        );
    }
}
