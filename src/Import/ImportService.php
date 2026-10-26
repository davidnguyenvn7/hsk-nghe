<?php

namespace HSKN\Import;

use HSKN\Audio\Mp3;
use HSKN\Install\Schema;
use HSKN\Repository\AudioRepo;
use HSKN\Repository\SentenceRepo;
use HSKN\Repository\TopicRepo;
use HSKN\Repository\WordRepo;
use HSKN\Support\Cache;
use HSKN\Support\Ids;
use HSKN\Support\Paths;

defined('ABSPATH') || exit;

/**
 * Import gói nội dung theo từng bước để chạy được cả trong WP-CLI lẫn trang Admin (mỗi request vài giây):
 *
 *   start()  đọc pack.json + validate toàn bộ          → phase "content"
 *   step()   content: upsert nội dung trong 1 transaction → phase "clips" (hoặc "finalize" nếu --skip-audio)
 *            clips:   copy clip mới theo lô, bỏ qua file đã có cùng tên (tên = hash nội dung)
 *            audio:   ghi bảng hskn_audio (kèm duration_ms đọc từ frame MP3)
 *            finalize: ghi hskn_packs, xoá cache đọc + cache bài nghe đã ghép
 *
 * Trạng thái lưu ở option hskn_import_job (không dùng transient). Chạy lại bao nhiêu lần cũng cho cùng kết quả.
 */
final class ImportService
{
    public const JOB = 'hskn_import_job';
    private const LOCK = 'hskn_import_lock';

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

    public function job(): ?array
    {
        $job = get_option(self::JOB);
        return is_array($job) ? $job : null;
    }

    public function cancel(): void
    {
        delete_option(self::JOB);
        delete_option(self::LOCK);
    }

    /**
     * Mở gói, validate, tạo job. $dryRun: chỉ trả về báo cáo, không tạo job.
     */
    public function start(string $path, bool $skipAudio = false, bool $dryRun = false): array
    {
        $src = PackSource::open($path);
        $pack = $src->json('pack.json');
        // gói chỉ có chữ (pack.py --no-audio): giữ nguyên audio đang có thay vì xoá sạch bảng hskn_audio
        $manifest = $src->json('manifest.json', false);
        if (!$manifest || (empty($manifest['sentences']) && empty($manifest['vocab']))) {
            $skipAudio = true;
        }
        $data = $this->load($src, $skipAudio);
        $errors = $this->validate($data, $src, $skipAudio);
        if ($errors) {
            throw new \RuntimeException("Gói không hợp lệ:\n- " . implode("\n- ", array_slice($errors, 0, 30))
                . (count($errors) > 30 ? "\n… và " . (count($errors) - 30) . ' lỗi khác' : ''));
        }
        $rows = $this->contentRows($data);
        $report = [
            'version' => (string) ($pack['version'] ?? '?'),
            'counts'  => $pack['counts'] ?? [],
            'changes' => $this->diff($rows),
            'clips'   => count($this->clipList($data['manifest'])),
        ];
        if ($dryRun) {
            return $report;
        }
        $job = [
            'path'       => $path,
            'version'    => $report['version'],
            'pack'       => $pack,
            'skip_audio' => $skipAudio,
            'phase'      => 'content',
            'offset'     => 0,
            'total'      => $report['clips'],
            'stats'      => ['changes' => $report['changes'], 'copied' => 0, 'skipped' => 0, 'failed' => 0],
            'started_at' => time(),
            'log'        => ["Bắt đầu import gói {$report['version']}"],
        ];
        update_option(self::JOB, $job, false);
        return $job;
    }

