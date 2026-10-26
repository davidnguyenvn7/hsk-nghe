<?php

namespace HSKN\Import;

defined('ABSPATH') || exit;

final class DirSource extends PackSource
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = trailingslashit($dir);
    }

    private function path(string $name): string
    {
        if (str_contains($name, '..')) {
            throw new \RuntimeException('Tên file không hợp lệ');
        }
        return $this->dir . $name;
    }

    public function read(string $name): ?string
    {
        $p = $this->path($name);
        return is_file($p) ? (string) file_get_contents($p) : null;
    }

    public function has(string $name): bool
    {
        return is_file($this->path($name));
    }

    public function size(string $name): int
    {
        return (int) @filesize($this->path($name));
    }

    public function copyTo(string $name, string $dest): bool
    {
        return @copy($this->path($name), $dest);
    }
}
