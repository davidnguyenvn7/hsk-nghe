<?php

namespace HSKN\Repository;

defined('ABSPATH') || exit;

final class TopicRepo extends Repo
{
    public function table(): string
    {
        return $this->t['topics'];
    }

    public function all(): array
    {
        $rows = $this->db->get_results("SELECT * FROM {$this->t['topics']} WHERE is_active = 1 ORDER BY sort, id", ARRAY_A); // phpcs:ignore
        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    public function find(string $id): ?array
    {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t['topics']} WHERE id = %s AND is_active = 1", $id), ARRAY_A); // phpcs:ignore
        return $row ? $this->hydrate($row) : null;
    }

    /** topic_id => [word_id…] theo thứ tự nghe (chỉ từ còn active). */
    public function wordIds(?string $topicId = null): array
    {
        $sql = "SELECT tw.topic_id, tw.word_id FROM {$this->t['topic_words']} tw
                JOIN {$this->t['words']} w ON w.id = tw.word_id AND w.is_active = 1"
            . ($topicId ? $this->db->prepare(' WHERE tw.topic_id = %s', $topicId) : '')
            . ' ORDER BY tw.topic_id, tw.sort';
        $out = [];
        foreach ($this->db->get_results($sql, ARRAY_A) ?: [] as $r) { // phpcs:ignore
            $out[$r['topic_id']][] = $r['word_id'];
        }
        return $topicId ? ($out[$topicId] ?? []) : $out;
    }

    public function replaceWords(array $topicWords): void
    {
        $this->db->query("DELETE FROM {$this->t['topic_words']} WHERE source = 'pack'"); // phpcs:ignore
        $rows = [];
        foreach ($topicWords as $topicId => $ids) {
            foreach (array_values($ids) as $i => $wid) {
                $rows[] = ['topic_id' => (string) $topicId, 'word_id' => $wid, 'sort' => $i];
            }
        }
        $this->upsert($this->t['topic_words'], $rows, ['sort']);
    }

    private function hydrate(array $r): array
    {
        return [
            'id'          => $r['id'],
            'slug'        => $r['slug'],
            'name_vi'     => $r['name_vi'],
            'name_zh'     => $r['name_zh'],
            'description' => $r['description'],
            'sort'        => (int) $r['sort'],
            'stats'       => json_decode($r['stats'] ?: '{}', true) ?: [],
            'source'      => $r['source'] ?? 'pack',
        ];
    }
}
