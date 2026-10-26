<?php

namespace HSKN\Repository;

defined('ABSPATH') || exit;

final class WordRepo extends Repo
{
    public function table(): string
    {
        return $this->t['words'];
    }

    /** @return array<string,array> id => từ (giữ thứ tự $ids). */
    public function findMany(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $rows = $this->db->get_results($this->db->prepare(
                "SELECT * FROM {$this->t['words']} WHERE is_active = 1 AND id IN (" . $this->placeholders($chunk) . ')', $chunk // phpcs:ignore
            ), ARRAY_A);
            foreach ($rows ?: [] as $r) {
                $out[$r['id']] = $this->hydrate($r);
            }
        }
        $sorted = [];
        foreach ($ids as $id) {
            if (isset($out[$id])) {
                $sorted[$id] = $out[$id];
            }
        }
        return $sorted;
    }

    public function find(string $id): ?array
    {
        return $this->findMany([$id])[$id] ?? null;
    }

    /** ID từ HSK theo cấp, theo thứ tự danh sách chính thức. */
    public function idsByLevel(int $level): array
    {
        return $this->db->get_col($this->db->prepare(
            "SELECT id FROM {$this->t['words']} WHERE is_active = 1 AND id LIKE %s ORDER BY id", "H{$level}-%" // phpcs:ignore
        ));
    }

    public function countByLevel(): array
    {
        $rows = $this->db->get_results("SELECT LEFT(id, 2) AS lv, COUNT(*) AS n FROM {$this->t['words']} WHERE is_active = 1 GROUP BY lv", ARRAY_A); // phpcs:ignore
        return array_column($rows ?: [], 'n', 'lv');
    }

    /**
     * Tra từ: q khớp hán tự / pinyin (bỏ dấu) / nghĩa. Trả về [items, total].
     */
    public function search(array $f, int $page, int $perPage): array
    {
        $where = ['w.is_active = 1'];
        $args = [];
        $join = '';
        if (!empty($f['level'])) {
            $where[] = 'w.id LIKE %s';
            $args[] = 'H' . (int) $f['level'] . '-%';
        }
        if (!empty($f['topic'])) {
            $join = "JOIN {$this->t['topic_words']} tw ON tw.word_id = w.id AND tw.topic_id = %s";
            array_unshift($args, $f['topic']);
        }
        if (!empty($f['q'])) {
            $like = '%' . $this->db->esc_like($f['q']) . '%';
            $where[] = '(w.hanzi LIKE %s OR w.pinyin LIKE %s OR w.vi LIKE %s OR w.en LIKE %s)';
            array_push($args, $like, $like, $like, $like);
        }
        $sqlWhere = implode(' AND ', $where);
        $order = !empty($f['topic']) ? 'tw.sort' : 'w.id';
        $base = "FROM {$this->t['words']} w {$join} WHERE {$sqlWhere}";
        $total = (int) $this->db->get_var($args ? $this->db->prepare("SELECT COUNT(*) {$base}", $args) : "SELECT COUNT(*) {$base}"); // phpcs:ignore
        $sql = "SELECT w.* {$base} ORDER BY {$order} LIMIT %d OFFSET %d";
        $rows = $this->db->get_results($this->db->prepare($sql, array_merge($args, [$perPage, ($page - 1) * $perPage])), ARRAY_A); // phpcs:ignore
        return [array_map([$this, 'hydrate'], $rows ?: []), $total];
    }

    public function hydrate(array $r): array
    {
        $level = (int) $r['level'];
        return [
            'id'      => $r['id'],
            'hanzi'   => $r['hanzi'],
            'pinyin'  => $r['pinyin'],
            'level'   => $level,
            'is_term' => $r['id'][0] === 'T',
            'pos'     => $r['pos'],
            'vi'      => $r['vi'],
            'en'      => $r['en'],
            'example' => $r['example_zh'] === '' ? null : [
                'zh'     => $r['example_zh'],
                'pinyin' => $r['example_pinyin'],
                'vi'     => $r['example_vi'],
                'en'     => $r['example_en'],
            ],
        ];
    }
}
