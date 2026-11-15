<?php

namespace HSKN\Analyze;

use HSKN\Author\AudioService;
use HSKN\Author\DeepSeek;
use HSKN\Author\Tts;
use HSKN\Support\Paths;

defined('ABSPATH') || exit;

/**
 * "Phân tích câu": người học nhập một câu / cụm từ (tiếng Trung hoặc tiếng Việt) →
 * DeepSeek tách ý, tách từ, giải thích nghĩa, pinyin, Hán Việt.
 *
 * Kết quả được cache theo chữ nhập (transient), nên câu đã phân tích rồi không tốn token nữa.
 * Mỗi chữ Trung trong kết quả kèm chữ ký HMAC: endpoint audio chỉ đọc đúng những chữ server đã trả ra,
 * không ai dùng được nó để đọc văn bản tuỳ ý bằng key Google của site.
 */
final class Analyzer
{
    public const MAX_LEN = 200;
    private const CACHE_TTL = 60 * DAY_IN_SECONDS;
    private const PROMPT_VERSION = 1;   // đổi prompt → tăng số này để bỏ cache cũ

    /** Tốc độ đọc: n = bình thường (câu, ý), w = từ (hơi chậm), s = chậm. */
    public const RATES = ['n' => '+0%', 'w' => '-10%', 's' => '-25%'];

    private const SYSTEM = 'You are an experienced Mandarin teacher for Vietnamese learners (HSK 1–4). '
        . 'You explain Chinese sentences clearly and accurately in Vietnamese, using simplified characters and mainland usage. '
        . 'You know Sino-Vietnamese readings (âm Hán Việt) precisely. Always answer with one JSON object only.';

    public int $tokens = 0;