    /**
     * Chạy một bước trong tối đa $budget giây. Trả về job (phase "done" khi xong).
     */
    public function step(float $budget = 8.0): array
    {
        $job = $this->job();
        if (!$job) {
            throw new \RuntimeException('Không có job import nào đang chạy.');
        }
        if ($job['phase'] === 'done') {
            return $job;
        }
        if (!$this->lock()) {
            $job['busy'] = true;
            return $job;
        }
        try {
            @set_time_limit(max(30, (int) $budget * 4));
            $deadline = microtime(true) + $budget;
            $src = PackSource::open($job['path']);
            switch ($job['phase']) {
                case 'content':
                    $this->importContent($src, $job);
                    $job['phase'] = $job['skip_audio'] ? 'finalize' : 'clips';
                    break;
                case 'clips':
                    $this->copyClips($src, $job, $deadline);
                    break;
                case 'audio':
                    $this->importAudio($src, $job);
                    $job['phase'] = 'finalize';
                    break;
                case 'finalize':
                    $this->finalize($job);
                    $job['phase'] = 'done';
                    break;
            }
            unset($job['busy']);
            update_option(self::JOB, $job, false);
            return $job;
        } finally {
            $this->unlock();
        }
    }

    /** Chạy hết các bước (WP-CLI). $tick nhận job sau mỗi bước để in tiến độ. */
    public function run(string $path, bool $skipAudio = false, ?callable $tick = null): array
    {
        $this->cancel();
        $job = $this->start($path, $skipAudio);
        while ($job['phase'] !== 'done') {
            $job = $this->step(20.0);
            if ($tick) {
                $tick($job);
            }
        }
        return $job;
    }

    // ------------------------------------------------------------------ load + validate

    private function load(PackSource $src, bool $skipAudio): array
    {
        return [
            'topics'    => $src->json('topics.json'),
            'vocab'     => $src->json('vocab.json'),
            'sentences' => $src->json('sentences.json'),
            'terms'     => $src->json('topic_terms.json', false) ?? [],
            'manifest'  => $skipAudio ? ['sentences' => [], 'vocab' => []] : ($src->json('manifest.json', false) ?? ['sentences' => [], 'vocab' => []]),
        ];
    }

    private function validate(array $d, PackSource $src, bool $skipAudio): array
    {
        $errors = [];
        $topicIds = [];
        foreach ($d['topics'] as $t) {
            if (!Ids::isTopic((string) ($t['id'] ?? ''))) {
                $errors[] = 'ID chủ đề không hợp lệ: ' . json_encode($t['id'] ?? null);
                continue;
            }
            $topicIds[$t['id']] = true;
        }
        $wordIds = [];
        foreach ($d['vocab'] as $w) {
            if (!Ids::isWord((string) ($w['id'] ?? ''))) {
                $errors[] = 'ID từ không hợp lệ: ' . json_encode($w['id'] ?? null);
            }
            $wordIds[$w['id'] ?? ''] = true;
        }
        foreach ($d['terms'] as $t) {
            if (empty($t['id']) || !Ids::isWord($t['id'])) {
                $errors[] = 'Từ chuyên ngành thiếu ID tường minh (chạy scripts/migrate_ids.py): ' . ($t['hanzi'] ?? '?');
                continue;
            }
            if (empty($t['same_as'])) {
                $wordIds[$t['id']] = true;
            }
        }
        $sentIds = [];
        foreach ($d['sentences'] as $s) {
            $id = (string) ($s['id'] ?? '');
            if (!Ids::isSentence($id)) {
                $errors[] = "ID câu không hợp lệ: $id";
            }
            if (isset($sentIds[$id])) {
                $errors[] = "ID câu bị trùng: $id";
            }
            $sentIds[$id] = true;
            if (!isset($topicIds[$s['topic'] ?? ''])) {
                $errors[] = "Câu $id thuộc chủ đề không tồn tại: " . ($s['topic'] ?? '');
            }
            if (($s['type'] ?? '') === 'E' && !isset($wordIds[$s['word'] ?? ''])) {
                $errors[] = "Câu $id gắn với từ không tồn tại: " . ($s['word'] ?? '');
            }
        }
        foreach ($d['topics'] as $t) {
            foreach ($t['words'] ?? [] as $wid) {
                if (!isset($wordIds[$wid])) {
                    $errors[] = "Chủ đề {$t['id']} có từ không tồn tại: $wid";
                }
            }
        }
        if (!$skipAudio) {
            foreach (['sentences' => $sentIds, 'vocab' => $wordIds] as $group => $known) {
                foreach (array_keys($d['manifest'][$group] ?? []) as $id) {
                    if (!isset($known[$id])) {
                        $errors[] = "manifest.$group có id không có trong dữ liệu: $id";
                    }
                }
            }
            $missing = 0;
            foreach ($this->clipList($d['manifest']) as $clip) {
                if (!Paths::clipPath($clip)) {
                    $errors[] = "Tên clip không hợp lệ: $clip";
                } elseif (!$src->has($clip) && ++$missing <= 5) {
                    $errors[] = "Gói thiếu clip $clip";
                }
            }
        }
        return $errors;
    }

