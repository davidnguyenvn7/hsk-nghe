<?php

namespace HSKN\Author;

use HSKN\Install\Schema;
use HSKN\Repository\WordRepo;
use HSKN\Support\Cache;

defined('ABSPATH') || exit;

/**
 * Lưu nội dung tạo trong WP Admin (source = 'wp').
 *
 * Dải ID riêng để không bao giờ trùng với nội dung sinh ở repo (generate.py):
 *   chủ đề mới   50–99
 *   từ mới       T{chủ đề}-501 …
 *   câu theo từ  {chủ đề}-E5001 …
 */
final class AuthorService
{
    private \wpdb $db;
    private array $t;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->t = Schema::tables();
    }

    /** Chủ đề tạo trong WP + số từ/câu, để hiện danh sách trên trang Thêm nội dung. */
    public function wpTopics(): array
    {
        return $this->db->get_results(
            "SELECT t.id, t.name_vi, t.name_zh, t.description,
                    (SELECT COUNT(*) FROM {$this->t['topic_words']} tw WHERE tw.topic_id = t.id) AS words,
                    (SELECT COUNT(*) FROM {$this->t['sentences']} s WHERE s.topic_id = t.id AND s.is_active = 1 AND s.type = 'E') AS sentences
             FROM {$this->t['topics']} t WHERE t.is_active = 1 AND t.source = 'wp' ORDER BY t.id", ARRAY_A // phpcs:ignore
        ) ?: [];
    }

    /** Từ của chủ đề (để loại trùng khi đề xuất) và câu E đã có của từng từ. */
    public function topicContext(string $topicId): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT w.id, w.hanzi FROM {$this->t['topic_words']} tw JOIN {$this->t['words']} w ON w.id = tw.word_id
             WHERE tw.topic_id = %s ORDER BY tw.sort", $topicId // phpcs:ignore
        ), ARRAY_A) ?: [];
        $sent = $this->db->get_results($this->db->prepare(
            "SELECT word_id, zh FROM {$this->t['sentences']} WHERE topic_id = %s AND type = 'E' AND is_active = 1", $topicId // phpcs:ignore
        ), ARRAY_A) ?: [];
        $have = [];
        foreach ($sent as $s) {
            $have[$s['word_id']][] = $s['zh'];
        }
        return ['words' => $rows, 'sentences' => $have];
    }

    /**
     * @param array $draft ['topic' => [id?|name_vi, name_zh, desc], 'words' => [[hanzi, id?, pinyin, vi, en, sentences => […]]]]
     * @return array{topic_id:string, words:int, sentences:int, skipped:string[]}
     */
    public function save(array $draft): array
    {
        $topic = (array) ($draft['topic'] ?? []);
        $words = array_values(array_filter((array) ($draft['words'] ?? []), 'is_array'));
        if (!$words) {
            throw new \RuntimeException('Chưa có từ nào để lưu.');
        }

        $this->db->query('START TRANSACTION');
        try {
            $topicId = $this->ensureTopic($topic);
            $checker = new Checker(30, array_column($words, 'hanzi'));
            $nextT = $this->nextNumber("SELECT MAX(CAST(SUBSTRING(id, 5) AS UNSIGNED)) FROM {$this->t['words']} WHERE id LIKE %s", "T{$topicId}-%", 500);
            $nextE = $this->nextNumber("SELECT MAX(CAST(SUBSTRING(id, 5) AS UNSIGNED)) FROM {$this->t['sentences']} WHERE id LIKE %s", "{$topicId}-E%", 5000);
            $sort = (int) $this->db->get_var($this->db->prepare("SELECT COALESCE(MAX(sort), -1) FROM {$this->t['topic_words']} WHERE topic_id = %s", $topicId)); // phpcs:ignore
            $sentSort = (int) $this->db->get_var($this->db->prepare("SELECT COALESCE(MAX(sort), -1) FROM {$this->t['sentences']} WHERE topic_id = %s", $topicId)); // phpcs:ignore
            $wordRepo = new WordRepo();
            $nWords = 0;
            $nSent = 0;
            $skipped = [];

            foreach ($words as $w) {
                $h = trim((string) ($w['hanzi'] ?? ''));
                if (!preg_match('/^[' . Checker::HAN . ']{1,6}$/u', $h)) {
                    continue;
                }
                $wid = $this->existingWordId($h, (string) ($w['id'] ?? ''));
                if (!$wid) {
                    $wid = sprintf('T%s-%03d', $topicId, ++$nextT);
                    $row = [
                        'id' => $wid, 'hanzi' => $h, 'pinyin' => str_replace(' ', '', mb_strtolower(trim((string) ($w['pinyin'] ?? '')))),
                        'level' => Checker::hskLevel($h) ?: 4, 'pos' => '', 'vi' => mb_substr(trim((string) ($w['vi'] ?? '')), 0, 255),
                        'en' => mb_substr(trim((string) ($w['en'] ?? '')), 0, 255), 'freq_rank' => null,
                        'example_zh' => '', 'example_pinyin' => '', 'example_vi' => '', 'example_en' => '',
                    ];
                    $wordRepo->upsert($this->t['words'], [$row + ['content_hash' => substr(md5(wp_json_encode($row)), 0, 16), 'source' => 'wp', 'is_active' => 1]], []);
                }
                $inTopic = $this->db->get_var($this->db->prepare("SELECT 1 FROM {$this->t['topic_words']} WHERE topic_id = %s AND word_id = %s", $topicId, $wid)); // phpcs:ignore
                if (!$inTopic) {
                    $wordRepo->upsert($this->t['topic_words'], [['topic_id' => $topicId, 'word_id' => $wid, 'sort' => ++$sort, 'source' => 'wp']], []);
                    $nWords++;
                }

                foreach ((array) ($w['sentences'] ?? []) as $s) {
                    $zh = trim((string) ($s['zh'] ?? ''));
                    $tokens = array_map(static fn($t) => [(string) ($t[0] ?? ''), (string) ($t[1] ?? '')], array_filter((array) ($s['tokens'] ?? []), 'is_array'));
                    $res = $checker->check($zh, $h, $tokens);
                    if ($res['errors']) {
                        $skipped[] = "$zh (" . implode('; ', $res['errors']) . ')';
                        continue;
                    }
                    $sid = sprintf('%s-E%04d', $topicId, ++$nextE);
                    $row = [
                        'id' => $sid, 'topic_id' => $topicId, 'type' => 'E', 'dialogue_id' => null, 'speaker' => null, 'word_id' => $wid,
                        'zh' => $zh, 'pinyin' => mb_substr(trim((string) ($s['pinyin'] ?? '')), 0, 512),
                        'vi' => mb_substr(trim((string) ($s['vi'] ?? '')), 0, 255), 'en' => mb_substr(trim((string) ($s['en'] ?? '')), 0, 255),
                        'grammar' => mb_substr(trim((string) ($s['grammar'] ?? '')), 0, 191), 'max_level' => $res['max_level'],
                        'tokens' => wp_json_encode($res['tokens']), 'sort' => ++$sentSort,
                    ];
                    $wordRepo->upsert($this->t['sentences'], [$row + ['content_hash' => substr(md5(wp_json_encode($row)), 0, 16), 'source' => 'wp', 'is_active' => 1]], []);
                    $wordRepo->upsert($this->t['word_sentence'], [['word_id' => $wid, 'sentence_id' => $sid, 'source' => 'wp']], ['source']);
                    $nSent++;
                }
            }
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }
        Cache::flush();
        return ['topic_id' => $topicId, 'words' => $nWords, 'sentences' => $nSent, 'skipped' => $skipped];
    }

    /** Ngừng dùng chủ đề tạo trong WP (không xoá: tiến độ người học còn tham chiếu). */
    public function deactivateTopic(string $id): bool
    {
        $ok = (bool) $this->db->update($this->t['topics'], ['is_active' => 0], ['id' => $id, 'source' => 'wp']);
        Cache::flush();
        return $ok;
    }

    private function ensureTopic(array $topic): string
    {
        $id = (string) ($topic['id'] ?? '');
        if ($id !== '') {
            if (!preg_match('/^\d{2}$/', $id) || !$this->db->get_var($this->db->prepare("SELECT 1 FROM {$this->t['topics']} WHERE id = %s AND is_active = 1", $id))) { // phpcs:ignore
                throw new \RuntimeException('Chủ đề không tồn tại.');
            }
            return $id;
        }
        $name = trim((string) ($topic['name_vi'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Thiếu tên chủ đề.');
        }
        $max = (int) $this->db->get_var("SELECT MAX(CAST(id AS UNSIGNED)) FROM {$this->t['topics']} WHERE CAST(id AS UNSIGNED) >= 50"); // phpcs:ignore
        $num = max(50, $max + 1);
        if ($num > 99) {
            throw new \RuntimeException('Đã hết mã chủ đề (50–99).');
        }
        $id = sprintf('%02d', $num);
        $sort = (int) $this->db->get_var("SELECT COALESCE(MAX(sort), 0) FROM {$this->t['topics']}") + 1; // phpcs:ignore
        $row = [
            'id' => $id, 'slug' => sanitize_title($name), 'name_vi' => mb_substr($name, 0, 191),
            'name_zh' => mb_substr(trim((string) ($topic['name_zh'] ?? '')), 0, 64),
            'description' => trim((string) ($topic['desc'] ?? '')), 'sort' => $sort, 'stats' => '{}',
        ];
        (new WordRepo())->upsert($this->t['topics'], [$row + ['content_hash' => '', 'source' => 'wp', 'is_active' => 1]], []);
        return $id;
    }

    private function existingWordId(string $hanzi, string $hint): ?string
    {
        if ($hint !== '') {
            $ok = $this->db->get_var($this->db->prepare("SELECT id FROM {$this->t['words']} WHERE id = %s AND hanzi = %s AND is_active = 1", $hint, $hanzi)); // phpcs:ignore
            if ($ok) {
                return $ok;
            }
        }
        return $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->t['words']} WHERE hanzi = %s AND is_active = 1 ORDER BY (id LIKE 'H%%') DESC, id LIMIT 1", $hanzi // phpcs:ignore
        )) ?: null;
    }

    private function nextNumber(string $sql, string $like, int $floor): int
    {
        $max = (int) $this->db->get_var($this->db->prepare($sql, $like)); // phpcs:ignore
        return max($floor, $max);
    }
}
