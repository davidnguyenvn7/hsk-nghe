<?php

namespace HSKN\Audio;

use HSKN\Support\Cache;
use HSKN\Support\Paths;

defined('ABSPATH') || exit;

/**
 * Ghép các bước của playlist thành MỘT file MP3 (để phát nền khi khoá màn hình) + danh sách cue.
 *
 * File lưu ở uploads/hskn/cache/{track}-{hash}.mp3, hash = các bước (tên clip đã là hash nội dung)
 * + phiên bản dữ liệu. Cùng bài + cùng cài đặt → dùng lại file; web server phục vụ trực tiếp (có Range).
 * Cue: [ms bắt đầu, unit, loại clip] cho từng clip, để trình phát biết đang đọc câu nào.
 */
final class AudioRenderer
{
    private const FORMAT = 1;

    /** @var array<string,array> clip đã đọc trong request này */
    private array $memo = [];

    /**
     * @return array{src:string, duration_ms:int, cues:array, bytes:int}
     */
    public function render(string $trackId, array $steps): array
    {
        $key = substr(sha1(wp_json_encode([self::FORMAT, Cache::version(), $steps])), 0, 16);
        $name = "{$trackId}-{$key}";
        $mp3 = Paths::cache() . "$name.mp3";
        $meta = Paths::cache() . "$name.json";

        if (!is_file($meta) || !is_file($mp3)) {
            Paths::ensureDirs();
            $lock = fopen(Paths::cache() . "$name.lock", 'c');
            try {
                if ($lock) {
                    flock($lock, LOCK_EX);
                }
                if (!is_file($meta) || !is_file($mp3)) {   // request khác có thể vừa ghép xong
                    $this->stitch($steps, $mp3, $meta);
                }
            } finally {
                if ($lock) {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                    @unlink(Paths::cache() . "$name.lock");
                }
            }
        } else {
            @touch($mp3);   // dọn cache theo mtime: bài còn người nghe thì giữ lại
        }

        $info = json_decode((string) file_get_contents($meta), true) ?: [];
        return [
            'src'         => Paths::cacheUrl() . "$name.mp3",
            'duration_ms' => (int) ($info['duration_ms'] ?? 0),
            'bytes'       => (int) ($info['bytes'] ?? 0),
            'cues'        => $info['cues'] ?? [],
        ];
    }

    private function stitch(array $steps, string $mp3, string $meta): void
    {
        $tmp = $mp3 . '.' . wp_generate_password(6, false) . '.tmp';
        $out = fopen($tmp, 'wb');
        if (!$out) {
            throw new \RuntimeException('Không ghi được thư mục cache audio.');
        }
        $t = 0.0;
        $rate = null;
        $silence = null;
        $cues = [];
        $bytes = 0;
        try {
            foreach ($steps as $step) {
                if (isset($step['f'])) {
                    $clip = $this->clip($step['f']);
                    if (!$clip || !$clip['count']) {
                        continue;
                    }
                    $rate ??= $clip['sample_rate'];
                    if ($clip['sample_rate'] !== $rate) {
                        continue;   // khác định dạng: ghép vào sẽ làm hỏng file
                    }
                    $silence ??= Mp3::silentFrame($clip['header']);
                    $cues[] = [(int) round($t), $step['u'], $step['k']];
                    $bytes += fwrite($out, $clip['bytes']);
                    $t += $clip['ms'];
                } elseif (isset($step['p']) && $silence) {
                    $n = max(1, (int) round($step['p'] / $silence['ms']));
                    $bytes += fwrite($out, str_repeat($silence['frame'], $n));
                    $t += $n * $silence['ms'];
                }
            }
        } finally {
            fclose($out);
        }
        if (!$cues) {
            @unlink($tmp);
            throw new \RuntimeException('Bài nghe chưa có audio.');
        }
        rename($tmp, $mp3);
        // .json ghi sau cùng: có .json tức là .mp3 đã hoàn chỉnh
        file_put_contents($meta, wp_json_encode(['duration_ms' => (int) round($t), 'bytes' => $bytes, 'cues' => $cues]));
    }

    private function clip(string $file): ?array
    {
        if (!array_key_exists($file, $this->memo)) {
            $path = Paths::clipPath($file);
            try {
                $this->memo[$file] = $path && is_file($path) ? Mp3::read($path) : null;
            } catch (\RuntimeException) {
                $this->memo[$file] = null;
            }
        }
        return $this->memo[$file];
    }
}
