<?php

namespace HSKN\Rest;

use HSKN\Audio\AudioRenderer;
use HSKN\Service\PlaylistService;
use HSKN\Service\TrackService;
use HSKN\Support\Settings;

defined('ABSPATH') || exit;

final class TracksController
{
    public function register(): void
    {
        register_rest_route('hskn/v1', '/tracks', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('hskn/v1', '/tracks/(?P<id>[a-z0-9-]{6,24})/playlist', [
            'methods'             => 'GET',
            'callback'            => [$this, 'playlist'],
            'permission_callback' => '__return_true',
            'args'                => [
                'word_repeat'      => ['type' => 'integer'],
                'sentence_repeat'  => ['type' => 'integer'],
                'meaning_lang'     => ['type' => 'string', 'enum' => ['vi', 'en', 'none']],
                'sentence_meaning' => ['type' => 'boolean'],
                'slow_repeat'      => ['type' => 'boolean'],
                'gap_ms'           => ['type' => 'integer'],
                'end_gap_ms'       => ['type' => 'integer'],
            ],
        ]);
    }

    public function index(): \WP_REST_Response
    {
        return new \WP_REST_Response((new TrackService())->all());
    }

    /**
     * Playlist đã dựng sẵn: một file MP3 (đã ghép theo cài đặt) + units để hiển thị + cue thời gian.
     */
    public function playlist(\WP_REST_Request $req)
    {
        $tracks = new TrackService();
        $track = $tracks->find((string) $req['id']);
        if (!$track) {
            return new \WP_Error('hskn_not_found', 'Không có bài nghe này.', ['status' => 404]);
        }
        $settings = Settings::forRequest($req->get_params());
        $built = (new PlaylistService($tracks))->build($track['id'], $settings);
        if (!$built || !$built['steps']) {
            return new \WP_Error('hskn_no_audio', 'Bài này chưa có audio. Hãy chạy make_audio.py rồi import lại.', ['status' => 404]);
        }
        try {
            $audio = (new AudioRenderer())->render($track['id'], $built['steps']);
        } catch (\RuntimeException $e) {
            return new \WP_Error('hskn_render', $e->getMessage(), ['status' => 500]);
        }
        $res = new \WP_REST_Response([
            'track'    => $track,
            'settings' => $settings,
            'audio'    => ['src' => $audio['src'], 'duration_ms' => $audio['duration_ms'], 'bytes' => $audio['bytes']],
            'units'    => $built['units'],
            'cues'     => $audio['cues'],
        ]);
        $res->header('Cache-Control', 'no-store');
        return $res;
    }
}
