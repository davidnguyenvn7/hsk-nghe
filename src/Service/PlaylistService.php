<?php

namespace HSKN\Service;

use HSKN\Repository\AudioRepo;

defined('ABSPATH') || exit;

/**
 * Nơi DUY NHẤT chứa quy tắc ghép bài nghe.
 *
 * Từ:   từ × word_repeat → nghĩa × 1 (vi | en | không) → với mỗi câu của từ: [câu]
 * Câu:  câu → (nghĩa câu, nếu sentence_meaning) → câu (chậm nếu slow_repeat) × (sentence_repeat − 1)
 * Giữa các bước là gap_ms, cuối mỗi mục là end_gap_ms. Mục thiếu clip chính thì bỏ qua.
 *
 * Kết quả:
 *   units: thứ hiển thị trên màn hình (từ hoặc câu), sentence có 'parent' = index của từ chứa nó
 *   steps: ['f' => clip, 'u' => unit, 'k' => loại] hoặc ['p' => ms lặng]
 */
final class PlaylistService
{
    private AudioRepo $audio;
    private array $s = [];
    private array $units = [];
    private array $steps = [];

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

        if ($content['kind'] === 'sentences') {
            $clips = $this->audio->forItems('sentence', array_column($content['sentences'], 'id'));
            foreach ($content['sentences'] as $sent) {
                $this->sentence($sent, $clips[$sent['id']] ?? [], null, max(1, (int) $settings['sentence_repeat']));
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
                if (empty($wc['word'])) {
                    continue;
                }
                $u = $this->unit(['kind' => 'word', 'id' => $wid, 'zh' => $w['hanzi'], 'pinyin' => $w['pinyin'],
                                  'vi' => $w['vi'], 'en' => $w['en'], 'level' => $w['level'], 'is_term' => $w['is_term']]);
                for ($i = 0; $i < (int) $settings['word_repeat']; $i++) {
                    $this->clip($wc['word'], $u, 'word');
                }
                $lang = $settings['meaning_lang'];
                if ($lang !== 'none') {
                    $this->clip($wc[$lang] ?? $wc['vi'] ?? null, $u, $lang);
                }
                if ((int) $settings['sentence_repeat'] > 0) {
                    if ($content['examples'] && $w['example'] && !empty($wc['example'])) {
                        $ex = $w['example'] + ['id' => "$wid-ex", 'type' => 'X', 'speaker' => null, 'grammar' => ''];
                        $map = ['zh' => $wc['example'], 'zh_slow' => $wc['example_slow'] ?? null,
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
        return ['units' => $this->units, 'steps' => $this->steps];
    }

    private function sentence(array $sent, array $c, ?int $parent, int $repeat): void
    {
        if (empty($c['zh'])) {
            return;
        }
        $u = $this->unit(['kind' => 'sentence', 'id' => $sent['id'], 'type' => $sent['type'], 'zh' => $sent['zh'],
                          'pinyin' => $sent['pinyin'] ?? '', 'vi' => $sent['vi'] ?? '', 'en' => $sent['en'] ?? '',
                          'grammar' => $sent['grammar'] ?? '', 'speaker' => $sent['speaker'] ?? null, 'parent' => $parent]);
        $this->clip($c['zh'], $u, 'zh');
        if ($this->s['sentence_meaning']) {
            $lang = $this->s['meaning_lang'] === 'en' && !empty($c['en']) ? 'en' : 'vi';
            $this->clip($c[$lang] ?? null, $u, $lang);
        }
        for ($i = 1; $i < $repeat; $i++) {
            $slow = $this->s['slow_repeat'] && !empty($c['zh_slow']);
            $this->clip($slow ? $c['zh_slow'] : $c['zh'], $u, $slow ? 'zh_slow' : 'zh');
        }
    }

    private function unit(array $u): int
    {
        $this->units[] = $u;
        return count($this->units) - 1;
    }

    private function clip(?string $file, int $u, string $kind): void
    {
        if (!$file) {
            return;
        }
        $this->steps[] = ['f' => $file, 'u' => $u, 'k' => $kind];
        $this->steps[] = ['p' => (int) $this->s['gap_ms']];
    }

    private function endItem(): void
    {
        $last = count($this->steps) - 1;
        if ($last >= 0 && isset($this->steps[$last]['p'])) {
            $this->steps[$last]['p'] = (int) $this->s['end_gap_ms'];
        }
    }
}
