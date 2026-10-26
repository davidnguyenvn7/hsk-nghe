<?php

namespace HSKN\Service;

use HSKN\Repository\AudioRepo;
use HSKN\Repository\SentenceRepo;
use HSKN\Repository\TopicRepo;
use HSKN\Repository\WordRepo;
use HSKN\Support\Cache;
use HSKN\Support\Ids;
use HSKN\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Bài nghe sinh động từ dữ liệu (không cần bảng):
 *   topic-NN        mẫu câu chủ đề (S, D)
 *   words-NN-kk     từ vựng chủ đề + câu theo từ (E), mỗi bài chunk_size từ
 *   vocab-hskL-kk   từ HSK cấp L + câu ví dụ, mỗi bài chunk_size từ
 * Thứ tự trong list() cũng là thứ tự "bài kế tiếp" khi nghe liên tục.
 */
final class TrackService
{
    private TopicRepo $topics;
    private WordRepo $words;
    private SentenceRepo $sentences;
    private AudioRepo $audio;

    public function __construct()
    {
        $this->topics = new TopicRepo();
        $this->words = new WordRepo();
        $this->sentences = new SentenceRepo();
        $this->audio = new AudioRepo();
    }

    public function chunk(): int
    {
        return (int) Settings::get('chunk_size');
    }

    /** @return array<int,array> */
    public function all(): array
    {
        $chunk = $this->chunk();
        return Cache::remember("tracks:$chunk", HOUR_IN_SECONDS, function () use ($chunk) {
            $playable = $this->audio->idsWithMainClip();
            $counts = $this->sentences->countByTopic();
            $topicWords = $this->topics->wordIds();
            $out = [];
            foreach ($this->topics->all() as $t) {
                $n = ($counts[$t['id']]['S'] ?? 0) + ($counts[$t['id']]['D'] ?? 0);
                if ($n) {
                    $ids = array_column($this->sentences->byTopic($t['id']), 'id');
                    $out[] = [
                        'id' => "topic-{$t['id']}", 'type' => 'topic', 'group' => $t['id'],
                        'title' => $t['name_vi'], 'subtitle' => 'Mẫu câu · ' . $t['name_zh'],
                        'items' => $n, 'playable' => count(array_intersect_key(array_flip($ids), $playable['sentence'])),
                    ];
                }
                $wids = $topicWords[$t['id']] ?? [];
                $parts = array_chunk($wids, $chunk);
                foreach ($parts as $k => $part) {
                    $out[] = [
                        'id' => sprintf('words-%s-%02d', $t['id'], $k + 1), 'type' => 'words', 'group' => $t['id'],
                        'title' => $t['name_vi'], 'subtitle' => 'Từ vựng' . (count($parts) > 1 ? ' ' . ($k + 1) . '/' . count($parts) : ''),
                        'items' => count($part), 'playable' => count(array_intersect_key(array_flip($part), $playable['word'])),
                    ];
                }
            }
            foreach ([2, 3] as $level) {
                $parts = array_chunk($this->words->idsByLevel($level), $chunk);
                foreach ($parts as $k => $part) {
                    $out[] = [
                        'id' => sprintf('vocab-hsk%d-%02d', $level, $k + 1), 'type' => 'vocab', 'group' => "hsk$level",
                        'title' => sprintf('HSK %d · Bài %02d', $level, $k + 1), 'subtitle' => $part[0] . ' – ' . end($part),
                        'items' => count($part), 'playable' => count(array_intersect_key(array_flip($part), $playable['word'])),
                    ];
                }
            }
            return $out;
        });
    }

    public function find(string $id): ?array
    {
        if (!Ids::isTrack($id)) {
            return null;
        }
        $all = $this->all();
        foreach ($all as $i => $track) {
            if ($track['id'] === $id) {
                $track['prev'] = $this->neighbour($all, $i, -1);
                $track['next'] = $this->neighbour($all, $i, 1);
                return $track;
            }
        }
        return null;
    }

    /** Bài trước/sau có nội dung (bài thiếu clip vẫn nghe được bằng giọng của máy). */
    private function neighbour(array $all, int $i, int $dir): ?string
    {
        for ($j = $i + $dir; $j >= 0 && $j < count($all); $j += $dir) {
            if ($all[$j]['items'] > 0) {
                return $all[$j]['id'];
            }
        }
        return null;
    }

    /**
     * Nội dung của bài: ['kind' => 'sentences', 'sentences' => […]] hoặc
     * ['kind' => 'words', 'words' => […], 'sentences' => [word_id => […]], 'examples' => bool]
     */
    public function content(string $id): ?array
    {
        if (preg_match('/^topic-(\d{2})$/', $id, $m)) {
            return ['kind' => 'sentences', 'sentences' => $this->sentences->byTopic($m[1])];
        }
        if (preg_match('/^words-(\d{2})-(\d{2})$/', $id, $m)) {
            $ids = array_slice($this->topics->wordIds($m[1]), ((int) $m[2] - 1) * $this->chunk(), $this->chunk());
            return ['kind' => 'words', 'words' => $this->words->findMany($ids),
                    'sentences' => $this->sentences->byWords($m[1], $ids), 'examples' => false];
        }
        if (preg_match('/^vocab-hsk([23])-(\d{2})$/', $id, $m)) {
            $ids = array_slice($this->words->idsByLevel((int) $m[1]), ((int) $m[2] - 1) * $this->chunk(), $this->chunk());
            return ['kind' => 'words', 'words' => $this->words->findMany($ids), 'sentences' => [], 'examples' => true];
        }
        return null;
    }
}
