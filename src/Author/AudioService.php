<?php

namespace HSKN\Author;

use HSKN\Audio\Mp3;
use HSKN\Install\Schema;
use HSKN\Repository\WordRepo;
use HSKN\Support\Cache;
use HSKN\Support\Paths;

defined('ABSPATH') || exit;

/**
 * Giữ clip audio khớp với chữ trong DB.
 *
 * Với mỗi từ/câu đang dùng, tính ra clip "phải có" cho từng loại (từ, nghĩa, câu, câu chậm…).
 * Tên clip = hash(giọng|tốc độ|chữ), giống hệt scripts/make_audio.py, nên:
 *   - chưa có dòng audio, hoặc file đang lưu khác tên phải có (chữ đã bị sửa) → cần tạo
 *   - chữ trống (ví dụ xoá nghĩa Anh) mà vẫn còn dòng audio → xoá dòng đó
 * Sửa nội dung ở bất kỳ đâu (Quản lý nội dung, Thêm nội dung) đều tự rơi vào đây.
 */
final class AudioService
{
    public const VOICE = ['zh_f' => 'zh-CN-XiaoxiaoNeural', 'zh_m' => 'zh-CN-YunxiNeural', 'vi' => 'vi-VN-HoaiMyNeural', 'en' => 'en-US-JennyNeural'];
    private const WORD_RATE = '-10%';
    private const SLOW_RATE = '-25%';