    private function clipList(array $manifest): array
    {
        $files = [];
        foreach (['sentences', 'vocab'] as $group) {
            foreach ($manifest[$group] ?? [] as $entry) {
                foreach ($entry as $file) {
                    $files[$file] = true;
                }
            }
        }
        ksort($files);
        return array_keys($files);
    }

    // ------------------------------------------------------------------ content

    private static function hash(array $row): string
    {
        return substr(md5(wp_json_encode($row)), 0, 16);
    }

    /** Dòng cho từng bảng nội dung (đã gắn content_hash). */
    private function contentRows(array $d): array
    {
        $topics = [];
        $topicWords = [];
        foreach (array_values($d['topics']) as $i => $t) {
            $row = [
                'id'          => $t['id'],
                'slug'        => (string) ($t['slug'] ?? ''),
                'name_vi'     => (string) ($t['name_vi'] ?? ''),
                'name_zh'     => (string) ($t['name_zh'] ?? ''),
                'description' => (string) ($t['desc'] ?? ''),
                'sort'        => $i,
                'stats'       => wp_json_encode($t['stats'] ?? []),
            ];
            $topics[] = $row + ['content_hash' => self::hash($row), 'is_active' => 1];
            $topicWords[$t['id']] = array_values(array_unique($t['words'] ?? []));
        }

        $words = [];
        $hanziToId = [];
        foreach ($d['vocab'] as $w) {
            $ex = $w['example'] ?? null;
            $row = [
                'id'             => $w['id'],
                'hanzi'          => $w['hanzi'],
                'pinyin'         => (string) ($w['pinyin'] ?? ''),
                'level'          => (int) $w['level'],
                'pos'            => implode(', ', (array) ($w['pos'] ?? [])),
                'vi'             => (string) ($w['vi'] ?? ''),
                'en'             => mb_substr((string) ($w['en'] ?? ''), 0, 255),
                'freq_rank'      => isset($w['freq_rank']) ? (int) $w['freq_rank'] : null,
                'example_zh'     => (string) ($ex['zh'] ?? ''),
                'example_pinyin' => (string) ($ex['pinyin'] ?? ''),
                'example_vi'     => (string) ($ex['vi'] ?? ''),
                'example_en'     => (string) ($ex['en'] ?? ''),
            ];
            $words[] = $row + ['content_hash' => self::hash($row), 'is_active' => 1];
            if (in_array((int) $w['level'], [2, 3], true)) {
                $hanziToId[$w['hanzi']] = $w['id'];
            }
        }
        foreach ($d['terms'] as $t) {
            if (!empty($t['same_as'])) {
                continue;
            }
            $row = [
                'id'             => $t['id'],
                'hanzi'          => $t['hanzi'],
                'pinyin'         => str_replace(' ', '', (string) ($t['pinyin'] ?? '')),
                'level'          => ((int) ($t['level'] ?? 0)) ?: 4,
                'pos'            => '',
                'vi'             => (string) ($t['vi'] ?? ''),
                'en'             => (string) ($t['en'] ?? ''),
                'freq_rank'      => null,
                'example_zh'     => '',
                'example_pinyin' => '',
                'example_vi'     => '',
                'example_en'     => '',
            ];
            $words[] = $row + ['content_hash' => self::hash($row), 'is_active' => 1];
        }

        $sentences = [];
        $links = [];
        $sortInTopic = [];
        foreach ($d['sentences'] as $s) {
            // thứ tự trong từng chủ đề: thêm câu vào chủ đề này không làm "đổi" câu của chủ đề khác
            $i = $sortInTopic[$s['topic']] = ($sortInTopic[$s['topic']] ?? -1) + 1;
            $row = [
                'id'          => $s['id'],
                'topic_id'    => $s['topic'],
                'type'        => $s['type'],
                'dialogue_id' => $s['dialogue'] ?? null,
                'speaker'     => $s['speaker'] ?? null,
                'word_id'     => $s['word'] ?? null,
                'zh'          => $s['zh'],
                'pinyin'      => (string) ($s['pinyin'] ?? ''),
                'vi'          => (string) ($s['vi'] ?? ''),
                'en'          => (string) ($s['en'] ?? ''),
                'grammar'     => mb_substr((string) ($s['grammar'] ?? ''), 0, 191),
                'max_level'   => (int) ($s['max_level'] ?? 0),
                'tokens'      => wp_json_encode($s['tokens'] ?? []),
                'sort'        => $i,
            ];
            $sentences[] = $row + ['content_hash' => self::hash($row), 'is_active' => 1];
            $seen = [];
            foreach ($s['tokens'] ?? [] as $tok) {
                $wid = $hanziToId[$tok[0] ?? ''] ?? null;
                if ($wid && !isset($seen[$wid])) {
                    $seen[$wid] = true;
                    $links[] = ['word_id' => $wid, 'sentence_id' => $s['id']];
                }
            }
            if (!empty($s['word']) && !isset($seen[$s['word']])) {
                $links[] = ['word_id' => $s['word'], 'sentence_id' => $s['id']];
            }
        }

        return compact('topics', 'topicWords', 'words', 'sentences', 'links');
    }

