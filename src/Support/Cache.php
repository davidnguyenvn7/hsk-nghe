<?php

namespace HSKN\Support;

defined('ABSPATH') || exit;

/**
 * Cache cho dữ liệu đọc nhiều và tính lại được (danh sách chủ đề, danh sách bài nghe).
 * Khoá gắn với hskn_data_version: import xong chỉ cần tăng version là mọi khoá cũ hết hiệu lực,
 * kể cả khi dùng Redis Object Cache (không cần xoá từng khoá).
 */
final class Cache
{
    private const GROUP = 'hskn';
    private const VERSION = 'hskn_data_version';

    public static function version(): int
    {
        return (int) get_option(self::VERSION, 1);
    }

    public static function bumpVersion(): void
    {
        update_option(self::VERSION, self::version() + 1, true);
    }

    public static function flush(): void
    {
        self::bumpVersion();
    }

    public static function remember(string $key, int $ttl, callable $fn)
    {
        $k = 'v' . self::version() . ':' . $key;
        $hit = wp_cache_get($k, self::GROUP, false, $found);
        if ($found) {
            return $hit;
        }
        $val = $fn();
        wp_cache_set($k, $val, self::GROUP, $ttl);
        return $val;
    }

    /** Xoá mọi bài nghe đã ghép (sẽ ghép lại khi có người nghe). */
    public static function clearRendered(?int $olderThanDays = null): int
    {
        $dir = Paths::cache();
        if (!is_dir($dir)) {
            return 0;
        }
        $limit = $olderThanDays ? time() - $olderThanDays * DAY_IN_SECONDS : PHP_INT_MAX;
        $n = 0;
        foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $f) {
            if ($f->isFile() && in_array($f->getExtension(), ['mp3', 'json', 'tmp'], true) && $f->getMTime() < $limit) {
                if (@unlink($f->getPathname()) && $f->getExtension() === 'mp3') {
                    $n++;
                }
            }
        }
        return $n;
    }
}
