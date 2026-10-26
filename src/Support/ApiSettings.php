<?php

namespace HSKN\Support;

defined('ABSPATH') || exit;

/**
 * Key dịch vụ ngoài (option hskn_api, không bao giờ gửi xuống trình duyệt người học).
 *
 * tts = 'google' Google Cloud Text-to-Speech (API key; chạy trên hosting)
 *       'azure'  Azure Speech (key + region; chạy trên hosting)
 *       'local'  gọi Python + edge-tts trên CHÍNH máy chạy WordPress (chỉ cho site dev/Local)
 */
final class ApiSettings
{
    public const OPTION = 'hskn_api';

    /** Giọng Google mặc định (WaveNet: miễn phí 1 triệu ký tự/tháng). Khoá = giọng logic dùng trong plugin. */
    public const GOOGLE_VOICES = [
        'zh_f' => 'cmn-CN-Wavenet-A',
        'zh_m' => 'cmn-CN-Wavenet-B',
        'vi'   => 'vi-VN-Wavenet-A',
        'en'   => 'en-US-Wavenet-F',
    ];

    public static function defaults(): array
    {
        return [
            'deepseek_key'   => '',
            'deepseek_model' => 'deepseek-flash',
            'tts'            => 'google',
            'google_key'     => '',
            'google_voices'  => self::GOOGLE_VOICES,
            'azure_key'      => '',
            'azure_region'   => 'southeastasia',
            'python'         => 'python',
        ];
    }

    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);
        $all = array_merge(self::defaults(), is_array($saved) ? $saved : []);
        $all['google_voices'] = array_merge(self::GOOGLE_VOICES, (array) $all['google_voices']);
        return $all;
    }

    public static function sanitize($in): array
    {
        $in = is_array($in) ? $in : [];
        $old = self::all();
        $out = self::defaults();
        foreach (['deepseek_key', 'azure_key', 'google_key'] as $k) {
            // ô key để trống khi lưu = giữ key cũ (trang cài đặt không in key ra HTML)
            $v = trim((string) ($in[$k] ?? ''));
            $out[$k] = $v === '' ? $old[$k] : ($v === '-' ? '' : sanitize_text_field($v));
        }
        $out['deepseek_model'] = preg_replace('/[^a-z0-9._-]/i', '', (string) ($in['deepseek_model'] ?? '')) ?: 'deepseek-flash';
        $out['tts'] = in_array($in['tts'] ?? '', ['google', 'azure', 'local'], true) ? $in['tts'] : 'google';
        foreach (self::GOOGLE_VOICES as $k => $def) {
            $v = preg_replace('/[^A-Za-z0-9-]/', '', (string) ($in['google_voices'][$k] ?? ''));
            $out['google_voices'][$k] = $v ?: $def;
        }
        $out['azure_region'] = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($in['azure_region'] ?? ''))) ?: 'southeastasia';
        $out['python'] = sanitize_text_field((string) ($in['python'] ?? 'python')) ?: 'python';
        return $out;
    }

    public static function mask(string $key): string
    {
        return $key === '' ? '' : substr($key, 0, 5) . '…' . substr($key, -4);
    }
}
