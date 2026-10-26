<?php

namespace HSKN\Support;

defined('ABSPATH') || exit;

/**
 * Cài đặt chung (option hskn_settings). Phần "nghe" có thể được người học ghi đè từng lần phát
 * nếu admin bật allow_user_override; mọi giá trị đều đi qua sanitize() nên số tổ hợp cache có giới hạn.
 */
final class Settings
{
    public const OPTION = 'hskn_settings';

    /** Các khoá thuộc về cách ghép bài nghe (ảnh hưởng tới file audio). */
    public const LISTEN_KEYS = ['word_repeat', 'meaning_lang', 'sentence_repeat', 'sentence_meaning', 'slow_repeat', 'gap_ms', 'end_gap_ms'];

    public static function defaults(): array
    {
        return [
            'word_repeat'         => 2,
            'meaning_lang'        => 'vi',
            'sentence_repeat'     => 2,
            'sentence_meaning'    => true,
            'slow_repeat'         => true,
            'gap_ms'              => 1000,
            'end_gap_ms'          => 1800,
            'chunk_size'          => 30,
            'auto_next'           => true,
            'allow_user_override' => true,
            'cache_days'          => 30,
            'delete_on_uninstall' => false,
        ];
    }

    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);
        return self::sanitize(is_array($saved) ? $saved : []);
    }

    public static function get(string $key)
    {
        return self::all()[$key] ?? null;
    }

    public static function sanitize(array $in, ?array $base = null): array
    {
        $base ??= self::defaults();
        $out = $base;
        $int = static fn($v, int $min, int $max, int $step = 1) => max($min, min($max, (int) round(((int) $v) / $step) * $step));
        $bool = static fn($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN);

        if (isset($in['word_repeat'])) $out['word_repeat'] = $int($in['word_repeat'], 1, 5);
        if (isset($in['sentence_repeat'])) $out['sentence_repeat'] = $int($in['sentence_repeat'], 0, 5);
        if (isset($in['meaning_lang']) && in_array($in['meaning_lang'], ['vi', 'en', 'none'], true)) $out['meaning_lang'] = $in['meaning_lang'];
        if (isset($in['gap_ms'])) $out['gap_ms'] = $int($in['gap_ms'], 300, 5000, 100);
        if (isset($in['end_gap_ms'])) $out['end_gap_ms'] = $int($in['end_gap_ms'], 300, 8000, 100);
        if (isset($in['chunk_size'])) $out['chunk_size'] = $int($in['chunk_size'], 10, 100, 5);
        if (isset($in['cache_days'])) $out['cache_days'] = $int($in['cache_days'], 1, 365);
        foreach (['sentence_meaning', 'slow_repeat', 'auto_next', 'allow_user_override', 'delete_on_uninstall'] as $k) {
            if (array_key_exists($k, $in)) $out[$k] = $bool($in[$k]);
        }
        return $out;
    }

    /** Cài đặt nghe thực tế cho một request: mặc định của admin + ghi đè của người học (nếu được phép). */
    public static function forRequest(array $params): array
    {
        $all = self::all();
        $listen = array_intersect_key($all, array_flip(self::LISTEN_KEYS));
        if (!$all['allow_user_override']) {
            return $listen;
        }
        $override = array_intersect_key($params, array_flip(self::LISTEN_KEYS));
        return array_intersect_key(self::sanitize($override, $all), $listen);
    }
}
