<?php

namespace HSKN\Cli;

use HSKN\Import\ImportService;
use HSKN\Install\Schema;
use HSKN\Repository\AudioRepo;
use HSKN\Service\TrackService;
use HSKN\Support\Cache;
use HSKN\Support\Paths;

defined('ABSPATH') || exit;

/**
 * Quản lý nội dung HSK Nghe.
 */
final class HsknCommand
{
    /**
     * Import gói nội dung (zip hoặc thư mục).
     *
     * ## OPTIONS
     *
     * <path>
     * : Đường dẫn tới hskn-pack-*.zip hoặc thư mục đã giải nén.
     *
     * [--dry-run]
     * : Chỉ kiểm tra gói và báo số dòng sẽ thay đổi.
     *
     * [--skip-audio]
     * : Bỏ qua clip audio (chỉ cập nhật chữ).
     *
     * ## EXAMPLES
     *
     *     wp hskn import ~/hskn-pack-2026.10.26.zip
     */
    public function import(array $args, array $assoc): void
    {
        $path = $args[0];
        $svc = new ImportService();
        $skip = !empty($assoc['skip-audio']);
        try {
            if (!empty($assoc['dry-run'])) {
                $r = $svc->start($path, $skip, true);
                \WP_CLI::log("Gói {$r['version']} hợp lệ.");
                foreach ($r['changes'] as $k => $c) {
                    \WP_CLI::log(sprintf('  %-10s mới %d · đổi %d · giữ %d · ngừng dùng %d', $k, $c['new'], $c['changed'], $c['unchanged'], $c['removed']));
                }
                \WP_CLI::log("  clip: {$r['clips']}");
                return;
            }
            $last = '';
            $job = $svc->run($path, $skip, static function (array $job) use (&$last): void {
                $line = $job['phase'] . ($job['phase'] === 'clips' ? " {$job['offset']}/{$job['total']}" : '');
                if ($line !== $last) {
                    \WP_CLI::log("  … $line");
                    $last = $line;
                }
            });
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
        foreach ($job['log'] as $line) {
            \WP_CLI::log($line);
        }
        \WP_CLI::success("Đã import gói {$job['version']}.");
    }

    /**
     * Thống kê nội dung đang có.
     */
    public function status(): void
    {
        global $wpdb;
        $t = Schema::tables();
        $pack = $wpdb->get_row("SELECT version, imported_at FROM {$t['packs']} WHERE is_active = 1 ORDER BY id DESC LIMIT 1"); // phpcs:ignore
        \WP_CLI::log('Gói: ' . ($pack ? "{$pack->version} (import {$pack->imported_at} UTC)" : 'chưa import'));
        foreach (['topics', 'words', 'sentences'] as $k) {
            $n = $wpdb->get_var("SELECT COUNT(*) FROM {$t[$k]} WHERE is_active = 1"); // phpcs:ignore
            \WP_CLI::log(sprintf('%-10s %s', $k, $n));
        }
        \WP_CLI::log(sprintf('%-10s %s', 'audio', (new AudioRepo())->count()));
        $tracks = (new TrackService())->all();
        $playable = count(array_filter($tracks, static fn($tr) => $tr['playable'] > 0));
        \WP_CLI::log(sprintf('%-10s %d (%d có audio)', 'tracks', count($tracks), $playable));
    }

    /**
     * Kiểm tra mọi file trong hskn_audio có tồn tại.
     *
     * @subcommand verify-audio
     */
    public function verifyAudio(): void
    {
        $missing = [];
        foreach ((new AudioRepo())->allFiles() as $file) {
            $p = Paths::clipPath($file);
            if (!$p || !is_file($p)) {
                $missing[] = $file;
            }
        }
        if ($missing) {
            \WP_CLI::error(count($missing) . ' clip bị thiếu, ví dụ: ' . implode(', ', array_slice($missing, 0, 5)));
        }
        \WP_CLI::success('Đủ clip.');
    }

    /**
     * Xoá các bài nghe đã ghép (sẽ ghép lại khi có người nghe).
     *
     * @subcommand clear-cache
     */
    public function clearCache(): void
    {
        Cache::flush();
        \WP_CLI::success('Đã xoá ' . Cache::clearRendered() . ' bài nghe đã ghép.');
    }
}
