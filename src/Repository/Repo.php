<?php

namespace HSKN\Repository;

use HSKN\Install\Schema;

defined('ABSPATH') || exit;

abstract class Repo
{
    protected \wpdb $db;
    protected array $t;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->t = Schema::tables();
    }

    /** "%s,%s,%s" cho mệnh đề IN. */
    protected function placeholders(array $values, string $p = '%s'): string
    {
        return implode(',', array_fill(0, count($values), $p));
    }

    /**
     * INSERT nhiều dòng … ON DUPLICATE KEY UPDATE (cột $update), theo lô.
     *
     * @param array<int,array<string,mixed>> $rows cùng bộ cột
     */
    public function upsert(string $table, array $rows, array $update, int $chunk = 200): int
    {
        if (!$rows) {
            return 0;
        }
        $cols = array_keys($rows[0]);
        $colSql = '`' . implode('`,`', $cols) . '`';
        $updSql = implode(',', array_map(static fn($c) => "`$c`=VALUES(`$c`)", $update));
        $n = 0;
        foreach (array_chunk($rows, $chunk) as $batch) {
            $values = [];
            $args = [];
            foreach ($batch as $row) {
                $ph = [];
                foreach ($cols as $c) {
                    $v = $row[$c];
                    if ($v === null) {
                        $ph[] = 'NULL';
                    } else {
                        $ph[] = is_int($v) ? '%d' : '%s';
                        $args[] = $v;
                    }
                }
                $values[] = '(' . implode(',', $ph) . ')';
            }
            $sql = "INSERT INTO {$table} ({$colSql}) VALUES " . implode(',', $values)
                . ($updSql ? " ON DUPLICATE KEY UPDATE {$updSql}" : '');
            $res = $this->db->query($args ? $this->db->prepare($sql, $args) : $sql); // phpcs:ignore WordPress.DB.PreparedSQL
            if ($res === false) {
                throw new \RuntimeException('DB: ' . $this->db->last_error);
            }
            $n += count($batch);
        }
        return $n;
    }

    /** id => content_hash của các dòng đang active (để chỉ ghi dòng thay đổi hoặc cần bật lại khi import). */
    public function hashes(string $table): array
    {
        $rows = $this->db->get_results("SELECT id, content_hash FROM {$table} WHERE is_active = 1 AND source = 'pack'", ARRAY_A); // phpcs:ignore
        return array_column($rows ?: [], 'content_hash', 'id');
    }

    /** Đặt is_active = 0 cho các id của gói không còn trong gói (không xoá: tiến độ người học còn tham chiếu). */
    public function deactivateExcept(string $table, array $keepIds): int
    {
        $all = $this->db->get_col("SELECT id FROM {$table} WHERE is_active = 1 AND source = 'pack'"); // phpcs:ignore
        $gone = array_values(array_diff($all, $keepIds));
        foreach (array_chunk($gone, 500) as $chunk) {
            $this->db->query($this->db->prepare(
                "UPDATE {$table} SET is_active = 0 WHERE id IN (" . $this->placeholders($chunk) . ')', $chunk // phpcs:ignore
            ));
        }
        return count($gone);
    }
}
