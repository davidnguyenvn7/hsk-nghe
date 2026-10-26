<?php

namespace HSKN\Support;

defined('ABSPATH') || exit;

/**
 * Key dịch vụ ngoài (option hskn_api, không bao giờ gửi xuống trình duyệt người học).
 *
 * tts = 'azure'  Azure Speech (chính thức, chạy trên hosting; cần key + region)
 *       'local'  gọi Python + edge-tts trên CHÍNH máy chạy WordPress (chỉ cho site dev/Local)
 */
final class ApiSettings
{
    public const OPTION = 'hskn_api';

    public static function defaults(): array
    {
        return [
            'deepseek_key'   => '',
            'deepseek_model' => 'deepseek-flash',
            'tts'            => 'azure',
            'azure_key'      => '',
            'azure_region'   => 'southeastasia',
            'python'         => 'python',
        ];
    }

    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($saved) ? $saved : []);
    }

    public static function sanitize($in): array
    {
        $in = is_array($in) ? $in : [];
        $old = self::all();
        $out = self::defaults();
        foreach (['deepseek_key', 'azure_key'] as $k) {
            // ô key để trống khi lưu = giữ key cũ (trang cài đặt không in key ra HTML)
            $v = trim((string) ($in[$k] ?? ''));
            $out[$k] = $v === '' ? $old[$k] : ($v === '-' ? '' : sanitize_text_field($v));
        }
        $out['deepseek_model'] = preg_replace('/[^a-z0-9._-]/i', '', (string) ($in['deepseek_model'] ?? '')) ?: 'deepseek-flash';
        $out['tts'] = in_array($in['tts'] ?? '', ['azure', 'local'], true) ? $in['tts'] : 'azure';
        $out['azure_region'] = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($in['azure_region'] ?? ''))) ?: 'southeastasia';
        $out['python'] = sanitize_text_field((string) ($in['python'] ?? 'python')) ?: 'python';
        return $out;
    }

    public static function mask(string $key): string
    {
        return $key === '' ? '' : substr($key, 0, 5) . '…' . substr($key, -4);
    }
}
