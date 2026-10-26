<?php

namespace HSKN\Repository;

defined('ABSPATH') || exit;

final class AudioRepo extends Repo
{
    /**
     * Clip của nhiều mục cùng loại trong 1 query: item_id => [kind => file].
     */
    public function forItems(string $type, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $rows = $this->db->get_results($this->db->prepare(
                "SELECT item_id, kind, file FROM {$this->t['audio']} WHERE item_type = %s AND item_id IN (" . $this->placeholders($chunk) . ')', // phpcs:ignore
                array_merge([$type], $chunk)
            ), ARRAY_A);
            foreach ($rows ?: [] as $r) {
                $out[$r['item_id']][$r['kind']] = $r['file'];
            }
        }
        return $out;
    }

    /** Số mục có clip chính (word / zh), dùng để biết bài nào nghe được. */
    public function idsWithMainClip(): array
    {
        $rows = $this->db->get_results("SELECT item_type, item_id FROM {$this->t['audio']} WHERE kind IN ('word','zh')", ARRAY_A); // phpcs:ignore
        $out = ['word' => [], 'sentence' => []];
        foreach ($rows ?: [] as $r) {
            $out[$r['item_type']][$r['item_id']] = true;
        }
        return $out;
    }

    public function replaceAll(array $rows): void
    {
        $this->db->query("DELETE FROM {$this->t['audio']} WHERE source = 'pack'"); // phpcs:ignore
        $this->upsert($this->t['audio'], $rows, ['file', 'duration_ms'], 500);
    }

    public function count(): int
    {
        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->t['audio']}"); // phpcs:ignore
    }

    public function allFiles(): array
    {
        return $this->db->get_col("SELECT DISTINCT file FROM {$this->t['audio']}"); // phpcs:ignore
    }
}
