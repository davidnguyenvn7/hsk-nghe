<?php

namespace HSKN\Support;

defined('ABSPATH') || exit;

/**
 * Cập nhật plugin từ GitHub (plugin không nằm trên WordPress.org nên WP không tự biết có bản mới).
 *
 * - Đọc "Version:" trong hsk-nghe.php ở nhánh main của repo public; cache 6 giờ.
 * - Bản trên GitHub mới hơn → trang Plugins hiện "Có bản cập nhật", bấm Update như plugin thường.
 * - Gói tải về là zip của nhánh main (thư mục gốc "hsk-nghe-main") → đổi tên về đúng thư mục đang cài.
 *
 * Phát hành bản mới = tăng "Version:" + HSKN_VERSION trong hsk-nghe.php rồi push lên main.
 */
final class Updater
{
    public const REPO = 'davidnguyenvn7/hsk-nghe';
    public const BRANCH = 'main';
    private const CACHE = 'hskn_update_check';

    private string $basename;

    public function __construct()
    {
        $this->basename = plugin_basename(HSKN_FILE);
    }

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject']);
        add_filter('plugins_api', [$this, 'info'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'fixFolder'], 10, 4);
        add_filter('plugin_action_links_' . $this->basename, [$this, 'links']);
        add_action('admin_post_hskn_check_update', [$this, 'checkNow']);
        add_action('upgrader_process_complete', static fn() => delete_site_transient(self::CACHE));
        add_action('admin_notices', [$this, 'notice']);
    }

    /** Kết quả của link "Kiểm tra cập nhật" trên trang Plugins. */
    public function notice(): void
    {
        $state = isset($_GET['hskn_update']) ? sanitize_key(wp_unslash($_GET['hskn_update'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $remote = isset($_GET['hskn_remote']) ? sanitize_text_field(wp_unslash($_GET['hskn_remote'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $text = [
            'new'    => ['warning', "HSK Nghe: có bản mới $remote (đang dùng " . HSKN_VERSION . '). Bấm "Update now" ở dòng plugin bên dưới.'],
            'latest' => ['success', 'HSK Nghe: đang dùng bản mới nhất (' . HSKN_VERSION . ').'],
            'error'  => ['error', 'HSK Nghe: không đọc được phiên bản trên GitHub, thử lại sau.'],
        ][$state] ?? null;
        if ($text) {
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($text[0]), esc_html($text[1]));
        }
    }

    /** Phiên bản trên GitHub (null nếu không đọc được). */
    public function remoteVersion(bool $force = false): ?string
    {
        $cached = get_site_transient(self::CACHE);
        if (!$force && is_array($cached)) {
            return $cached['version'];
        }
        $res = wp_remote_get(sprintf('https://raw.githubusercontent.com/%s/%s/hsk-nghe.php', self::REPO, self::BRANCH), ['timeout' => 10]);
        $version = null;
        if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200
            && preg_match('/^\s*\*\s*Version:\s*([0-9][0-9.]*)/mi', wp_remote_retrieve_body($res), $m)) {
            $version = $m[1];
        }
        // lỗi mạng: thử lại sau 1 giờ thay vì 6 giờ
        set_site_transient(self::CACHE, ['version' => $version, 'checked' => time()], $version ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
        return $version;
    }

    private function package(): string
    {
        return sprintf('https://github.com/%s/archive/refs/heads/%s.zip', self::REPO, self::BRANCH);
    }

    public function inject($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }
        $remote = $this->remoteVersion();
        $item = (object) [
            'id'           => 'github.com/' . self::REPO,
            'slug'         => dirname($this->basename),
            'plugin'       => $this->basename,
            'new_version'  => $remote ?: HSKN_VERSION,
            'url'          => 'https://github.com/' . self::REPO,
            'package'      => $this->package(),
            'requires_php' => '8.1',
            'icons'        => [],
            'banners'      => [],
        ];
        if ($remote && version_compare($remote, HSKN_VERSION, '>')) {
            $transient->response[$this->basename] = $item;
            unset($transient->no_update[$this->basename]);
        } else {
            // có mặt trong no_update thì WP mới hiện nút bật "tự động cập nhật"
            $transient->no_update[$this->basename] = $item;
            unset($transient->response[$this->basename]);
        }
        return $transient;
    }

    /** Cửa sổ "Xem chi tiết" của bản cập nhật. */
    public function info($result, $action, $args)
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== dirname($this->basename)) {
            return $result;
        }
        $remote = $this->remoteVersion() ?: HSKN_VERSION;
        return (object) [
            'name'          => 'HSK Nghe Thụ Động',
            'slug'          => dirname($this->basename),
            'version'       => $remote,
            'author'        => 'HSK Nghe',
            'homepage'      => 'https://github.com/' . self::REPO,
            'requires_php'  => '8.1',
            'requires'      => '6.4',
            'download_link' => $this->package(),
            'sections'      => [
                'description' => 'Nghe thụ động từ vựng HSK 2–3 và mẫu câu theo chủ đề.',
                'changelog'   => sprintf('<p>Xem các thay đổi tại <a href="https://github.com/%1$s/commits/%2$s" target="_blank">github.com/%1$s/commits</a>.</p>', self::REPO, self::BRANCH),
            ],
        ];
    }

    /** Zip của GitHub giải nén ra "hsk-nghe-main/" → đổi về đúng tên thư mục plugin đang cài. */
    public function fixFolder($source, $remoteSource, $_upgrader, $extra = [])
    {
        if (($extra['plugin'] ?? '') !== $this->basename || is_wp_error($source)) {
            return $source;
        }
        global $wp_filesystem;
        $want = trailingslashit($remoteSource) . dirname($this->basename) . '/';
        if (untrailingslashit($source) === untrailingslashit($want)) {
            return $source;
        }
        if (!is_file(trailingslashit($source) . 'hsk-nghe.php')) {
            return new \WP_Error('hskn_update', 'Gói cập nhật không đúng cấu trúc plugin HSK Nghe.');
        }
        return $wp_filesystem && $wp_filesystem->move($source, $want, true) ? $want : $source;
    }

    public function links(array $links): array
    {
        $url = wp_nonce_url(admin_url('admin-post.php?action=hskn_check_update'), 'hskn_check_update');
        $links[] = '<a href="' . esc_url($url) . '">Kiểm tra cập nhật</a>';
        return $links;
    }

    public function checkNow(): void
    {
        check_admin_referer('hskn_check_update');
        if (!current_user_can('update_plugins')) {
            wp_die('Không đủ quyền.');
        }
        $remote = $this->remoteVersion(true);
        delete_site_transient('update_plugins');
        wp_update_plugins();
        $msg = $remote === null ? 'error' : (version_compare($remote, HSKN_VERSION, '>') ? 'new' : 'latest');
        wp_safe_redirect(add_query_arg(['hskn_update' => $msg, 'hskn_remote' => $remote], admin_url('plugins.php')));
        exit;
    }
}
