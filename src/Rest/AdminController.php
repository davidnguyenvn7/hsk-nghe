<?php

namespace HSKN\Rest;

use HSKN\Import\ImportService;
use HSKN\Support\Paths;

defined('ABSPATH') || exit;

/**
 * Import từ trang Admin: trình duyệt gọi /admin/import/step lặp lại đến khi xong (mỗi lần vài giây),
 * nên không vướng max_execution_time và có thanh tiến độ.
 */
final class AdminController
{
    public function register(): void
    {
        $perm = static fn() => current_user_can('manage_options');
        register_rest_route('hskn/v1', '/admin/import', [
            ['methods' => 'GET', 'callback' => [$this, 'status'], 'permission_callback' => $perm],
            ['methods' => 'POST', 'callback' => [$this, 'start'], 'permission_callback' => $perm, 'args' => [
                'source'     => ['type' => 'string', 'required' => true],
                'skip_audio' => ['type' => 'boolean', 'default' => false],
                'dry_run'    => ['type' => 'boolean', 'default' => false],
            ]],
            ['methods' => 'DELETE', 'callback' => [$this, 'cancel'], 'permission_callback' => $perm],
        ]);
        register_rest_route('hskn/v1', '/admin/import/step', [
            'methods' => 'POST', 'callback' => [$this, 'step'], 'permission_callback' => $perm,
        ]);
    }

    /** Gói có trong uploads/hskn/incoming/: file .zip hoặc thư mục có pack.json. */
    public static function incoming(): array
    {
        $out = [];
        $dir = Paths::incoming();
        if (!is_dir($dir)) {
            return $out;
        }
        foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $f) {
            $name = $f->getFilename();
            if ($f->isFile() && str_ends_with(strtolower($name), '.zip')) {
                $out[] = ['name' => $name, 'type' => 'zip', 'size' => $f->getSize(), 'mtime' => $f->getMTime()];
            } elseif ($f->isDir() && is_file($f->getPathname() . '/pack.json')) {
                $out[] = ['name' => $name, 'type' => 'dir', 'size' => null, 'mtime' => $f->getMTime()];
            }
        }
        usort($out, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $out;
    }

    private static function sourcePath(string $name): ?string
    {
        $name = basename($name);
        foreach (self::incoming() as $p) {
            if ($p['name'] === $name) {
                return Paths::incoming() . $name;
            }
        }
        return null;
    }

    public function status(): \WP_REST_Response
    {
        return new \WP_REST_Response(['job' => (new ImportService())->job(), 'incoming' => self::incoming()]);
    }

    public function start(\WP_REST_Request $req)
    {
        $path = self::sourcePath((string) $req['source']);
        if (!$path) {
            return new \WP_Error('hskn_source', 'Không tìm thấy gói trong thư mục incoming.', ['status' => 404]);
        }
        $svc = new ImportService();
        $job = $svc->job();
        if ($job && $job['phase'] !== 'done' && !$req['dry_run']) {
            return new \WP_Error('hskn_busy', 'Đang có một lần import chưa xong. Huỷ nó trước.', ['status' => 409]);
        }
        try {
            if ($req['dry_run']) {
                return new \WP_REST_Response(['report' => $svc->start($path, (bool) $req['skip_audio'], true)]);
            }
            $svc->cancel();
            return new \WP_REST_Response(['job' => $svc->start($path, (bool) $req['skip_audio'])]);
        } catch (\Throwable $e) {
            return new \WP_Error('hskn_import', $e->getMessage(), ['status' => 400]);
        }
    }

    public function step()
    {
        try {
            return new \WP_REST_Response(['job' => (new ImportService())->step(8.0)]);
        } catch (\Throwable $e) {
            return new \WP_Error('hskn_import', $e->getMessage(), ['status' => 500]);
        }
    }

    public function cancel(): \WP_REST_Response
    {
        (new ImportService())->cancel();
        return new \WP_REST_Response(['ok' => true]);
    }
}