    /** Số dòng thêm mới / thay đổi / ngừng dùng cho từng bảng. */
    private function diff(array $rows): array
    {
        $out = [];
        $tables = ['topics' => $this->topics->table(), 'words' => $this->words->table(), 'sentences' => $this->sentences->table()];
        foreach ($tables as $key => $table) {
            $active = $this->words->hashes($table);                    // id => hash (dòng đang dùng)
            $exists = array_flip($this->allIds($table));               // mọi id, kể cả đã ngừng dùng
            $c = ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'removed' => 0];
            foreach ($rows[$key] as $r) {
                if (!isset($exists[$r['id']])) {
                    $c['new']++;
                } elseif (($active[$r['id']] ?? null) === $r['content_hash']) {
                    $c['unchanged']++;
                } else {
                    $c['changed']++;                                     // nội dung đổi hoặc được bật lại
                }
            }
            $c['removed'] = count(array_diff_key($active, array_column($rows[$key], 'id', 'id')));
            $out[$key] = $c;
        }
        return $out;
    }

    private function allIds(string $table): array
    {
        global $wpdb;
        return $wpdb->get_col("SELECT id FROM {$table}"); // phpcs:ignore
    }

    private function importContent(PackSource $src, array &$job): void
    {
        global $wpdb;
        $data = $this->load($src, true);
        $rows = $this->contentRows($data);
        $tables = ['topics' => $this->topics->table(), 'words' => $this->words->table(), 'sentences' => $this->sentences->table()];

        $wpdb->query('START TRANSACTION');
        try {
            foreach ($tables as $key => $table) {
                $old = $this->words->hashes($table);
                $changed = array_values(array_filter($rows[$key], static fn($r) => ($old[$r['id']] ?? null) !== $r['content_hash']));
                if ($changed) {
                    $cols = array_diff(array_keys($changed[0]), ['id']);
                    $this->words->upsert($table, $changed, $cols);
                }
                $removed = $this->words->deactivateExcept($table, array_column($rows[$key], 'id'));
                $job['log'][] = sprintf('%s: ghi %d dòng, ngừng dùng %d', $key, count($changed), $removed);
            }
            $this->topics->replaceWords($rows['topicWords']);
            $this->sentences->replaceWordLinks($rows['links']);
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        Cache::flush();
    }

    // ------------------------------------------------------------------ audio

    private function copyClips(PackSource $src, array &$job, float $deadline): void
    {
        Paths::ensureDirs();
        $manifest = $src->json('manifest.json', false) ?? [];
        $clips = $this->clipList($manifest);
        $job['total'] = count($clips);
        $n = count($clips);
        while ($job['offset'] < $n && microtime(true) < $deadline) {
            $clip = $clips[$job['offset']++];
            $dest = Paths::clipPath($clip);
            if (!$dest) {
                $job['stats']['failed']++;
                continue;
            }
            if (is_file($dest) && filesize($dest) === $src->size($clip)) {
                $job['stats']['skipped']++;
                continue;
            }
            if ($src->copyTo($clip, $dest)) {
                $job['stats']['copied']++;
            } else {
                $job['stats']['failed']++;
                $job['log'][] = "Không copy được $clip";
            }
        }
        if ($job['offset'] >= $n) {
            $job['log'][] = sprintf('Clip: copy %d, đã có %d, lỗi %d', $job['stats']['copied'], $job['stats']['skipped'], $job['stats']['failed']);
            $job['phase'] = 'audio';
        }
    }

    private function importAudio(PackSource $src, array &$job): void
    {
        $manifest = $src->json('manifest.json', false) ?? [];
        $durations = [];
        $rows = [];
        foreach (['sentences' => 'sentence', 'vocab' => 'word'] as $group => $type) {
            foreach ($manifest[$group] ?? [] as $id => $entry) {
                foreach ($entry as $kind => $file) {
                    $path = Paths::clipPath($file);
                    if (!$path || !is_file($path)) {
                        continue;
                    }
                    $durations[$file] ??= Mp3::durationMs($path);
                    $rows[] = ['item_type' => $type, 'item_id' => (string) $id, 'kind' => (string) $kind, 'file' => $file, 'duration_ms' => $durations[$file]];
                }
            }
        }
        $this->audio->replaceAll($rows);
        $job['log'][] = sprintf('Audio: %d clip gắn với %d mục', count($durations), count(array_unique(array_map(static fn($r) => $r['item_type'] . $r['item_id'], $rows))));
    }

    private function finalize(array &$job): void
    {
        global $wpdb;
        $t = Schema::tables();
        $wpdb->query("UPDATE {$t['packs']} SET is_active = 0"); // phpcs:ignore
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$t['packs']} (version, imported_at, counts, is_active) VALUES (%s, %s, %s, 1)
             ON DUPLICATE KEY UPDATE imported_at = VALUES(imported_at), counts = VALUES(counts), is_active = 1", // phpcs:ignore
            $job['version'], current_time('mysql', true), wp_json_encode($job['pack']['counts'] ?? [])
        ));
        Cache::bumpVersion();
        $removed = Cache::clearRendered();
        $job['log'][] = "Xoá $removed bài nghe đã ghép (sẽ ghép lại khi có người nghe)";
        $job['log'][] = 'Xong.';
        $job['finished_at'] = time();
    }

    // ------------------------------------------------------------------ lock

    private function lock(): bool
    {
        if (add_option(self::LOCK, time(), '', false)) {
            return true;
        }
        if ((int) get_option(self::LOCK) < time() - 120) {   // lock cũ do request chết giữa chừng
            update_option(self::LOCK, time(), false);
            return true;
        }
        return false;
    }

    private function unlock(): void
    {
        delete_option(self::LOCK);
    }
}
