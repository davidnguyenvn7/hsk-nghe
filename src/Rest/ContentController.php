<?php

namespace HSKN\Rest;

use HSKN\Author\AudioService;
use HSKN\Author\ContentService;
use HSKN\Support\Ids;

defined('ABSPATH') || exit;

/** REST cho trang "Quản lý nội dung" (chỉ admin). */
final class ContentController
{
    public function register(): void
    {
        $perm = static fn() => current_user_can('manage_options');
        $r = static function (string $path, string $method, callable $cb) use ($perm): void {
            register_rest_route('hskn/v1', '/admin/content' . $path, ['methods' => $method, 'callback' => $cb, 'permission_callback' => $perm]);
        };
        $r('/topics', 'GET', fn() => $this->wrap(fn() => (new ContentService())->topics()));
        $r('/topics/(?P<id>\d{2})', 'GET', fn($q) => $this->wrap(fn() => (new ContentService())->topic($q['id'])));
        $r('/topics/(?P<id>\d{2})', 'PATCH', fn($q) => $this->wrap(fn() => (new ContentService())->updateTopic($q['id'], $q->get_json_params())));
        $r('/topics/(?P<id>\d{2})/words/(?P<wid>[HT][0-9-]{5,6})', 'DELETE', fn($q) => $this->wrap(fn() => (new ContentService())->removeWord($q['id'], $q['wid'])));
        $r('/words', 'GET', fn($q) => $this->wrap(fn() => (new ContentService())->words(
            in_array((int) $q['level'], [2, 3], true) ? (int) $q['level'] : 2, mb_substr(trim((string) $q['q']), 0, 50), max(1, (int) $q['page'])
        )));
        $r('/words/(?P<id>[HT][0-9-]{5,6})', 'PATCH', fn($q) => $this->wrap(fn() => $this->word($q, fn($id) => (new ContentService())->updateWord($id, $q->get_json_params(), (bool) $q['force']))));
        $r('/words/(?P<id>[HT][0-9-]{5,6})/example', 'POST', fn($q) => $this->wrap(fn() => $this->word($q, fn($id) => (new ContentService())->suggestExample($id))));
        $r('/words/(?P<id>[HT][0-9-]{5,6})/example', 'PUT', fn($q) => $this->wrap(fn() => $this->word($q, fn($id) => (new ContentService())->setExample($id, $q->get_json_params()))));
        $r('/sentences/(?P<id>[0-9A-Z-]{6,12})', 'PATCH', fn($q) => $this->wrap(function () use ($q) {
            if (!Ids::isSentence($q['id'])) {
                throw new \RuntimeException('ID câu không hợp lệ.');
            }
            return (new ContentService())->updateSentence($q['id'], $q->get_json_params(), (bool) $q['force']);
        }));
        $r('/audio', 'GET', fn() => $this->wrap(fn() => ['pending' => count((new AudioService())->pending())]));
    }

    private function word(\WP_REST_Request $q, callable $fn)
    {
        if (!Ids::isWord((string) $q['id'])) {
            throw new \RuntimeException('ID từ không hợp lệ.');
        }
        return $fn((string) $q['id']);
    }

    private function wrap(callable $fn)
    {
        try {
            @set_time_limit(120);
            return new \WP_REST_Response($fn());
        } catch (\Throwable $e) {
            return new \WP_Error('hskn_content', $e->getMessage(), ['status' => 400]);
        }
    }
}
