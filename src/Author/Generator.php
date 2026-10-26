<?php

namespace HSKN\Author;

use HSKN\Install\Schema;

defined('ABSPATH') || exit;

/**
 * Sinh từ / câu bằng DeepSeek cho trang "Thêm nội dung" (cùng quy tắc với scripts/generate.py).
 * Không ghi gì vào DB: kết quả trả về cho admin duyệt, AuthorService::save() mới ghi.
 */
final class Generator
{
    private DeepSeek $ds;

    public function __construct()
    {
        $this->ds = new DeepSeek();
    }

    public function tokens(): int
    {
        return $this->ds->tokens;
    }

    /**
     * @param array $topic ['name_vi','desc','name_zh'?]
     * @return array{name_zh:string, desc:string, words:array}
     */
    public function proposeWords(array $topic, int $count, array $list, array $exclude): array
    {
        $ask = "Topic (Vietnamese): {$topic['name_vi']}. Description: " . ($topic['desc'] ?? '') . ".\n"
            . ($list
                ? 'Give pinyin/meaning for exactly these words: ' . implode('、', $list) . ".\n"
                : "List the {$count} Chinese words or short phrases most useful for this topic in daily work conversations.\n"
                  . "- Prefer words from HSK 1–3. Specialized terms (outside HSK) are fine when the topic needs them.\n"
                  . "- Each item is a single lexical word or fixed phrase, 1–4 characters, no sentences.\n"
                  . ($exclude ? '- Do NOT include any of: ' . implode('、', $exclude) . "\n" : ''))
            . "For each item give: hanzi (simplified), pinyin (tone marks, syllables separated by spaces), "
            . "vi (short natural Vietnamese meaning in the context of this topic, max 6 words), en (short English gloss, max 4 words).\n"
            . (empty($topic['name_zh']) ? "Also give name_zh (2–6 character Chinese title of the topic) and desc (one Vietnamese line listing sub-situations).\n" : '')
            . 'JSON: {"name_zh": "...", "desc": "...", "words": [{"hanzi": "...", "pinyin": "...", "vi": "...", "en": "..."}]}';
        $data = $this->ds->json($ask, 0.7);

        $words = [];
        $seen = array_flip($exclude);
        foreach ((array) ($data['words'] ?? []) as $w) {
            $h = trim((string) ($w['hanzi'] ?? ''));
            if ($h === '' || !preg_match('/^[' . Checker::HAN . ']{1,6}$/u', $h) || isset($seen[$h])) {
                continue;
            }
            $seen[$h] = true;
            $words[] = $this->describe($h, $w);
            if (!$list && count($words) >= $count) {
                break;
            }
        }
        return [
            'name_zh' => trim((string) ($data['name_zh'] ?? ($topic['name_zh'] ?? ''))),
            'desc'    => trim((string) ($data['desc'] ?? '')),
            'words'   => $words,
        ];
    }

