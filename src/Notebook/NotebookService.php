<?php

namespace HSKN\Notebook;

use HSKN\Author\AudioService;
use HSKN\Author\Tts;
use HSKN\Install\Schema;
use HSKN\Support\Paths;

defined('ABSPATH') || exit;

/**
 * Sổ tay của người học: câu / từ lưu từ "Tra câu", chia theo "bài" (list) tự đặt tên.
 * Mỗi bài nghe được như một bài nghe thường (track "my-{list id}"): clip đặt tên theo hash nội dung
 * bằng đúng quy tắc của AudioService, nên từ nào đã có clip (trong kho chung) thì dùng lại luôn.
 */
final class NotebookService
{
    public const MAX_ITEMS = 3000;     // mỗi người
    public const MAX_LISTS = 100;
    public const DEFAULT_NAME = 'Sổ tay';

    private \wpdb $db;
    private array $t;

    public function __construct(private int $user)
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->t = Schema::tables();
        if ($user <= 0) {
            throw new \RuntimeException('Cần đăng nhập.');
        }
    }

    // ------------------------------------------------------------------ bài (list)

    public function lists(): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT l.id, l.name, l.updated_at,
                    SUM(s.kind = 'word') AS words, SUM(s.kind = 'sentence') AS sentences
               FROM {$this->t['lists']} l LEFT JOIN {$this->t['saved']} s ON s.list_id = l.id
              WHERE l.user_id = %d GROUP BY l.id ORDER BY l.id",
            $this->user
        ), ARRAY_A) ?: [];
        if (!$rows) {
            $this->createList(self::DEFAULT_NAME);
            return $this->lists();
        }
        return array_map(static fn($r) => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'words' => (int) $r['words'], 'sentences' => (int) $r['sentences'],
            'updated_at' => $r['updated_at'],
        ], $rows);
    }

    /** "w:学习", "s:…" của mọi mục đã lưu (để nút Lưu biết mục nào đã có). */
    public function keys(): array
    {
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT DISTINCT kind, zh FROM {$this->t['saved']} WHERE user_id = %d LIMIT %d", $this->user, self::MAX_ITEMS
        ), ARRAY_A) ?: [];
        return array_map(static fn($r) => ($r['kind'] === 'word' ? 'w:' : 's:') . $r['zh'], $rows);
    }

    public function createList(string $name): array
    {
        $name = self::name($name);
        $n = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->t['lists']} WHERE user_id = %d", $this->user));
        if ($n >= self::MAX_LISTS) {
            throw new \RuntimeException('Bạn đã có ' . self::MAX_LISTS . ' bài, hãy xoá bớt bài cũ.');
        }
        $now = current_time('mysql', true);
        $this->db->insert($this->t['lists'], ['user_id' => $this->user, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        return ['id' => (int) $this->db->insert_id, 'name' => $name, 'words' => 0, 'sentences' => 0, 'updated_at' => $now];
    }

    public function renameList(int $id, string $name): void
    {
        $this->own($id);
        $this->db->update($this->t['lists'], ['name' => self::name($name), 'updated_at' => current_time('mysql', true)], ['id' => $id]);
    }

    public function deleteList(int $id): void
    {
        $this->own($id);
        $this->db->delete($this->t['saved'], ['list_id' => $id]);
        $this->db->delete($this->t['lists'], ['id' => $id]);
    }

    public function find(int $id): array
    {
        return $this->own($id);
    }

    private function own(int $id): array
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->t['lists']} WHERE id = %d AND user_id = %d", $id, $this->user
        ), ARRAY_A);
        if (!$row) {
            throw new \RuntimeException('Không tìm thấy bài này trong sổ tay của bạn.');
        }
        return $row;
    }

    private function defaultList(): int
    {
        return (int) $this->lists()[0]['id'];
    }

    private static function name(string $name): string
    {
        $name = mb_substr(trim(sanitize_text_field($name)), 0, 80);
        if ($name === '') {
            throw new \RuntimeException('Đặt tên cho bài.');
        }
        return $name;
    }

    // ------------------------------------------------------------------ mục (câu / từ)

    public function items(int $listId): array
    {
        $this->own($listId);
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->t['saved']} WHERE list_id = %d ORDER BY id", $listId
        ), ARRAY_A) ?: [];
        return array_map([$this, 'present'], $rows);
    }

    private function present(array $r): array
    {
        $data = json_decode((string) $r['data'], true) ?: [];
        $main = $this->spec($r, $data)[$r['kind'] === 'word' ? 'word' : 'zh'] ?? null;
        $clip = $main ? AudioService::clipName(...$main) : null;
        return [
            'id'      => (int) $r['id'],
            'list_id' => (int) $r['list_id'],
            'kind'    => $r['kind'],
            'zh'      => $r['zh'],
            'pinyin'  => $r['pinyin'],
            'vi'      => $r['vi'],
            'data'    => $data,
            'audio'   => $clip && is_file(Paths::base() . $clip) ? Paths::baseUrl() . $clip : null,
            'created' => $r['created_at'],
        ];
    }

    /**
     * Lưu một câu / từ (trùng trong cùng bài thì cập nhật). Tạo luôn clip giọng đọc nếu dịch vụ TTS đã cấu hình.
     * @param array $in {kind, zh, pinyin, vi, data, list_id?}
     */
    public function save(array $in): array
    {
        $kind = ($in['kind'] ?? '') === 'word' ? 'word' : 'sentence';
        $zh = mb_substr(trim(sanitize_text_field((string) ($in['zh'] ?? ''))), 0, $kind === 'word' ? 20 : 190);
        if ($zh === '' || !preg_match('/\p{Han}/u', $zh)) {
            throw new \RuntimeException('Mục cần lưu phải có chữ Hán.');
        }
        $listId = (int) ($in['list_id'] ?? 0) ?: $this->defaultList();
        $this->own($listId);
        $count = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->t['saved']} WHERE user_id = %d", $this->user));
        if ($count >= self::MAX_ITEMS) {
            throw new \RuntimeException('Sổ tay đã đầy (' . self::MAX_ITEMS . ' mục). Hãy xoá bớt mục cũ.');
        }
        $row = [
            'user_id'    => $this->user,
            'list_id'    => $listId,
            'kind'       => $kind,
            'zh'         => $zh,
            'pinyin'     => mb_substr(sanitize_text_field((string) ($in['pinyin'] ?? '')), 0, 500),
            'vi'         => mb_substr(sanitize_text_field((string) ($in['vi'] ?? '')), 0, 500),
            'data'       => wp_json_encode(self::cleanData(is_array($in['data'] ?? null) ? $in['data'] : [])),
            'created_at' => current_time('mysql', true),
        ];
        $id = (int) $this->db->get_var($this->db->prepare(
            "SELECT id FROM {$this->t['saved']} WHERE list_id = %d AND kind = %s AND zh = %s", $listId, $kind, $zh
        ));
        if ($id) {
            unset($row['created_at']);
            $this->db->update($this->t['saved'], $row, ['id' => $id]);
        } else {
            $this->db->insert($this->t['saved'], $row);
            $id = (int) $this->db->insert_id;
        }
        $this->db->update($this->t['lists'], ['updated_at' => current_time('mysql', true)], ['id' => $listId]);
        $saved = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t['saved']} WHERE id = %d", $id), ARRAY_A);
        $this->ensureClips([$saved], 8);
        return $this->present($saved);
    }

    public function delete(int $id): void
    {
        $this->db->delete($this->t['saved'], ['id' => $id, 'user_id' => $this->user]);
    }

    /** Chỉ giữ các trường biết trước, chuỗi đã làm sạch (dữ liệu đến từ trình duyệt). */
    private static function cleanData(array $d): array
    {
        $s = static fn($v, int $max = 600) => mb_substr(sanitize_text_field(is_scalar($v) ? (string) $v : ''), 0, $max);
        $out = [];
        foreach (['han_viet', 'pos', 'explain', 'note', 'input'] as $k) {
            if (isset($d[$k]) && ($v = $s($d[$k])) !== '') {
                $out[$k] = $v;
            }
        }
        if (!empty($d['chars']) && is_array($d['chars'])) {
            foreach (array_slice(array_filter($d['chars'], 'is_array'), 0, 8) as $c) {
                $out['chars'][] = ['c' => $s($c['c'] ?? '', 2), 'pinyin' => $s($c['pinyin'] ?? '', 20), 'han_viet' => $s($c['han_viet'] ?? '', 30), 'vi' => $s($c['vi'] ?? '', 120)];
            }
        }
        if (!empty($d['example']['zh'])) {
            $out['example'] = ['zh' => $s($d['example']['zh'], 120), 'pinyin' => $s($d['example']['pinyin'] ?? ''), 'vi' => $s($d['example']['vi'] ?? '')];
        }
        return $out;
    }

    // ------------------------------------------------------------------ audio + bài nghe

    /** Clip phải có của một mục: kind => [chữ, giọng, tốc độ] (cùng quy tắc với nội dung chính). */
    private function spec(array $r, array $data): array
    {
        if ($r['kind'] === 'word') {
            $ex = $data['example'] ?? [];
            $spec = AudioService::wordSpec(['hanzi' => $r['zh'], 'vi' => $r['vi'], 'en' => '',
                'example_zh' => $ex['zh'] ?? '', 'example_vi' => $ex['vi'] ?? '', 'example_en' => '']);
        } else {
            $spec = AudioService::sentenceSpec(['zh' => $r['zh'], 'vi' => $r['vi'], 'en' => '', 'speaker' => null]);
        }
        return array_filter($spec, static fn($s) => trim((string) $s[0]) !== '');
    }

    /** Tạo clip còn thiếu (tối đa $max clip mỗi lần gọi). Không có dịch vụ TTS thì bỏ qua: trình phát đọc bằng giọng máy. */
    public function ensureClips(array $rows, int $max = 40): int
    {
        $tts = new Tts();
        if ($tts->ready()) {
            return 0;
        }
        $jobs = [];
        foreach ($rows as $r) {
            foreach ($this->spec($r, json_decode((string) $r['data'], true) ?: []) as [$text, $voice, $rate]) {
                $path = Paths::base() . AudioService::clipName($text, $voice, $rate);
                if (!is_file($path) && !isset($jobs[$path]) && count($jobs) < $max) {
                    $jobs[$path] = ['text' => $text, 'voice' => $voice, 'rate' => $rate, 'path' => $path];
                }
            }
        }
        if (!$jobs) {
            return 0;
        }
        Paths::ensureDirs();
        try {
            return count($tts->many(array_values($jobs))['ok']);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Nội dung cho PlaylistService::buildMixed(): các mục theo thứ tự đã lưu, kèm clip đang có.
     * @return array{track: array, items: array}
     */
    public function trackContent(int $listId): array
    {
        $list = $this->own($listId);
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->t['saved']} WHERE list_id = %d ORDER BY id", $listId
        ), ARRAY_A) ?: [];
        $this->ensureClips($rows, 30);   // tự vá clip thiếu (ví dụ lúc lưu chưa cấu hình giọng đọc)
        $items = [];
        foreach ($rows as $r) {
            $data = json_decode((string) $r['data'], true) ?: [];
            $clips = [];
            foreach ($this->spec($r, $data) as $kind => $s) {
                $file = AudioService::clipName(...$s);
                if (is_file(Paths::base() . $file)) {
                    $clips[$kind] = $file;
                }
            }
            $items[] = ['id' => 'my' . $r['id'], 'kind' => $r['kind'], 'zh' => $r['zh'], 'pinyin' => $r['pinyin'], 'vi' => $r['vi'],
                        'han_viet' => $data['han_viet'] ?? '', 'example' => $data['example'] ?? null, 'clips' => $clips];
        }
        $w = count(array_filter($items, static fn($i) => $i['kind'] === 'word'));
        $track = [
            'id' => 'my-' . $listId, 'type' => 'mine', 'group' => 'mine', 'title' => $list['name'],
            'subtitle' => self::subtitle($w, count($items) - $w), 'items' => count($items), 'prev' => null, 'next' => null,
        ];
        return ['track' => $track, 'items' => $items];
    }

    public static function subtitle(int $words, int $sentences): string
    {
        $p = [];
        if ($words) {
            $p[] = "$words từ";
        }
        if ($sentences) {
            $p[] = "$sentences câu";
        }
        return 'Sổ tay · ' . ($p ? implode(', ', $p) : 'chưa có mục nào');
    }
}
