<?php

namespace HSKN\Repository;

defined('ABSPATH') || exit;

final class SentenceRepo extends Repo
{
    public function table(): string
    {
        return $this->t['sentences'];
    }

    /** Mẫu câu (S, D) của chủ đề, theo thứ tự trong file nguồn. */
    public function byTopic(string $topicId, array $types = ['S', 'D']): array
    {
        $args = array_merge([$topicId], $types);
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->t['sentences']} WHERE is_active = 1 AND topic_id = %s AND type IN (" . $this->placeholders($types) . ') ORDER BY sort', $args // phpcs:ignore
        ), ARRAY_A);
        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    /** Câu E của nhiều từ trong một chủ đề: word_id => [câu…] (1 query). */
    public function byWords(string $topicId, array $wordIds): array
    {
        if (!$wordIds) {
            return [];
        }
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->t['sentences']} WHERE is_active = 1 AND type = 'E' AND topic_id = %s AND word_id IN ("
            . $this->placeholders($wordIds) . ') ORDER BY sort', array_merge([$topicId], $wordIds) // phpcs:ignore
        ), ARRAY_A);
        $out = [];
        foreach ($rows ?: [] as $r) {
            $out[$r['word_id']][] = $this->hydrate($r);
        }
        return $out;
    }

    /** Câu chủ đề có chứa từ (qua bảng word_sentence). */
    public function containingWord(string $wordId, int $limit = 10): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT s.* FROM {$this->t['word_sentence']} ws JOIN {$this->t['sentences']} s ON s.id = ws.sentence_id AND s.is_active = 1
             WHERE ws.word_id = %s ORDER BY s.topic_id, s.sort LIMIT %d", $wordId, $limit // phpcs:ignore
        ), ARRAY_A);
        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    public function countByTopic(): array
    {
        $rows = $this->db->get_results("SELECT topic_id, type, COUNT(*) AS n FROM {$this->t['sentences']} WHERE is_active = 1 GROUP BY topic_id, type", ARRAY_A); // phpcs:ignore
        $out = [];
        foreach ($rows ?: [] as $r) {
            $out[$r['topic_id']][$r['type']] = (int) $r['n'];
        }
        return $out;
    }

    public function replaceWordLinks(array $links): void
    {
        $this->db->query("DELETE FROM {$this->t['word_sentence']} WHERE source = 'pack'"); // phpcs:ignore
        $this->upsert($this->t['word_sentence'], $links, [], 500);
    }

    public function hydrate(array $r): array
    {
        return [
            'id'        => $r['id'],
            'topic'     => $r['topic_id'],
            'type'      => $r['type'],
            'dialogue'  => $r['dialogue_id'],
            'speaker'   => $r['speaker'],
            'word'      => $r['word_id'],
            'zh'        => $r['zh'],
            'pinyin'    => $r['pinyin'],
            'vi'        => $r['vi'],
            'en'        => $r['en'],
            'grammar'   => $r['grammar'],
            'max_level' => (int) $r['max_level'],
            'tokens'    => json_decode($r['tokens'] ?: '[]', true) ?: [],
        ];
    }
}
