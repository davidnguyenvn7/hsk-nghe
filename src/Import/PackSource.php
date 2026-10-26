<?php

namespace HSKN\Import;

defined('ABSPATH') || exit;

/**
 * Gói nội dung dạng zip (hskn-pack-*.zip) hoặc thư mục đã giải nén (upload qua SFTP).
 * Không giải nén toàn bộ: JSON đọc thẳng, clip copy từng file một khi cần.
 */
abstract class PackSource
{
    public static function open(string $path): self
    {
        if (is_dir($path)) {
            return new DirSource($path);
        }
        if (is_file($path) && str_ends_with(strtolower($path), '.zip')) {
            return new ZipSource($path);
        }
        throw new \RuntimeException("Không tìm thấy gói nội dung: $path");
    }

    abstract public function read(string $name): ?string;

    abstract public function has(string $name): bool;

    abstract public function size(string $name): int;

    abstract public function copyTo(string $name, string $dest): bool;

    public function json(string $name, bool $required = true): ?array
    {
        $raw = $this->read($name);
        if ($raw === null) {
            if ($required) {
                throw new \RuntimeException("Gói thiếu file $name");
            }
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("$name không phải JSON hợp lệ");
        }
        return $data;
    }
}
