<?php

namespace HSKN\Rest;

use HSKN\Analyze\Analyzer;
use HSKN\Support\Settings;

defined('ABSPATH') || exit;

/**
 * POST hskn/v1/analyze        {text}             → phân tích câu (có cache)
 * POST hskn/v1/analyze/audio  {t, r, k}          → URL clip đọc một đoạn đã được server ký
 *
 * Ai được dùng: Cài đặt → "Phân tích câu" (mọi người / thành viên đăng nhập / chỉ admin).
 * Người không phải admin bị giới hạn số lần phân tích MỚI mỗi giờ (câu đã có trong cache không tính).
 */
final class AnalyzeController
{
    public static function allowed(): bool
    {
        return match (Settings::get('analyze_access')) {
            'off'   => false,
            'admin' => current_user_can('manage_options'),
            'login' => is_user_logged_in(),
            default => true,
        };
    }

    public function register(): void
    {
        $perm = static fn() => self::allowed();
        register_rest_route('hskn/v1', '/analyze', ['methods' => 'POST', 'callback' => [$this, 'analyze'], 'permission_callback' => $perm]);
        register_rest_route('hskn/v1', '/analyze/audio', ['methods' => 'POST', 'callback' => [$this, 'audio'], 'permission_callback' => $perm]);
    }

    public function analyze(\WP_REST_Request $req)
    {
        @set_time_limit(120);
        $text = Analyzer::normalize((string) $req['text']);
        $an = new Analyzer();
        try {
            // thử cache trước, chỉ tính lượt khi thật sự phải gọi DeepSeek
            $key = 'hskn_anl_' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . get_current_user_id());
            $limited = !current_user_can('manage_options');
            $res = $an->analyze($text, function () use ($key, $limited) {
                if (!$limited) {
                    return;
                }
                $n = (int) get_transient($key);
                $max = (int) Settings::get('analyze_limit');
                if ($n >= $max) {
                    throw new \LengthException("Bạn đã phân tích {$max} câu mới trong giờ này. Thử lại sau ít phút nhé.");
                }
                set_transient($key, $n + 1, HOUR_IN_SECONDS);
            });
        } catch (\LengthException $e) {
            return new \WP_Error('hskn_limit', $e->getMessage(), ['status' => 429]);
        } catch (\Throwable $e) {
            return new \WP_Error('hskn_analyze', $e->getMessage(), ['status' => 400]);
        }
        return new \WP_REST_Response(Analyzer::decorate($res['result']) + ['cached' => $res['cached'], 'tokens' => $an->tokens]);
    }

    public function audio(\WP_REST_Request $req)
    {
        try {
            return new \WP_REST_Response(['url' => Analyzer::audio((string) $req['t'], (string) $req['r'], (string) $req['k'])]);
        } catch (\DomainException $e) {
            // chưa cấu hình / lỗi dịch vụ giọng đọc → trình duyệt tự đọc bằng giọng của máy
            return new \WP_Error('hskn_tts_off', $e->getMessage(), ['status' => 503]);
        } catch (\Throwable $e) {
            return new \WP_Error('hskn_audio', $e->getMessage(), ['status' => 400]);
        }
    }
}
