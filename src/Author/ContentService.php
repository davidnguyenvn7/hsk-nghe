<?php

namespace HSKN\Author;

use HSKN\Install\Schema;
use HSKN\Repository\SentenceRepo;
use HSKN\Support\Cache;

defined('ABSPATH') || exit;

/**
 * Trang "Quản lý nội dung": xem, sửa, ẩn/hiện chủ đề, từ, câu.
 *
 * Dòng nào được sửa ở đây chuyển sang source = 'wp' để import gói về sau không ghi đè.
 * Không xoá cứng câu/từ: chỉ is_active = 0 (tiến độ người học còn tham chiếu).
 * Clip audio không cần xử lý ở đây: AudioService::plan() tự thấy chữ đã đổi.
 */
final class ContentService
{
    private \wpdb $db;
    private array $t;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->t = Schema::tables();
    }

    public function topics(): array
    {
        $rows = $this->db->get_results(
            "SELECT t.id, t.name_vi, t.name_zh, t.description, t.source, t.is_active,
                (SELECT COUNT(*) FROM {$this->t['topic_words']} tw JOIN {$this->t['words']} w ON w.id = tw.word_id AND w.is_active = 1 WHERE tw.topic_id = t.id) AS words,
                (SELECT COUNT(*) FROM {$this->t['sentences']} s WHERE s.topic_id = t.id AND s.is_active = 1 AND s.type IN ('S','D')) AS patterns,
                (SELECT COUNT(*) FROM {$this->t['sentences']} s WHERE s.topic_id = t.id AND s.is_active = 1 AND s.type = 'E') AS word_sentences
             FROM {$this->t['topics']} t ORDER BY t.sort, t.id", ARRAY_A // phpcs:ignore
        ) ?: [];
        foreach ($rows as &$r) {
            foreach (['words', 'patterns', 'word_sentences', 'is_active'] as $k) {
                $r[$k] = (int) $r[$k];
            }
        }
        return $rows;
    }

    public function topic(string $id): array
    {
        $t = $this->db->get_row($this->db->prepare("SELECT id, name_vi, name_zh, description, source, is_active FROM {$this->t['topics']} WHERE id = %s", $id), ARRAY_A); // phpcs:ignore
        if (!$t) {
            throw new \RuntimeException('Không có chủ đề này.');
        }
        $repo = new SentenceRepo();
        $words = $this->db->get_results($this->db->prepare(
            "SELECT w.* FROM {$this->t['topic_words']} tw JOIN {$this->t['words']} w ON w.id = tw.word_id AND w.is_active = 1
             WHERE tw.topic_id = %s ORDER BY tw.sort", $id // phpcs:ignore
        ), ARRAY_A) ?: [];
        $sents = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->t['sentences']} WHERE topic_id = %s ORDER BY type = 'E', sort", $id // phpcs:ignore
        ), ARRAY_A) ?: [];
        $byWord = [];
        $patterns = [];
        foreach ($sents as $s) {
            $row = $repo->hydrate($s) + ['is_active' => (int) $s['is_active']];
            unset($row['tokens']);
            if ($s['type'] === 'E') {
                $byWord[$s['word_id']][] = $row;
            } else {
                $patterns[] = $row;
            }
        }
        $t['is_active'] = (int) $t['is_active'];
        $t['words'] = array_map(fn($w) => $this->word($w) + ['sentences' => $byWord[$w['id']] ?? []], $words);
        $t['patterns'] = $patterns;
        return $t;
    }

    public function words(int $level, string $q, int $page, int $perPage = 50): array
    {
        $where = $this->db->prepare('is_active = 1 AND id LIKE %s', "H{$level}-%");
        if ($q !== '') {
            $like = '%' . $this->db->esc_like($q) . '%';
            $where .= $this->db->prepare(' AND (hanzi LIKE %s OR pinyin LIKE %s OR vi LIKE %s OR en LIKE %s OR example_zh LIKE %s)', $like, $like, $like, $like, $like);
        }
        $total = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->t['words']} WHERE $where"); // phpcs:ignore
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->t['words']} WHERE $where ORDER BY id LIMIT %d OFFSET %d", $perPage, ($page - 1) * $perPage // phpcs:ignore
        ), ARRAY_A) ?: [];
        return ['items' => array_map([$this, 'word'], $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    private function word(array $w): array
    {
        return [
            'id' => $w['id'], 'hanzi' => $w['hanzi'], 'pinyin' => $w['pinyin'], 'level' => (int) $w['level'],
            'vi' => $w['vi'], 'en' => $w['en'], 'source' => $w['source'],
            'example_zh' => $w['example_zh'], 'example_pinyin' => $w['example_pinyin'],
            'example_vi' => $w['example_vi'], 'example_en' => $w['example_en'],
        ];
    }

    // ------------------------------------------------------------------ sửa

    public function updateTopic(string $id, array $in): array
    {
        $data = [];
        foreach (['name_vi' => 191, 'name_zh' => 64] as $k => $len) {
            if (isset($in[$k])) {
                $data[$k] = mb_substr(sanitize_text_field((string) $in[$k]), 0, $len);
            }
        }
        if (isset($in['description'])) {
            $data['description'] = sanitize_text_field((string) $in['description']);
        }
        if (isset($in['is_active'])) {
            $data['is_active'] = $in['is_active'] ? 1 : 0;
        }
        if (isset($data['name_vi']) && $data['name_vi'] === '') {
            throw new \RuntimeException('Tên chủ đề không được trống.');
        }
        $this->db->update($this->t['topics'], $data + ['source' => 'wp'], ['id' => $id]);
        Cache::flush();
        return ['ok' => true];
    }

    /**
     * Sửa từ: pinyin/nghĩa, và câu ví dụ (từ HSK). Đổi câu ví dụ chữ Hán → DeepSeek gắn lại pinyin + kiểm tra.
     */
    public function updateWord(string $id, array $in, bool $force = false): array
    {
        $w = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t['words']} WHERE id = %s", $id), ARRAY_A); // phpcs:ignore
        if (!$w) {
            throw new \RuntimeException('Không có từ này.');
        }
        $data = [];
        foreach (['pinyin' => 128, 'vi' => 255, 'en' => 255, 'example_vi' => 255, 'example_en' => 255, 'example_pinyin' => 512] as $k => $len) {
            if (isset($in[$k])) {
                $data[$k] = mb_substr(trim(str_replace('|', '/', sanitize_text_field((string) $in[$k]))), 0, $len);
            }
        }
        if (isset($data['vi']) && $data['vi'] === '') {
            throw new \RuntimeException('Nghĩa tiếng Việt không được trống.');
        }
        $zh = isset($in['example_zh']) ? trim(sanitize_text_field((string) $in['example_zh'])) : null;
        if ($zh !== null && $zh !== $w['example_zh']) {
            if ($zh === '') {
                $data += ['example_zh' => '', 'example_pinyin' => '', 'example_vi' => '', 'example_en' => ''];
            } else {
                $a = (new Generator())->analyze($zh, $w['hanzi'], 20);
                if ($a['errors'] && !$force) {
                    return ['ok' => false, 'errors' => $a['errors']];
                }
                $data['example_zh'] = $a['zh'];
                $data['example_pinyin'] = $a['pinyin'];
            }
        }
        $this->db->update($this->t['words'], $data + ['source' => 'wp'], ['id' => $id]);
        Cache::flush();
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t['words']} WHERE id = %s", $id), ARRAY_A); // phpcs:ignore
        return ['ok' => true, 'word' => $this->word($row)];
    }

    /**
     * Sửa câu (mẫu câu S/D hoặc câu theo từ E). Đổi chữ Hán → DeepSeek gắn lại pinyin + tách từ + kiểm tra.
     */
    public function updateSentence(string $id, array $in, bool $force = false): array
    {
        $s = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t['sentences']} WHERE id = %s", $id), ARRAY_A); // phpcs:ignore
        if (!$s) {
            throw new \RuntimeException('Không có câu này.');
        }
        $data = [];
        foreach (['pinyin' => 512, 'vi' => 255, 'en' => 255, 'grammar' => 191] as $k => $len) {
            if (isset($in[$k])) {
                $data[$k] = mb_substr(trim(str_replace('|', '/', sanitize_text_field((string) $in[$k]))), 0, $len);
            }
        }
        if (isset($data['vi']) && $data['vi'] === '') {
            throw new \RuntimeException('Nghĩa tiếng Việt không được trống.');
        }
        if (isset($in['is_active'])) {
            $data['is_active'] = $in['is_active'] ? 1 : 0;
        }
        $zh = isset($in['zh']) ? trim(sanitize_text_field((string) $in['zh'])) : null;
        if ($zh !== null && $zh !== $s['zh']) {
            if ($zh === '') {
                throw new \RuntimeException('Câu không được trống.');
            }
            $word = $s['word_id'] ? (string) $this->db->get_var($this->db->prepare("SELECT hanzi FROM {$this->t['words']} WHERE id = %s", $s['word_id'])) : ''; // phpcs:ignore
            $a = (new Generator())->analyze($zh, $word, 30);
            if ($a['errors'] && !$force) {
                return ['ok' => false, 'errors' => $a['errors']];
            }
            $data += ['zh' => $a['zh'], 'pinyin' => $a['pinyin'], 'tokens' => wp_json_encode($a['tokens']), 'max_level' => $a['max_level']];
        }
        $this->db->update($this->t['sentences'], $data + ['source' => 'wp'], ['id' => $id]);
        Cache::flush();
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t['sentences']} WHERE id = %s", $id), ARRAY_A); // phpcs:ignore
        $out = (new SentenceRepo())->hydrate($row) + ['is_active' => (int) $row['is_active']];
        unset($out['tokens']);
        return ['ok' => true, 'sentence' => $out];
    }

    /** Bỏ từ khỏi chủ đề (ẩn luôn các câu E của từ đó trong chủ đề). */
    public function removeWord(string $topicId, string $wordId): array
    {
        $this->db->delete($this->t['topic_words'], ['topic_id' => $topicId, 'word_id' => $wordId]);
        $this->db->update($this->t['sentences'], ['is_active' => 0, 'source' => 'wp'], ['topic_id' => $topicId, 'word_id' => $wordId, 'type' => 'E']);
        Cache::flush();
        return ['ok' => true];
    }

    /** DeepSeek viết một câu ví dụ mới cho từ HSK (chỉ trả về để xem trước, chưa lưu). */
    public function suggestExample(string $id): array
    {
        $w = $this->db->get_row($this->db->prepare("SELECT hanzi, vi, example_zh FROM {$this->t['words']} WHERE id = %s", $id), ARRAY_A); // phpcs:ignore
        if (!$w) {
            throw new \RuntimeException('Không có từ này.');
        }
        $topic = ['name_vi' => 'Đời sống, văn phòng, nhà ở, thành phố', 'name_zh' => '日常生活', 'desc' => ''];
        $res = (new Generator())->sentences($topic, [['hanzi' => $w['hanzi'], 'vi' => $w['vi'], 'have' => [$w['example_zh']]]], 1, 15);
        $first = $res[$w['hanzi']]['accepted'][0] ?? null;
        if (!$first) {
            throw new \RuntimeException('DeepSeek chưa viết được câu đạt yêu cầu, thử lại.');
        }
        return ['zh' => $first['zh'], 'pinyin' => $first['pinyin'], 'vi' => $first['vi'], 'en' => $first['en']];
    }

    /** Lưu câu ví dụ đã được DeepSeek phân tích (từ suggestExample) — không gọi lại API. */
    public function setExample(string $id, array $ex): array
    {
        $data = [
            'example_zh'     => mb_substr(trim(sanitize_text_field((string) ($ex['zh'] ?? ''))), 0, 255),
            'example_pinyin' => mb_substr(trim(sanitize_text_field((string) ($ex['pinyin'] ?? ''))), 0, 512),
            'example_vi'     => mb_substr(trim(sanitize_text_field((string) ($ex['vi'] ?? ''))), 0, 255),
            'example_en'     => mb_substr(trim(sanitize_text_field((string) ($ex['en'] ?? ''))), 0, 255),
        ];
        $this->db->update($this->t['words'], $data + ['source' => 'wp'], ['id' => $id]);
        Cache::flush();
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t['words']} WHERE id = %s", $id), ARRAY_A); // phpcs:ignore
        return ['ok' => true, 'word' => $this->word($row)];
    }
}
