<?php

namespace HSKN\Admin;

use HSKN\Import\ImportService;
use HSKN\Install\Schema;
use HSKN\Repository\AudioRepo;
use HSKN\Rest\AdminController;
use HSKN\Support\ApiSettings;
use HSKN\Support\Cache;
use HSKN\Support\Paths;
use HSKN\Support\Settings;

defined('ABSPATH') || exit;

final class AdminPages
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_post_hskn_upload', [$this, 'upload']);
        add_action('admin_post_hskn_clear_cache', [$this, 'clearCache']);
    }

    public function menu(): void
    {
        add_menu_page('HSK Nghe', 'HSK Nghe', 'manage_options', 'hskn', [$this, 'overview'], 'dashicons-format-audio', 58);
        add_submenu_page('hskn', 'Tổng quan', 'Tổng quan', 'manage_options', 'hskn', [$this, 'overview']);
        $import = add_submenu_page('hskn', 'Import nội dung', 'Import', 'manage_options', 'hskn-import', [$this, 'import']);
        $content = add_submenu_page('hskn', 'Quản lý nội dung', 'Quản lý nội dung', 'manage_options', 'hskn-content', [$this, 'content']);
        $author = add_submenu_page('hskn', 'Thêm nội dung', 'Thêm nội dung', 'manage_options', 'hskn-author', [$this, 'author']);
        add_action("load-$content", static function (): void {
            add_action('admin_enqueue_scripts', static function (): void {
                $ver = HSKN_VERSION . '.' . (int) @filemtime(HSKN_DIR . 'assets/admin-content.js');
                wp_enqueue_script('hskn-content', HSKN_URL . 'assets/admin-content.js', [], $ver, true);
                wp_localize_script('hskn-content', 'HSKN_CONTENT', [
                    'rest'   => esc_url_raw(rest_url('hskn/v1/admin/')),
                    'nonce'  => wp_create_nonce('wp_rest'),
                    'player' => esc_url_raw(self::playerUrl()),
                    'add'    => esc_url_raw(admin_url('admin.php?page=hskn-author')),
                ]);
            });
        });
        add_submenu_page('hskn', 'Cài đặt nghe', 'Cài đặt', 'manage_options', 'hskn-settings', [$this, 'settingsPage']);
        add_action("load-$author", static function (): void {
            add_action('admin_enqueue_scripts', static function (): void {
                $ver = HSKN_VERSION . '.' . (int) @filemtime(HSKN_DIR . 'assets/admin-author.js');
                wp_enqueue_script('hskn-author', HSKN_URL . 'assets/admin-author.js', [], $ver, true);
                wp_localize_script('hskn-author', 'HSKN_AUTHOR', [
                    'rest'     => esc_url_raw(rest_url('hskn/v1/admin/author')),
                    'nonce'    => wp_create_nonce('wp_rest'),
                    'player'   => esc_url_raw(self::playerUrl()),
                    'settings' => esc_url_raw(admin_url('admin.php?page=hskn-settings')),
                ]);
            });
        });
        add_action("load-$import", static function (): void {
            add_action('admin_enqueue_scripts', static function (): void {
                wp_enqueue_script('hskn-admin', HSKN_URL . 'assets/admin.js', [], HSKN_VERSION, true);
                wp_localize_script('hskn-admin', 'HSKN_ADMIN', [
                    'rest'  => esc_url_raw(rest_url('hskn/v1/admin/import')),
                    'nonce' => wp_create_nonce('wp_rest'),
                ]);
            });
        });
    }

    // ------------------------------------------------------------------ Tổng quan

    public function overview(): void
    {
        global $wpdb;
        $t = Schema::tables();
        $count = static fn(string $sql) => (int) $wpdb->get_var($sql); // phpcs:ignore
        $pack = $wpdb->get_row("SELECT * FROM {$t['packs']} WHERE is_active = 1 ORDER BY id DESC LIMIT 1", ARRAY_A); // phpcs:ignore
        $clips = Paths::dirSize(Paths::clips());
        $cache = Paths::dirSize(Paths::cache());
        $rows = [
            'Gói đang dùng'      => $pack ? esc_html($pack['version']) . ' · import ' . esc_html(get_date_from_gmt($pack['imported_at'], 'd/m/Y H:i')) : '<em>chưa import</em>',
            'Chủ đề'             => $count("SELECT COUNT(*) FROM {$t['topics']} WHERE is_active = 1"),
            'Từ HSK 2 / HSK 3'   => $count("SELECT COUNT(*) FROM {$t['words']} WHERE is_active = 1 AND id LIKE 'H2-%'") . ' / ' . $count("SELECT COUNT(*) FROM {$t['words']} WHERE is_active = 1 AND id LIKE 'H3-%'"),
            'Từ chuyên ngành'    => $count("SELECT COUNT(*) FROM {$t['words']} WHERE is_active = 1 AND id LIKE 'T%'"),
            'Mẫu câu (S, D)'     => $count("SELECT COUNT(*) FROM {$t['sentences']} WHERE is_active = 1 AND type IN ('S','D')"),
            'Câu theo từ (E)'    => $count("SELECT COUNT(*) FROM {$t['sentences']} WHERE is_active = 1 AND type = 'E'"),
            'Clip audio'         => number_format_i18n($clips['files']) . ' file · ' . size_format($clips['bytes']) . ' · ' . number_format_i18n((new AudioRepo())->count()) . ' liên kết',
            'Bài nghe đã ghép'   => number_format_i18n($cache['files']) . ' file · ' . size_format($cache['bytes']),
        ];
        ?>
        <div class="wrap">
            <h1>HSK Nghe Thụ Động</h1>
            <table class="widefat striped" style="max-width:760px">
                <tbody>
                <?php foreach ($rows as $k => $v) : ?>
                    <tr><th style="width:220px"><?php echo esc_html($k); ?></th><td><?php echo wp_kses_post((string) $v); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px">
                <input type="hidden" name="action" value="hskn_clear_cache">
                <?php wp_nonce_field('hskn_clear_cache'); ?>
                <button class="button">Xoá bài nghe đã ghép</button>
                <span class="description">Bài nghe được ghép lại tự động khi có người nghe.</span>
            </form>
            <h2>Shortcode</h2>
            <p><code>[hskn_player]</code> thư viện bài nghe + trình phát (đọc tham số <code>?track=</code> trên URL).</p>
            <p><code>[hskn_player track="topic-03"]</code> mở sẵn một bài. <code>[hskn_player group="03"]</code> chỉ hiện bài của chủ đề 03, <code>group="hsk2"</code> chỉ HSK 2.</p>
            <h2>Thêm nội dung</h2>
            <p><strong>Trực tiếp trong WP:</strong> trang <a href="<?php echo esc_url(admin_url('admin.php?page=hskn-author')); ?>">Thêm nội dung</a> — DeepSeek đề xuất từ và câu, bạn duyệt, plugin tạo giọng đọc.</p>
            <p><strong>Số lượng lớn:</strong> sinh ở repo <code>hsk-nghe-thu-dong</code>:
                <code>generate.py</code> → <code>build.py</code> → <code>make_audio.py</code> → <code>pack.py</code>, rồi import ở trang <a href="<?php echo esc_url(admin_url('admin.php?page=hskn-import')); ?>">Import</a> hoặc <code>wp hskn import</code>.</p>
        </div>
        <?php
    }

    public function clearCache(): void
    {
        check_admin_referer('hskn_clear_cache');
        if (!current_user_can('manage_options')) {
            wp_die('Không đủ quyền.');
        }
        $n = Cache::clearRendered();
        wp_safe_redirect(add_query_arg(['page' => 'hskn', 'cleared' => $n], admin_url('admin.php')));
        exit;
    }

    // ------------------------------------------------------------------ Import

    public function import(): void
    {
        Paths::ensureDirs();
        $max = wp_max_upload_size();
        $job = (new ImportService())->job();
        ?>
        <div class="wrap" id="hskn-import">
            <h1>Import gói nội dung</h1>
            <?php if (isset($_GET['uploaded'])) : // phpcs:ignore ?>
                <div class="notice notice-success"><p>Đã tải lên. Chọn gói bên dưới để import.</p></div>
            <?php elseif (isset($_GET['upload_error'])) : // phpcs:ignore ?>
                <div class="notice notice-error"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['upload_error']))); // phpcs:ignore ?></p></div>
            <?php endif; ?>

            <h2>1. Đưa gói lên server</h2>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="hskn_upload">
                <?php wp_nonce_field('hskn_upload'); ?>
                <input type="file" name="pack" accept=".zip" required>
                <button class="button">Tải lên</button>
                <span class="description">Tối đa <?php echo esc_html(size_format($max)); ?>.</span>
            </form>
            <p class="description"><strong>File gói lớn hơn giới hạn trên</strong> (ví dụ gói đầy đủ có audio ~220 MB): dùng <strong>File Manager</strong> của hosting
                (hoặc FileZilla/SFTP) để upload file <code>hskn-pack-….zip</code> vào thư mục dưới đây, rồi tải lại trang này:<br>
                <code><?php echo esc_html(Paths::incoming()); ?></code></p>

            <h2>2. Chọn gói để import</h2>
            <table class="widefat striped" style="max-width:760px">
                <thead><tr><th>Gói</th><th>Loại</th><th>Dung lượng</th><th></th></tr></thead>
                <tbody>
                <?php $list = AdminController::incoming(); ?>
                <?php if (!$list) : ?>
                    <tr><td colspan="4"><em>Chưa có gói nào trong thư mục incoming.</em></td></tr>
                <?php endif; ?>
                <?php foreach ($list as $p) : ?>
                    <tr>
                        <td><code><?php echo esc_html($p['name']); ?></code></td>
                        <td><?php echo $p['type'] === 'zip' ? 'zip' : 'thư mục'; ?></td>
                        <td><?php echo $p['size'] ? esc_html(size_format($p['size'])) : '–'; ?></td>
                        <td style="text-align:right">
                            <button class="button" data-hskn-dry="<?php echo esc_attr($p['name']); ?>">Kiểm tra</button>
                            <button class="button button-primary" data-hskn-import="<?php echo esc_attr($p['name']); ?>">Import</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p><label><input type="checkbox" id="hskn-skip-audio"> Bỏ qua audio (chỉ cập nhật chữ)</label></p>

            <h2>3. Tiến độ</h2>
            <div id="hskn-progress" style="max-width:760px">
                <progress id="hskn-bar" max="100" value="0" style="width:100%;height:18px"></progress>
                <p id="hskn-phase"><?php echo $job ? esc_html('Lần import gần nhất: ' . $job['version'] . ' · ' . $job['phase']) : 'Chưa chạy.'; ?></p>
                <pre id="hskn-log" style="background:#fff;border:1px solid #ccd0d4;padding:8px;max-height:280px;overflow:auto;white-space:pre-wrap"><?php echo $job ? esc_html(implode("\n", $job['log'] ?? [])) : ''; ?></pre>
                <button class="button" id="hskn-resume" <?php disabled(!$job || $job['phase'] === 'done'); ?>>Chạy tiếp</button>
                <button class="button" id="hskn-cancel" <?php disabled(!$job || $job['phase'] === 'done'); ?>>Huỷ</button>
            </div>
        </div>
        <?php
    }

    public function upload(): void
    {
        check_admin_referer('hskn_upload');
        if (!current_user_can('manage_options')) {
            wp_die('Không đủ quyền.');
        }
        $back = admin_url('admin.php?page=hskn-import');
        $file = $_FILES['pack'] ?? null; // phpcs:ignore
        $name = $file ? sanitize_file_name(wp_unslash($file['name'])) : '';
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || !str_ends_with(strtolower($name), '.zip')) {
            wp_safe_redirect(add_query_arg('upload_error', rawurlencode('Upload lỗi (mã ' . ($file['error'] ?? '?') . ') hoặc không phải file .zip.'), $back));
            exit;
        }
        Paths::ensureDirs();
        if (!move_uploaded_file($file['tmp_name'], Paths::incoming() . $name)) {
            wp_safe_redirect(add_query_arg('upload_error', rawurlencode('Không ghi được vào thư mục incoming.'), $back));
            exit;
        }
        wp_safe_redirect(add_query_arg('uploaded', 1, $back));
        exit;
    }

    // ------------------------------------------------------------------ Thêm nội dung

    /** Trang đầu tiên có shortcode [hskn_player] (để mở thử bài vừa tạo). */
    public static function playerUrl(): string
    {
        global $wpdb;
        $id = $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE '%[hskn_player%' ORDER BY ID LIMIT 1"); // phpcs:ignore
        return $id ? (string) get_permalink((int) $id) : home_url('/');
    }

    public function content(): void
    {
        ?>
        <div class="wrap">
            <h1>Quản lý nội dung</h1>
            <div id="hskn-content"><p>Đang tải…</p></div>
        </div>
        <?php
    }

    public function author(): void
    {
        ?>
        <div class="wrap">
            <h1>Thêm nội dung</h1>
            <p>DeepSeek đề xuất từ và câu → bạn duyệt → lưu → tạo giọng đọc. Nội dung tạo ở đây được giữ nguyên khi import gói từ repo.</p>
            <div id="hskn-author"><p>Đang tải…</p></div>
        </div>
        <?php
    }

    // ------------------------------------------------------------------ Cài đặt

    public function settings(): void
    {
        register_setting('hskn_api', ApiSettings::OPTION, ['type' => 'array', 'sanitize_callback' => [ApiSettings::class, 'sanitize']]);
        register_setting('hskn_settings', Settings::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => static function ($in) {
                $in = is_array($in) ? $in : [];
                foreach (['sentence_meaning', 'slow_repeat', 'auto_next', 'allow_user_override', 'delete_on_uninstall'] as $k) {
                    $in[$k] = !empty($in[$k]);   // checkbox bỏ chọn thì không gửi lên
                }
                $out = Settings::sanitize($in);
                if ((int) $out['chunk_size'] !== (int) Settings::get('chunk_size')) {
                    Cache::flush();
                }
                return $out;
            },
        ]);
    }

    public function settingsPage(): void
    {
        $s = Settings::all();
        $o = Settings::OPTION;
        $num = static function (string $k, int $min, int $max, int $step = 1) use ($s, $o): void {
            printf('<input type="number" name="%s[%s]" value="%d" min="%d" max="%d" step="%d" class="small-text">',
                esc_attr($o), esc_attr($k), (int) $s[$k], $min, $max, $step);
        };
        $check = static function (string $k, string $label) use ($s, $o): void {
            printf('<label><input type="checkbox" name="%s[%s]" value="1" %s> %s</label>',
                esc_attr($o), esc_attr($k), checked($s[$k], true, false), esc_html($label));
        };
        ?>
        <div class="wrap">
            <h1>Cài đặt nghe</h1>
            <p>Thứ tự đọc một từ: <strong>từ × số lần</strong> → <strong>nghĩa × 1</strong> → với mỗi câu: <strong>câu</strong> → (nghĩa câu) → <strong>câu × (số lần − 1)</strong>.</p>
            <form method="post" action="options.php">
                <?php settings_fields('hskn_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr><th>Số lần đọc từ (tiếng Trung)</th><td><?php $num('word_repeat', 1, 5); ?></td></tr>
                    <tr><th>Đọc nghĩa của từ</th><td>
                        <select name="<?php echo esc_attr($o); ?>[meaning_lang]">
                            <?php foreach (['vi' => 'Tiếng Việt', 'en' => 'Tiếng Anh', 'none' => 'Không đọc'] as $v => $l) : ?>
                                <option value="<?php echo esc_attr($v); ?>" <?php selected($s['meaning_lang'], $v); ?>><?php echo esc_html($l); ?></option>
                            <?php endforeach; ?>
                        </select> <span class="description">đọc 1 lần</span></td></tr>
                    <tr><th>Số lần đọc mỗi câu</th><td><?php $num('sentence_repeat', 0, 5); ?> <span class="description">0 = chỉ nghe từ, không đọc câu</span></td></tr>
                    <tr><th></th><td><?php $check('sentence_meaning', 'Đọc nghĩa câu 1 lần (sau lần đọc đầu)'); ?><br><?php $check('slow_repeat', 'Các lần lặp sau đọc chậm'); ?></td></tr>
                    <tr><th>Khoảng lặng (ms)</th><td>giữa các bước <?php $num('gap_ms', 300, 5000, 100); ?> · cuối mỗi mục <?php $num('end_gap_ms', 300, 8000, 100); ?></td></tr>
                    <tr><th>Số từ mỗi bài</th><td><?php $num('chunk_size', 10, 100, 5); ?></td></tr>
                    <tr><th>Nghe liên tục</th><td><?php $check('auto_next', 'Hết bài tự chuyển sang bài kế tiếp'); ?></td></tr>
                    <tr><th>Người học</th><td><?php $check('allow_user_override', 'Cho phép người học tự chỉnh các tuỳ chọn trên trong trình phát'); ?></td></tr>
                    <tr><th>Dọn cache</th><td>xoá bài nghe đã ghép không ai nghe sau <?php $num('cache_days', 1, 365); ?> ngày</td></tr>
                    <tr><th>Gỡ plugin</th><td><?php $check('delete_on_uninstall', 'Xoá toàn bộ bảng, cài đặt và audio khi gỡ plugin'); ?></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2 id="api">Tạo nội dung trong WP (DeepSeek + giọng đọc)</h2>
            <?php $api = ApiSettings::all(); $a = ApiSettings::OPTION; ?>
            <form method="post" action="options.php">
                <?php settings_fields('hskn_api'); ?>
                <table class="form-table" role="presentation">
                    <tr><th>DeepSeek API key</th><td>
                        <input type="password" name="<?php echo esc_attr($a); ?>[deepseek_key]" value="" class="regular-text" autocomplete="off"
                               placeholder="<?php echo esc_attr($api['deepseek_key'] ? 'Đã lưu: ' . ApiSettings::mask($api['deepseek_key']) : 'sk-…'); ?>">
                        <p class="description">Để trống = giữ key cũ. Nhập <code>-</code> để xoá.</p></td></tr>
                    <tr><th>Model</th><td><input type="text" name="<?php echo esc_attr($a); ?>[deepseek_model]" value="<?php echo esc_attr($api['deepseek_model']); ?>" class="regular-text"></td></tr>
                    <tr><th>Giọng đọc</th><td>
                        <label><input type="radio" name="<?php echo esc_attr($a); ?>[tts]" value="google" <?php checked($api['tts'], 'google'); ?>> Google Cloud Text-to-Speech (khuyến nghị)</label><br>
                        <label><input type="radio" name="<?php echo esc_attr($a); ?>[tts]" value="azure" <?php checked($api['tts'], 'azure'); ?>> Azure Speech</label><br>
                        <label><input type="radio" name="<?php echo esc_attr($a); ?>[tts]" value="local" <?php checked($api['tts'], 'local'); ?>> Python + edge-tts trên máy này (chỉ cho site Local/dev)</label></td></tr>
                    <tr><th>Google Cloud API key</th><td>
                        <input type="password" name="<?php echo esc_attr($a); ?>[google_key]" value="" class="regular-text" autocomplete="off"
                               placeholder="<?php echo esc_attr($api['google_key'] ? 'Đã lưu: ' . ApiSettings::mask($api['google_key']) : 'AIza…'); ?>">
                        <p class="description">console.cloud.google.com → tạo project → bật <em>Cloud Text-to-Speech API</em> → gắn tài khoản thanh toán (thẻ)
                            → <em>APIs &amp; Services → Credentials → Create credentials → API key</em> → giới hạn key chỉ cho Text-to-Speech API.
                            Giọng WaveNet miễn phí 1 triệu ký tự/tháng.</p></td></tr>
                    <tr><th>Giọng Google</th><td>
                        <?php foreach (['zh_f' => 'Trung (nữ)', 'zh_m' => 'Trung (nam, người B)', 'vi' => 'Việt', 'en' => 'Anh'] as $k => $label) : ?>
                            <label style="display:inline-block;margin:0 12px 6px 0"><?php echo esc_html($label); ?><br>
                                <input type="text" name="<?php echo esc_attr($a); ?>[google_voices][<?php echo esc_attr($k); ?>]" value="<?php echo esc_attr($api['google_voices'][$k]); ?>" style="width:170px"></label>
                        <?php endforeach; ?>
                        <p class="description">Tên giọng theo danh sách của Google (ví dụ <code>cmn-CN-Wavenet-A</code>, <code>vi-VN-Neural2-A</code>). Giọng mới chỉ áp dụng cho clip tạo sau này.</p></td></tr>
                    <tr><th>Azure Speech key</th><td>
                        <input type="password" name="<?php echo esc_attr($a); ?>[azure_key]" value="" class="regular-text" autocomplete="off"
                               placeholder="<?php echo esc_attr($api['azure_key'] ? 'Đã lưu: ' . ApiSettings::mask($api['azure_key']) : ''); ?>">
                        region <input type="text" name="<?php echo esc_attr($a); ?>[azure_region]" value="<?php echo esc_attr($api['azure_region']); ?>" class="small-text" style="width:140px">
                        <p class="description">Azure Portal → tạo tài nguyên <em>Speech</em> (gói F0 miễn phí 500.000 ký tự/tháng) → Keys and Endpoint.</p></td></tr>
                    <tr><th>Lệnh Python</th><td><input type="text" name="<?php echo esc_attr($a); ?>[python]" value="<?php echo esc_attr($api['python']); ?>" class="regular-text">
                        <p class="description">Chỉ dùng cho chế độ Python. Cần <code>pip install edge-tts truststore</code>.</p></td></tr>
                </table>
                <?php submit_button('Lưu key'); ?>
            </form>
        </div>
        <?php
    }
}
