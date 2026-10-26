<?php

namespace HSKN\Service;

use HSKN\Repository\AudioRepo;

defined('ABSPATH') || exit;

/**
 * Nơi DUY NHẤT chứa quy tắc ghép bài nghe.
 *
 * Từ:   từ × word_repeat → nghĩa × 1 (vi | en | không) → với mỗi câu của từ: [câu]
 * Câu:  câu → (nghĩa câu, nếu sentence_meaning) → câu (chậm nếu slow_repeat) × (sentence_repeat − 1)
 * Giữa các bước là gap_ms, cuối mỗi mục là end_gap_ms.
 *
 * Kết quả:
 *   units:   thứ hiển thị trên màn hình (từ hoặc câu), sentence có 'parent' = index của từ chứa nó
 *   steps:   bản audio  — ['f' => clip, 'u', 'k'] | ['p' => ms]   (chỉ các bước có clip)
 *   speech:  bản chữ    — ['t' => chữ, 'l' => zh|vi|en, 'r' => tốc độ, 'v' => f|m, 'u', 'k'] | ['p' => ms]
 *            dùng để trình duyệt tự đọc bằng giọng của máy khi thiếu clip (không cần API nào).
 *   missing: số mục chính (từ / câu) chưa có clip → trình phát chuyển sang đọc bằng giọng máy.
 */
final class PlaylistService
{
    private const SLOW = 0.75;
    private const WORD_RATE = 0.9;

    private AudioRepo $audio;
    private array $s = [];
    private array $units = [];
    private array $steps = [];
    private array $speech = [];
    private int $missing = 0;

    public function __construct(private TrackService $tracks = new TrackService())
    {
        $this->audio = new AudioRepo();
    }

    public function build(string $trackId, array $settings): ?array
    {
        $content = $this->tracks->content($trackId);
        if ($content === null) {
            return null;
        }
        $this->s = $settings;
        $this->units = [];
        $this->steps = [];
        $this->speech = [];
        $this->missing = 0;

        if ($content['kind'] === 'sentences') {
            $clips = $this->audio->forItems('sentence', array_column($content['sentences'], 'id'));
            foreach ($content['sentences'] as $sent) {
                $c = $clips[$sent['id']] ?? [];
                $this->missing += empty($c['zh']) ? 1 : 0;
                $this->sentence($sent, $c, null, max(1, (int) $settings['sentence_repeat']));
                $this->endItem();
            }
        } else {
            $words = $content['words'];
            $wordClips = $this->audio->forItems('word', array_keys($words));
            $sentIds = [];
            foreach ($content['sentences'] as $list) {
                array_push($sentIds, ...array_column($list, 'id'));
            }
            $sentClips = $this->audio->forItems('sentence', $sentIds);
            foreach ($words as $wid => $w) {
                $wc = $wordClips[$wid] ?? [];
                $this->missing += empty($wc['word']) ? 1 : 0;
                $u = $this->unit(['kind' => 'word', 'id' => $wid, 'zh' => $w['hanzi'], 'pinyin' => $w['pinyin'],
                                  'vi' => $w['vi'], 'en' => $w['en'], 'level' => $w['level'], 'is_term' => $w['is_term']]);
                $enShort = self::shortEn((string) $w['en']);
                for ($i = 0; $i < (int) $settings['word_repeat']; $i++) {
                    $this->say($wc['word'] ?? null, $w['hanzi'], 'zh', self::WORD_RATE, 'f', $u, 'word');
                }
                $lang = $settings['meaning_lang'];
                if ($lang !== 'none') {
                    $useEn = $lang === 'en' && $enShort !== '';
                    $this->say($useEn ? ($wc['en'] ?? null) : ($wc['vi'] ?? null), $useEn ? $enShort : $w['vi'], $useEn ? 'en' : 'vi', 1, 'f', $u, $useEn ? 'en' : 'vi');
                }
                if ((int) $settings['sentence_repeat'] > 0) {
                    if ($content['examples'] && $w['example']) {
                        $ex = $w['example'] + ['id' => "$wid-ex", 'type' => 'X', 'speaker' => 'B', 'grammar' => ''];
                        $map = ['zh' => $wc['example'] ?? null, 'zh_slow' => $wc['example_slow'] ?? null,
                                'vi' => $wc['example_vi'] ?? null, 'en' => $wc['example_en'] ?? null];
                        $this->sentence($ex, array_filter($map), $u, (int) $settings['sentence_repeat']);
                    }
                    foreach ($content['sentences'][$wid] ?? [] as $sent) {
                        $this->sentence($sent, $sentClips[$sent['id']] ?? [], $u, (int) $settings['sentence_repeat']);
                    }
                }
                $this->endItem();
            }
        }
        return ['units' => $this->units, 'steps' => $this->steps, 'speech' => $this->speech, 'missing' => $this->missing];
    }