    private \wpdb $db;
    private array $t;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->t = Schema::tables();
    }

    public static function clipName(string $text, string $voice, string $rate = '+0%'): string
    {
        return 'clips/' . substr(sha1("$voice|$rate|$text"), 0, 16) . '.mp3';
    }

    /** "modal particle ending sentence, showing …; xyz" → "modal particle ending sentence" (giống short_en). */
    public static function shortEn(string $en): string
    {
        $first = trim((string) preg_replace('/\(.*?\)/', '', explode(';', $en)[0]));
        $words = preg_split('/\s+/', trim(explode(',', $first)[0])) ?: [];
        return implode(' ', array_slice(array_filter($words), 0, 6));
    }

    /** Clip phải có của một từ: kind => [chữ, giọng, tốc độ]. */
    public static function wordSpec(array $w): array
    {
        $spec = [
            'word' => [(string) $w['hanzi'], self::VOICE['zh_f'], self::WORD_RATE],
            'vi'   => [(string) $w['vi'], self::VOICE['vi'], '+0%'],
            'en'   => [self::shortEn((string) $w['en']), self::VOICE['en'], '+0%'],
        ];
        if (($w['example_zh'] ?? '') !== '') {
            $spec += [
                'example'      => [$w['example_zh'], self::VOICE['zh_m'], '+0%'],
                'example_slow' => [$w['example_zh'], self::VOICE['zh_m'], self::SLOW_RATE],
                'example_vi'   => [(string) $w['example_vi'], self::VOICE['vi'], '+0%'],
                'example_en'   => [(string) $w['example_en'], self::VOICE['en'], '+0%'],
            ];
        }
        return $spec;
    }

    /** Clip phải có của một câu. */
    public static function sentenceSpec(array $s): array
    {
        $voice = ($s['speaker'] ?? null) === 'B' ? self::VOICE['zh_m'] : self::VOICE['zh_f'];
        return [
            'zh'      => [(string) $s['zh'], $voice, '+0%'],
            'zh_slow' => [(string) $s['zh'], $voice, self::SLOW_RATE],
            'vi'      => [(string) $s['vi'], self::VOICE['vi'], '+0%'],
            'en'      => [(string) $s['en'], self::VOICE['en'], '+0%'],
        ];
    }

    /**
     * Clip cần tạo / cập nhật và dòng audio cần xoá.
     *
     * @return array{jobs: array, stale: array}
     */
    public function plan(): array
    {
        $have = [];
        foreach ($this->db->get_results("SELECT item_type, item_id, kind, file FROM {$this->t['audio']}", ARRAY_N) ?: [] as [$ty, $id, $k, $f]) { // phpcs:ignore
            $have["$ty|$id|$k"] = $f;
        }
        $jobs = [];
        $stale = [];
        $check = function (string $type, string $id, array $spec) use (&$jobs, &$stale, $have): void {
            foreach ($spec as $kind => [$text, $voice, $rate]) {
                $key = "$type|$id|$kind";
                if (trim($text) === '') {
                    if (isset($have[$key])) {
                        $stale[] = [$type, $id, $kind];
                    }
                    continue;
                }
                $file = self::clipName($text, $voice, $rate);
                if (($have[$key] ?? null) !== $file) {
                    $jobs[] = compact('type', 'id', 'kind', 'text', 'voice', 'rate', 'file');
                }
            }
        };
        // từ cần audio: từ HSK 2–3 (bài vocab-hsk*) + mọi từ nằm trong một chủ đề
        $words = $this->db->get_results(
            "SELECT id, hanzi, vi, en, example_zh, example_vi, example_en FROM {$this->t['words']} w
             WHERE is_active = 1 AND (id LIKE 'H2-%' OR id LIKE 'H3-%' OR EXISTS (SELECT 1 FROM {$this->t['topic_words']} tw WHERE tw.word_id = w.id))", ARRAY_A // phpcs:ignore
        ) ?: [];
        foreach ($words as $w) {
            $spec = self::wordSpec($w);
            if ($w['id'][0] === 'T') {          // từ chuyên ngành không có câu ví dụ
                $spec = array_intersect_key($spec, array_flip(['word', 'vi', 'en']));
            }
            $check('word', $w['id'], $spec);
        }
        foreach ($this->db->get_results("SELECT id, speaker, zh, vi, en FROM {$this->t['sentences']} WHERE is_active = 1", ARRAY_A) ?: [] as $s) { // phpcs:ignore
            $check('sentence', $s['id'], self::sentenceSpec($s));
        }
        return ['jobs' => $jobs, 'stale' => $stale];
    }

    public function pending(): array
    {
        return $this->plan()['jobs'];
    }

    /**
     * Xử lý một lô. @return array{made:int, reused:int, errors:string[], remaining:int}
     */
    public function step(int $limit = 40): array
    {
        Paths::ensureDirs();
        $plan = $this->plan();
        foreach ($plan['stale'] as [$type, $id, $kind]) {
            $this->db->delete($this->t['audio'], ['item_type' => $type, 'item_id' => $id, 'kind' => $kind]);
        }
        $jobs = array_slice($plan['jobs'], 0, $limit);
        $toMake = [];
        foreach ($jobs as $j) {
            $path = Paths::clipPath($j['file']);
            if (!is_file($path) || filesize($path) === 0) {
                $toMake[$path] = ['text' => $j['text'], 'voice' => $j['voice'], 'rate' => $j['rate'], 'path' => $path];
            }
        }
        $res = $toMake ? (new Tts())->many(array_values($toMake)) : ['ok' => [], 'errors' => []];
        $rows = [];
        $errors = [];
        foreach ($jobs as $j) {
            $path = Paths::clipPath($j['file']);
            if (isset($res['errors'][$path])) {
                $errors[] = "{$j['text']}: {$res['errors'][$path]}";
                continue;
            }
            if (is_file($path) && filesize($path) > 0) {
                $rows[] = ['item_type' => $j['type'], 'item_id' => $j['id'], 'kind' => $j['kind'], 'file' => $j['file'],
                           'duration_ms' => Mp3::durationMs($path), 'source' => 'wp'];
            }
        }
        if ($rows) {
            (new WordRepo())->upsert($this->t['audio'], $rows, ['file', 'duration_ms', 'source']);
        }
        if ($rows || $plan['stale']) {
            Cache::flush();
        }
        return [
            'made'      => count($res['ok']),
            'reused'    => count($rows) - count($res['ok']),
            'errors'    => array_slice(array_unique($errors), 0, 5),
            'remaining' => max(0, count($plan['jobs']) - count($rows)),
        ];
    }
}
