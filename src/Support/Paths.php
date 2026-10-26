<?php

namespace HSKN\Support;

defined('ABSPATH') || exit;

/**
 * uploads/hskn/
 *   clips/      clip lẻ <sha1-16>.mp3 (tên = hash nội dung → cache immutable)
 *   cache/      bài nghe đã ghép theo cài đặt: {track}-{hash}.mp3 + .json (cue)
 *   incoming/   gói upload qua SFTP hoặc trang Import (zip hoặc thư mục)
 */
final class Paths
{
    public static function base(): string
    {
        return trailingslashit(wp_upload_dir(null, false)['basedir']) . 'hskn/';
    }

    public static function baseUrl(): string
    {
        return trailingslashit(wp_upload_dir(null, false)['baseurl']) . 'hskn/';
    }

    public static function clips(): string
    {
        return self::base() . 'clips/';
    }

    public static function cache(): string
    {
        return self::base() . 'cache/';
    }

    public static function cacheUrl(): string
    {
        return self::baseUrl() . 'cache/';
    }

    public static function incoming(): string
    {
        return self::base() . 'incoming/';
    }

    /** "clips/ab12….mp3" → đường dẫn tuyệt đối (chỉ cho phép tên file an toàn). */
    public static function clipPath(string $file): ?string
    {
        if (!preg_match('~^clips/[a-z0-9_]+\.mp3$~', $file)) {
            return null;
        }
        return self::base() . $file;
    }

    public static function ensureDirs(): void
    {
        foreach ([self::clips(), self::cache(), self::incoming()] as $dir) {
            wp_mkdir_p($dir);
        }
        // incoming/ chứa gói chưa import: không cho tải trực tiếp
        $ht = self::incoming() . '.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\n");
        }
        foreach ([self::base(), self::incoming()] as $dir) {
            if (!is_file($dir . 'index.php')) {
                @file_put_contents($dir . 'index.php', "<?php // Silence is golden.\n");
            }
        }
    }

    public static function dirSize(string $dir): array
    {
        $bytes = 0;
        $files = 0;
        if (is_dir($dir)) {
            foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $f) {
                if ($f->isFile() && $f->getExtension() === 'mp3') {
                    $bytes += $f->getSize();
                    $files++;
                }
            }
        }
        return ['files' => $files, 'bytes' => $bytes];
    }
}