    public static function normalize(string $text): string
    {
        $text = wp_strip_all_tags($text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    public static function lang(string $text): string
    {
        return preg_match('/\p{Han}/u', $text) ? 'zh' : 'vi';
    }

    /**
     * @param callable|null $beforeCall chạy ngay trước khi gọi DeepSeek (cache trượt) — dùng để đếm lượt
     * @return array{result: array, cached: bool}
     */
    public function analyze(string $text, ?callable $beforeCall = null): array
    {
        $text = self::normalize($text);
        if ($text === '') {
            throw new \RuntimeException('Nhập một câu hoặc cụm từ.');
        }
        if (mb_strlen($text) > self::MAX_LEN) {
            throw new \RuntimeException('Câu dài quá (tối đa ' . self::MAX_LEN . ' ký tự). Hãy tách thành câu ngắn hơn.');
        }
        $key = 'hskn_an' . self::PROMPT_VERSION . '_' . md5($text);
        $hit = get_transient($key);
        if (is_array($hit)) {
            return ['result' => $hit, 'cached' => true];
        }
        $ds = new DeepSeek();
        if ($beforeCall) {
            $beforeCall();
        }
        $raw = $ds->json($this->prompt($text, self::lang($text)), 0.3, self::SYSTEM);
        $this->tokens = $ds->tokens;
        $result = $this->clean($raw, $text);
        if ($result['zh'] === '') {
            throw new \RuntimeException('Không phân tích được câu này, thử viết lại rõ hơn.');
        }
        set_transient($key, $result, self::CACHE_TTL);
        return ['result' => $result, 'cached' => false];
    }

    private function prompt(string $text, string $lang): string
    {
        $intro = $lang === 'zh'
            ? "Analyze this Chinese sentence / phrase for a Vietnamese learner:\n«{$text}»\n"
              . "If it contains typos or wrong characters, use the corrected version in \"zh\" and mention the correction in \"note\"."
            : "The learner typed this in Vietnamese:\n«{$text}»\n"
              . 'First translate it into natural, everyday spoken Mandarin (what a native speaker would actually say), put that in "zh", '
              . 'then analyze that Chinese sentence.';

        return $intro . "\n\n" . <<<'TXT'
Return JSON with exactly these keys (all explanations in Vietnamese, short and clear; pinyin with tone marks):
{
  "zh": "the Chinese sentence (simplified)",
  "pinyin": "pinyin of the whole sentence, words separated by spaces",
  "han_viet": "âm Hán Việt of every character in order, lowercase, separated by spaces (punctuation omitted)",
  "vi": "natural Vietnamese meaning",
  "note": "1–2 sentences: overall structure, nuance, when to use it (or the typo correction). Empty string if nothing useful",
  "parts": [
    { "zh": "a meaning chunk of the sentence (subject / time / place / verb phrase / clause…)", "pinyin": "...", "vi": "meaning of this chunk", "explain": "role of this chunk in the sentence and how it is built" }
  ],
  "words": [
    { "hanzi": "word", "pinyin": "...", "han_viet": "âm Hán Việt (lowercase)", "pos": "từ loại bằng tiếng Việt (danh từ, động từ, lượng từ, trợ từ…)",
      "vi": "nghĩa ngắn trong câu này", "explain": "giải thích thêm: nghĩa khác, cách dùng, lỗi hay gặp (1–2 câu)",
      "chars": [ { "c": "one character", "pinyin": "...", "han_viet": "...", "vi": "nghĩa gốc của chữ" } ],
      "example": { "zh": "one short new example sentence (HSK 2–3 level)", "pinyin": "...", "vi": "..." } }
  ],
  "grammar": [
    { "pattern": "grammar pattern, e.g. 虽然…但是…", "explain": "cách dùng", "example_zh": "...", "example_vi": "..." }
  ]
}
Rules:
- "parts": split the sentence into its ideas in order; joined together they must give back the whole sentence. One part for a very short phrase.
- "words": every meaningful word of the sentence in order of appearance (skip punctuation, do not repeat a word). Particles like 的, 了, 吗, 呢 are included with their function explained.
- "chars": only for words of 2+ characters; one entry per character.
- "grammar": 0–3 notable patterns actually used in the sentence; empty array if none.
- "han_viet" must be the real Sino-Vietnamese reading (e.g. 学习 → "học tập", 银行 → "ngân hàng", 的 → "đích"), never a translation.
TXT;
    }

    private function clean(array $raw, string $input): array
    {
        $str = static fn($v, int $max = 400) => mb_substr(trim(wp_strip_all_tags((string) (is_scalar($v) ? $v : ''))), 0, $max);
        $list = static fn($v, int $max) => array_slice(array_values(array_filter(is_array($v) ? $v : [], 'is_array')), 0, $max);

        $out = [
            'input'    => $input,
            'lang'     => self::lang($input),
            'zh'       => $str($raw['zh'] ?? '', 300),
            'pinyin'   => $str($raw['pinyin'] ?? ''),
            'han_viet' => $str($raw['han_viet'] ?? ''),
            'vi'       => $str($raw['vi'] ?? ''),
            'note'     => $str($raw['note'] ?? '', 600),
            'parts'    => [],
            'words'    => [],
            'grammar'  => [],
        ];
        foreach ($list($raw['parts'] ?? [], 12) as $p) {
            if (($zh = $str($p['zh'] ?? '', 200)) !== '') {
                $out['parts'][] = ['zh' => $zh, 'pinyin' => $str($p['pinyin'] ?? ''), 'vi' => $str($p['vi'] ?? ''), 'explain' => $str($p['explain'] ?? '', 600)];
            }
        }
        $seen = [];
        foreach ($list($raw['words'] ?? [], 40) as $w) {
            $hz = $str($w['hanzi'] ?? '', 20);
            if ($hz === '' || isset($seen[$hz]) || !preg_match('/\p{Han}/u', $hz)) {
                continue;
            }
            $seen[$hz] = true;
            $chars = [];
            if (mb_strlen($hz) > 1) {
                foreach ($list($w['chars'] ?? [], 8) as $c) {
                    if (($ch = $str($c['c'] ?? '', 2)) !== '') {
                        $chars[] = ['c' => $ch, 'pinyin' => $str($c['pinyin'] ?? '', 20), 'han_viet' => $str($c['han_viet'] ?? '', 30), 'vi' => $str($c['vi'] ?? '', 120)];
                    }
                }
            }
            $ex = is_array($w['example'] ?? null) ? $w['example'] : [];
            $out['words'][] = [
                'hanzi'    => $hz,
                'pinyin'   => $str($w['pinyin'] ?? '', 60),
                'han_viet' => $str($w['han_viet'] ?? '', 60),
                'pos'      => $str($w['pos'] ?? '', 40),
                'vi'       => $str($w['vi'] ?? '', 200),
                'explain'  => $str($w['explain'] ?? '', 600),
                'chars'    => $chars,
                'example'  => ($exZh = $str($ex['zh'] ?? '', 120)) !== ''
                    ? ['zh' => $exZh, 'pinyin' => $str($ex['pinyin'] ?? ''), 'vi' => $str($ex['vi'] ?? '')] : null,
            ];
        }
        foreach ($list($raw['grammar'] ?? [], 3) as $g) {
            if (($pat = $str($g['pattern'] ?? '', 60)) !== '') {
                $out['grammar'][] = ['pattern' => $pat, 'explain' => $str($g['explain'] ?? '', 600),
                                     'example_zh' => $str($g['example_zh'] ?? '', 120), 'example_vi' => $str($g['example_vi'] ?? '')];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ audio

    public static function sign(string $text, string $rate): string
    {
        return substr(hash_hmac('sha256', "an|$rate|$text", wp_salt('auth')), 0, 20);
    }

    /** Thông tin để phát một đoạn: chữ ký, và URL nếu clip đã có sẵn (bấm loa là phát luôn, không cần gọi server). */
    public static function speakable(string $text, string $rate): array
    {
        $clip = AudioService::clipName($text, AudioService::VOICE['zh_f'], self::RATES[$rate]);
        $out = ['t' => $text, 'r' => $rate, 'k' => self::sign($text, $rate)];
        if (is_file(Paths::base() . $clip)) {
            $out['u'] = Paths::baseUrl() . $clip;
        }
        return $out;
    }

    /** Gắn thông tin audio cho mọi chữ Trung trong kết quả (tính lúc trả về, không lưu vào cache). */
    public static function decorate(array $r): array
    {
        $r['audio'] = self::speakable($r['zh'], 'n');
        $r['audio_slow'] = self::speakable($r['zh'], 's');
        foreach ($r['parts'] as &$p) {
            $p['audio'] = self::speakable($p['zh'], 'n');
        }
        unset($p);
        foreach ($r['words'] as &$w) {
            $w['audio'] = self::speakable($w['hanzi'], 'w');
            if ($w['example']) {
                $w['example']['audio'] = self::speakable($w['example']['zh'], 'n');
            }
        }
        unset($w);
        foreach ($r['grammar'] as &$g) {
            if ($g['example_zh'] !== '') {
                $g['audio'] = self::speakable($g['example_zh'], 'n');
            }
        }
        unset($g);
        return $r;
    }

    /** Tạo clip (nếu chưa có) cho một đoạn đã ký, trả URL. */
    public static function audio(string $text, string $rate, string $sig): string
    {
        if (!isset(self::RATES[$rate]) || $text === '' || !hash_equals(self::sign($text, $rate), $sig)) {
            throw new \InvalidArgumentException('Đoạn audio không hợp lệ.');
        }
        $clip = AudioService::clipName($text, AudioService::VOICE['zh_f'], self::RATES[$rate]);
        $path = Paths::base() . $clip;
        if (!is_file($path)) {
            $tts = new Tts();
            if ($err = $tts->ready()) {
                throw new \DomainException($err);
            }
            Paths::ensureDirs();
            $res = $tts->many([['text' => $text, 'voice' => AudioService::VOICE['zh_f'], 'rate' => self::RATES[$rate], 'path' => $path]]);
            if (!in_array($path, $res['ok'], true)) {
                throw new \DomainException('Không tạo được giọng đọc: ' . (reset($res['errors']) ?: 'lỗi không rõ'));
            }
        }
        return Paths::baseUrl() . $clip;
    }
}
