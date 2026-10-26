<?php

namespace HSKN\Author;

use HSKN\Install\Schema;

defined('ABSPATH') || exit;

/**
 * Kiểm tra câu do DeepSeek sinh (bản PHP của Checker trong scripts/generate.py).
 *
 * DeepSeek trả kèm phần tách từ: [["这个","zhège"],["房子","fángzi"],…]. Ở đây kiểm tra:
 * ghép lại đúng câu, chứa từ đích, độ dài, dấu câu cuối, không có chữ Latin/số,
 * và mọi từ đều thuộc HSK 1–3 hoặc là từ chuyên ngành đã có.
 * Cấp từng từ: 1–3 = HSK, 4 = chuyên ngành, 0 = ngoài danh sách.
 */
final class Checker
{
    private const NUM = '零一二三四五六七八九十百千万亿两';
    /** Chữ Hán thật (CJK Unified + Ext A). Không dùng \p{Han}: PCRE tính cả dấu 。 vào đó. */
    public const HAN = '\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}';
    private static ?array $levels = null;
    private array $terms = [];
    private int $maxLen = 4;

    public function __construct(public int $maxChars = 18, array $extraTerms = [])
    {
        global $wpdb;
        if (self::$levels === null) {
            self::$levels = json_decode((string) file_get_contents(HSKN_DIR . 'data/hsk-levels.json'), true) ?: [];
        }
        $t = Schema::tables();
        foreach ($wpdb->get_col("SELECT hanzi FROM {$t['words']} WHERE is_active = 1 AND id LIKE 'T%'") as $h) { // phpcs:ignore
            $this->terms[$h] = true;
        }
        foreach ($extraTerms as $h) {
            $this->terms[$h] = true;
        }
        foreach (array_keys(self::$levels + $this->terms) as $w) {
            $this->maxLen = max($this->maxLen, mb_strlen($w));
        }
    }

    public function addTerm(string $hanzi): void
    {
        $this->terms[$hanzi] = true;
    }

    public static function hskLevel(string $hanzi): int
    {
        if (self::$levels === null) {
            self::$levels = json_decode((string) file_get_contents(HSKN_DIR . 'data/hsk-levels.json'), true) ?: [];
        }
        return (int) (self::$levels[$hanzi] ?? 0);
    }

    public function level(string $tok): int
    {
        if (isset(self::$levels[$tok])) {
            return self::$levels[$tok];
        }
        if ($this->isNumber($tok)) {
            return 1;
        }
        if (isset($this->terms[$tok])) {
            return 4;
        }
        $base = mb_strlen($tok) > 1 && str_ends_with($tok, '儿') ? mb_substr($tok, 0, -1) : $tok;
        if (isset(self::$levels[$base])) {
            return self::$levels[$base];
        }
        $chars = mb_str_split($base);
        if (count($chars) === 2 && $chars[0] === $chars[1] && isset(self::$levels[$chars[0]])) {   // 看看
            return self::$levels[$chars[0]];
        }
        // 这个 → 这 + 个: tách theo từ dài nhất đã biết
        $max = 0;
        $i = 0;
        $n = count($chars);
        while ($i < $n) {
            $found = false;
            for ($len = min($this->maxLen, $n - $i); $len >= 1; $len--) {
                $piece = implode('', array_slice($chars, $i, $len));
                if (isset(self::$levels[$piece]) || isset($this->terms[$piece]) || $this->isNumber($piece)) {
                    $max = max($max, self::$levels[$piece] ?? (isset($this->terms[$piece]) ? 4 : 1));
                    $i += $len;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return 0;
            }
        }
        return $max;
    }

    private function isNumber(string $s): bool
    {
        foreach (mb_str_split($s) as $c) {
            if (!str_contains(self::NUM, $c)) {
                return false;
            }
        }
        return $s !== '';
    }

    /**
     * @param array $tokens [[từ, pinyin], …] từ DeepSeek
     * @return array{errors: string[], tokens: array, max_level: int}
     */
    public function check(string $zh, string $word, array $tokens): array
    {
        $errors = [];
        if ($word !== '' && !str_contains($zh, $word)) {
            $errors[] = "không chứa từ đích $word";
        }
        $han = preg_match_all('/[' . self::HAN . ']/u', $zh);
        if ($han > $this->maxChars) {
            $errors[] = "dài $han chữ (tối đa {$this->maxChars})";
        }
        if (!preg_match('/[。？！]$/u', $zh)) {
            $errors[] = 'thiếu dấu câu cuối (。？！)';
        }
        if (preg_match('/[A-Za-z0-9０-９]/u', $zh)) {
            $errors[] = 'có chữ Latin/số Ả Rập (hãy viết số bằng chữ Hán)';
        }
        $joined = '';
        $out = [];
        $unknown = [];
        $max = 0;
        foreach ($tokens as $t) {
            $w = is_array($t) ? (string) ($t[0] ?? '') : '';
            $py = is_array($t) ? trim((string) ($t[1] ?? '')) : '';
            $joined .= $w;
            if (!preg_match('/[' . self::HAN . ']/u', $w)) {
                $out[] = [$w, '', null];
                continue;
            }
            $lv = $w === $word ? max(1, $this->level($w)) : $this->level($w);
            if ($lv === 0) {
                $unknown[] = $w;
            }
            $max = max($max, $lv === 0 ? 5 : $lv);
            $out[] = [$w, $py, $lv];
        }
        $strip = static fn($s) => preg_replace('/[^' . self::HAN . ']/u', '', $s);
        if ($strip($joined) !== $strip($zh)) {
            $errors[] = 'phần tách từ không khớp với câu';
        }
        if ($unknown) {
            $errors[] = 'dùng từ ngoài HSK 1–3: ' . implode('、', $unknown);
        }
        return ['errors' => $errors, 'tokens' => $out, 'max_level' => $max];
    }
}
