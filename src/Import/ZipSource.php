<?php

namespace HSKN\Import;

defined('ABSPATH') || exit;

final class ZipSource extends PackSource
{
    private \ZipArchive $zip;

    public function __construct(string $file)
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP thiếu extension zip. Hãy giải nén gói và import từ thư mục.');
        }
        $this->zip = new \ZipArchive();
        if ($this->zip->open($file, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException("Không mở được zip: $file");
        }
    }

    public function read(string $name): ?string
    {
        $s = $this->zip->getFromName($name);
        return $s === false ? null : $s;
    }

    public function has(string $name): bool
    {
        return $this->zip->locateName($name) !== false;
    }

    public function size(string $name): int
    {
        $st = $this->zip->statName($name);
        return $st ? (int) $st['size'] : 0;
    }

    public function copyTo(string $name, string $dest): bool
    {
        $in = $this->zip->getStream($name);
        if (!$in) {
            return false;
        }
        $tmp = $dest . '.part';
        $out = fopen($tmp, 'wb');
        $ok = $out && stream_copy_to_stream($in, $out) !== false;
        fclose($in);
        if ($out) {
            fclose($out);
        }
        return $ok && rename($tmp, $dest);
    }
}
