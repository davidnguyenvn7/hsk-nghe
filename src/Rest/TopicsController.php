<?php

namespace HSKN\Rest;

use HSKN\Repository\SentenceRepo;
use HSKN\Repository\TopicRepo;
use HSKN\Repository\WordRepo;
use HSKN\Support\Cache;
use HSKN\Support\Ids;

defined('ABSPATH') || exit;

final class TopicsController
{
    public function register(): void
    {
        register_rest_route('hskn/v1', '/topics', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('hskn/v1', '/topics/(?P<id>\d{2})', [
            'methods'             => 'GET',
            'callback'            => [$this, 'show'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function index(): \WP_REST_Response
    {
        $data = Cache::remember('topics', HOUR_IN_SECONDS, static function () {
            $words = (new TopicRepo())->wordIds();
            return array_map(static fn($t) => $t + ['word_count' => count($words[$t['id']] ?? [])], (new TopicRepo())->all());
        });
        return new \WP_REST_Response($data);
    }

    public function show(\WP_REST_Request $req)
    {
        $id = (string) $req['id'];
        $topic = Ids::isTopic($id) ? (new TopicRepo())->find($id) : null;
        if (!$topic) {
            return new \WP_Error('hskn_not_found', 'Không có chủ đề này.', ['status' => 404]);
        }
        $sentences = (new SentenceRepo())->byTopic($id);
        // gom lượt hội thoại liền nhau thành một hội thoại
        $items = [];
        foreach ($sentences as $s) {
            if ($s['type'] === 'D') {
                $last = count($items) - 1;
                if ($last >= 0 && ($items[$last]['type'] ?? '') === 'dialogue' && $items[$last]['id'] === $s['dialogue']) {
                    $items[$last]['turns'][] = $s;
                    continue;
                }
                $items[] = ['type' => 'dialogue', 'id' => $s['dialogue'], 'turns' => [$s]];
                continue;
            }
            $items[] = ['type' => 'sentence'] + $s;
        }
        $wordIds = (new TopicRepo())->wordIds($id);
        $words = array_values((new WordRepo())->findMany($wordIds));
        $wordSentences = (new SentenceRepo())->byWords($id, $wordIds);
        foreach ($words as &$w) {
            $w['sentences'] = $wordSentences[$w['id']] ?? [];
        }
        return new \WP_REST_Response($topic + ['items' => $items, 'words' => $words]);
    }
}