    private function sentence(array $sent, array $c, ?int $parent, int $repeat): void
    {
        $u = $this->unit(['kind' => 'sentence', 'id' => $sent['id'], 'type' => $sent['type'], 'zh' => $sent['zh'],
                          'pinyin' => $sent['pinyin'] ?? '', 'vi' => $sent['vi'] ?? '', 'en' => $sent['en'] ?? '',
                          'grammar' => $sent['grammar'] ?? '', 'speaker' => $sent['type'] === 'X' ? null : ($sent['speaker'] ?? null), 'parent' => $parent]);
        $voice = ($sent['speaker'] ?? null) === 'B' ? 'm' : 'f';
        // không có clip câu chính thì bỏ cả câu khỏi bản audio (bản giọng máy vẫn đọc đủ)
        $hasAudio = !empty($c['zh']);
        $this->say($hasAudio ? $c['zh'] : null, $sent['zh'], 'zh', 1, $voice, $u, 'zh');
        if ($this->s['sentence_meaning']) {
            $useEn = $this->s['meaning_lang'] === 'en' && ($sent['en'] ?? '') !== '';
            $this->say($hasAudio ? ($c[$useEn ? 'en' : 'vi'] ?? null) : null, $useEn ? $sent['en'] : ($sent['vi'] ?? ''), $useEn ? 'en' : 'vi', 1, 'f', $u, $useEn ? 'en' : 'vi');
        }
        for ($i = 1; $i < $repeat; $i++) {
            $slow = (bool) $this->s['slow_repeat'];
            $file = $hasAudio ? ($slow && !empty($c['zh_slow']) ? $c['zh_slow'] : $c['zh']) : null;
            $this->say($file, $sent['zh'], 'zh', $slow ? self::SLOW : 1, $voice, $u, $slow ? 'zh_slow' : 'zh');
        }
    }

    private function unit(array $u): int
    {
        $this->units[] = $u;
        return count($this->units) - 1;
    }

    /** Một bước đọc: thêm vào bản audio (nếu có clip) và bản giọng máy (nếu có chữ). */
    private function say(?string $file, string $text, string $lang, float $rate, string $voice, int $u, string $kind): void
    {
        $gap = (int) $this->s['gap_ms'];
        if ($file) {
            $this->steps[] = ['f' => $file, 'u' => $u, 'k' => $kind];
            $this->steps[] = ['p' => $gap];
        }
        if (trim($text) !== '') {
            $this->speech[] = ['t' => $text, 'l' => $lang, 'r' => $rate, 'v' => $voice, 'u' => $u, 'k' => $kind];
            $this->speech[] = ['p' => $gap];
        }
    }

    private function endItem(): void
    {
        foreach (['steps', 'speech'] as $list) {
            $last = count($this->{$list}) - 1;
            if ($last >= 0 && isset($this->{$list}[$last]['p'])) {
                $this->{$list}[$last]['p'] = (int) $this->s['end_gap_ms'];
            }
        }
    }

    /** Giống AudioService::shortEn: nghĩa Anh gọn để đọc. */
    private static function shortEn(string $en): string
    {
        $first = trim((string) preg_replace('/\(.*?\)/', '', explode(';', $en)[0]));
        $words = preg_split('/\s+/', trim(explode(',', $first)[0])) ?: [];
        return implode(' ', array_slice(array_filter($words), 0, 6));
    }
}
