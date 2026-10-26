<?php

namespace HSKN\Author;

use HSKN\Support\ApiSettings;

defined('ABSPATH') || exit;

/**
 * Tạo clip MP3 (24 kHz mono 48 kbps — cùng định dạng edge-tts để AudioRenderer ghép được).
 */
final class Tts
{
    private array $api;

    public function __construct()
    {
        $this->api = ApiSettings::all();
    }

    public function provider(): string
    {
        return $this->api['tts'];
    }

    public function ready(): ?string
    {
        if ($this->api['tts'] === 'azure' && ($this->api['azure_key'] === '' || $this->api['azure_region'] === '')) {
            return 'Chưa nhập Azure Speech key / region (HSK Nghe → Cài đặt).';
        }
        if ($this->api['tts'] === 'local' && !function_exists('proc_open')) {
            return 'PHP trên server không cho chạy proc_open, không dùng được chế độ Python cục bộ.';
        }
        return null;
    }

    /**
     * @param array $jobs [['text','voice','rate','path']]
     * @return array{ok: string[], errors: array<string,string>}
     */
    public function many(array $jobs): array
    {
        if (!$jobs) {
            return ['ok' => [], 'errors' => []];
        }
        return $this->api['tts'] === 'local' ? $this->local($jobs) : $this->azure($jobs);
    }

    private function azure(array $jobs): array
    {
        $out = ['ok' => [], 'errors' => []];
        $url = "https://{$this->api['azure_region']}.tts.speech.microsoft.com/cognitiveservices/v1";
        foreach ($jobs as $j) {
            $lang = substr($j['voice'], 0, 5);
            $ssml = "<speak version='1.0' xmlns='http://www.w3.org/2001/10/synthesis' xml:lang='{$lang}'>"
                . "<voice name='" . esc_attr($j['voice']) . "'><prosody rate='" . esc_attr($j['rate']) . "'>"
                . htmlspecialchars($j['text'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</prosody></voice></speak>';
            $res = wp_remote_post($url, [
                'timeout' => 30,
                'headers' => [
                    'Ocp-Apim-Subscription-Key' => $this->api['azure_key'],
                    'Content-Type'              => 'application/ssml+xml',
                    'X-Microsoft-OutputFormat'  => 'audio-24khz-48kbitrate-mono-mp3',
                    'User-Agent'                => 'hsk-nghe',
                ],
                'body'    => $ssml,
            ]);
            $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
            $body = is_wp_error($res) ? '' : wp_remote_retrieve_body($res);
            if ($code === 200 && strlen($body) > 100 && file_put_contents($j['path'], $body)) {
                $out['ok'][] = $j['path'];
            } else {
                $out['errors'][$j['path']] = is_wp_error($res) ? $res->get_error_message() : "Azure HTTP $code";
                if (in_array($code, [401, 403], true)) {
                    break;   // sai key: dừng luôn, không gọi tiếp
                }
            }
        }
        return $out;
    }

    private function local(array $jobs): array
    {
        $cmd = escapeshellcmd($this->api['python']) . ' ' . escapeshellarg(HSKN_DIR . 'bin/tts_local.py');
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PYTHONIOENCODING' => 'utf-8'] + getenv());
        if (!is_resource($proc)) {
            throw new \RuntimeException('Không chạy được Python: ' . $this->api['python']);
        }
        fwrite($pipes[0], wp_json_encode($jobs));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $data = json_decode((string) $stdout, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Python TTS lỗi: ' . trim(substr((string) $stderr, -400)));
        }
        return ['ok' => (array) ($data['ok'] ?? []), 'errors' => (array) ($data['errors'] ?? [])];
    }
}