    /** Từ đã có trong DB (HSK hoặc chuyên ngành) thì dùng lại ID + nghĩa sẵn có (đã có audio). */
    private function describe(string $h, array $w): array
    {
        global $wpdb;
        $t = Schema::tables();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, pinyin, vi, en, level FROM {$t['words']} WHERE hanzi = %s AND is_active = 1 ORDER BY (id LIKE 'H%%') DESC, id LIMIT 1", $h // phpcs:ignore
        ), ARRAY_A);
        if ($row) {
            return ['hanzi' => $h, 'id' => $row['id'], 'pinyin' => $row['pinyin'], 'vi' => $row['vi'],
                    'en' => $row['en'] ? strtok($row['en'], ';') : (string) ($w['en'] ?? ''), 'level' => (int) $row['level'], 'existing' => true];
        }
        $lv = Checker::hskLevel($h);
        return ['hanzi' => $h, 'id' => null, 'pinyin' => trim((string) ($w['pinyin'] ?? '')),
                'vi' => trim(str_replace('|', '/', (string) ($w['vi'] ?? ''))), 'en' => trim(str_replace('|', '/', (string) ($w['en'] ?? ''))),
                'level' => $lv ?: 4, 'existing' => false];
    }

    /**
     * Câu do người dùng tự sửa: nhờ DeepSeek tách từ + gắn pinyin, rồi kiểm tra như câu sinh ra.
     * $word = từ đích phải có trong câu ('' với mẫu câu chủ đề).
     *
     * @return array{zh:string, pinyin:string, tokens:array, max_level:int, errors:string[]}
     */
    public function analyze(string $zh, string $word = '', int $maxChars = 30): array
    {
        $zh = trim(str_replace('|', '，', $zh));
        $ask = "Analyze this Mandarin sentence for a textbook. Do not change it.\n"
            . "Sentence: {$zh}\n"
            . "- pinyin: whole sentence with tone marks, mainland textbook style (yí ge, bú shì), capitalize the first letter.\n"
            . "- tokens: the sentence split into words, each [word, pinyin], punctuation as its own token with empty pinyin.\n"
            . 'JSON: {"pinyin": "...", "tokens": [["这个","zhège"],["。",""]]}';
        $data = $this->ds->json($ask, 0.2);
        $res = (new Checker($maxChars, $word !== '' ? [$word] : []))->check($zh, $word, (array) ($data['tokens'] ?? []));
        return ['zh' => $zh, 'pinyin' => trim((string) ($data['pinyin'] ?? ''))] + $res;
    }

    /**
     * Sinh câu cho một lô từ (≤ 8 từ / lần gọi), kiểm tra, gửi lại câu bị loại tối đa $rounds vòng.
     *
     * @param array $words [['hanzi','vi', 'have' => [câu đã có]]]
     * @return array<string, array{accepted: array, rejected: array}>  hanzi => …
     */
    public function sentences(array $topic, array $words, int $perWord, int $maxChars = 18, int $rounds = 2): array
    {
        $checker = new Checker($maxChars, array_column($words, 'hanzi'));
        $out = [];
        $need = [];
        foreach ($words as $w) {
            $out[$w['hanzi']] = ['accepted' => [], 'rejected' => []];
            $need[$w['hanzi']] = $perWord;
        }
        $feedback = '';
        for ($round = 0; $round <= $rounds; $round++) {
            $pending = array_filter($words, static fn($w) => $need[$w['hanzi']] > 0);
            if (!$pending) {
                break;
            }
            $lines = array_map(static fn($w) => "- {$w['hanzi']} ({$w['vi']}): {$need[$w['hanzi']]} sentence(s)", $pending);
            $ask = "Topic: {$topic['name_vi']} (" . ($topic['name_zh'] ?? '') . '). ' . ($topic['desc'] ?? '') . "\n"
                . "For EACH target word below write the requested number of different, natural spoken Mandarin sentences set in this topic's real work/daily situations.\n"
                . "Rules:\n"
                . "- The sentence MUST contain the target word written exactly as given.\n"
                . "- At most {$maxChars} Chinese characters. End with 。？ or ！. Write numbers in Chinese characters.\n"
                . '- Use only HSK 1–3 vocabulary, plus the target words: ' . implode('、', array_column($words, 'hanzi')) . "\n"
                . "- Vary the patterns (question, statement, request, negative). No personal names.\n"
                . "- pinyin: whole sentence with tone marks, mainland textbook style (yí ge, bú shì).\n"
                . "- tokens: the sentence split into words, each [word, pinyin], punctuation as its own token with empty pinyin.\n"
                . "- vi: natural Vietnamese translation. en: natural English translation. grammar: key pattern, short (may be empty).\n"
                . "Target words:\n" . implode("\n", $lines)
                . ($feedback ? "\n\nPrevious attempt had problems, avoid them:\n$feedback" : '')
                . "\n" . 'JSON: {"items": [{"word": "...", "sentences": [{"zh": "...", "pinyin": "...", "tokens": [["这个","zhège"],["。",""]], "vi": "...", "en": "...", "grammar": "..."}]}]}';
            $data = $this->ds->json($ask);
            $feedback = '';
            foreach ((array) ($data['items'] ?? []) as $item) {
                $h = trim((string) ($item['word'] ?? ''));
                if (!isset($need[$h])) {
                    continue;
                }
                foreach ((array) ($item['sentences'] ?? []) as $s) {
                    $zh = str_replace('|', '，', trim((string) ($s['zh'] ?? '')));
                    if ($need[$h] <= 0 || $zh === '') {
                        break;
                    }
                    $have = array_merge($words[array_search($h, array_column($words, 'hanzi'), true)]['have'] ?? [], array_column($out[$h]['accepted'], 'zh'));
                    $res = $checker->check($zh, $h, (array) ($s['tokens'] ?? []));
                    if (in_array($zh, $have, true)) {
                        $res['errors'][] = 'trùng câu đã có';
                    }
                    if ($res['errors']) {
                        $out[$h]['rejected'][] = ['zh' => $zh, 'errors' => $res['errors']];
                        $feedback .= "- $h: 「{$zh}」 → " . implode('; ', $res['errors']) . "\n";
                        continue;
                    }
                    $clean = static fn($v) => trim(str_replace('|', '/', (string) $v));
                    $out[$h]['accepted'][] = [
                        'zh' => $zh, 'pinyin' => $clean($s['pinyin'] ?? ''), 'vi' => $clean($s['vi'] ?? ''),
                        'en' => $clean($s['en'] ?? ''), 'grammar' => mb_substr($clean($s['grammar'] ?? ''), 0, 120),
                        'tokens' => $res['tokens'], 'max_level' => $res['max_level'],
                    ];
                    $need[$h]--;
                }
            }
        }
        return $out;
    }
}
