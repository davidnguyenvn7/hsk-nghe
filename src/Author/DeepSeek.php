<?php

namespace HSKN\Author;

use HSKN\Support\ApiSettings;

defined('ABSPATH') || exit;

/**
 * DeepSeek chat completions (OpenAI-compatible), chế độ JSON, tắt thinking cho nhanh.
 */
final class DeepSeek
{
    public const SYSTEM = 'You are an experienced Mandarin teacher writing listening material for Vietnamese adults at HSK 2–3 level '
        . 'who work in real estate, offices and city life. You write natural, everyday spoken Mandarin '
        . '(simplified characters, mainland usage). Always answer with one JSON object only.';

    private string $key;
    private string $model;
    public int $tokens = 0;

    public function __construct()
    {
        $api = ApiSettings::all();
        $this->key = $api['deepseek_key'];
        $this->model = $api['deepseek_model'];
        if ($this->key === '') {
            throw new \RuntimeException('Chưa nhập DeepSeek API key (HSK Nghe → Cài đặt).');
        }
    }

    public function json(string $user, float $temperature = 0.8): array
    {
        $body = wp_json_encode([
            'model'           => $this->model,
            'temperature'     => $temperature,
            'messages'        => [['role' => 'system', 'content' => self::SYSTEM], ['role' => 'user', 'content' => $user]],
            'response_format' => ['type' => 'json_object'],
            'thinking'        => ['type' => 'disabled'],
        ]);
        $last = '';
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $res = wp_remote_post('https://api.deepseek.com/chat/completions', [
                'timeout' => 90,
                'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->key],
                'body'    => $body,
            ]);
            if (is_wp_error($res)) {
                $last = $res->get_error_message();
                sleep(2);
                continue;
            }
            $code = wp_remote_retrieve_response_code($res);
            $data = json_decode(wp_remote_retrieve_body($res), true);
            if (in_array($code, [429, 500, 502, 503, 504], true)) {
                $last = "HTTP $code";
                sleep(3 * ($attempt + 1));
                continue;
            }
            if ($code !== 200) {
                throw new \RuntimeException('DeepSeek lỗi HTTP ' . $code . ': ' . ($data['error']['message'] ?? substr(wp_remote_retrieve_body($res), 0, 200)));
            }
            $this->tokens += (int) ($data['usage']['total_tokens'] ?? 0);
            $text = (string) ($data['choices'][0]['message']['content'] ?? '');
            if (preg_match('/\{.*\}/s', $text, $m)) {
                $json = json_decode($m[0], true);
                if (is_array($json)) {
                    return $json;
                }
            }
            $last = 'JSON hỏng';
        }
        throw new \RuntimeException('Không gọi được DeepSeek: ' . $last);
    }
}
