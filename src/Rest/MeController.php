<?php

namespace HSKN\Rest;

use HSKN\Audio\AudioRenderer;
use HSKN\Notebook\NotebookService;
use HSKN\Service\PlaylistService;
use HSKN\Support\Settings;

defined('ABSPATH') || exit;

/**
 * Sổ tay của người đăng nhập (cookie + nonce):
 *   GET    me/lists                     các bài + khoá các mục đã lưu
 *   POST   me/lists {name}              tạo bài
 *   POST   me/lists/{id} {name}         đổi tên
 *   DELETE me/lists/{id}                xoá bài (cùng các mục trong bài)
 *   GET    me/lists/{id}/items          các mục
 *   GET    me/lists/{id}/playlist       bài nghe đã ghép (giống tracks/{id}/playlist)
 *   POST   me/items {kind, zh, …}       lưu câu / từ
 *   DELETE me/items/{id}
 */
final class MeController
{
    public function register(): void
    {
        $route = function (string $path, string $method, string $cb): void {
            register_rest_route('hskn/v1', '/me' . $path, [
                'methods' => $method, 'callback' => [$this, $cb], 'permission_callback' => static fn() => is_user_logged_in(),
            ]);
        };
        $route('/lists', 'GET', 'lists');
        $route('/lists', 'POST', 'createList');
        $route('/lists/(?P<id>\d+)', 'POST', 'renameList');
        $route('/lists/(?P<id>\d+)', 'DELETE', 'deleteList');
        $route('/lists/(?P<id>\d+)/items', 'GET', 'items');
        $route('/lists/(?P<id>\d+)/playlist', 'GET', 'playlist');
        $route('/items', 'POST', 'save');
        $route('/items/(?P<id>\d+)', 'DELETE', 'delete');
    }

    private static function run(callable $fn)
    {
        try {
            @set_time_limit(120);
            $res = new \WP_REST_Response($fn(new NotebookService(get_current_user_id())));
            $res->header('Cache-Control', 'no-store');
            return $res;
        } catch (\Throwable $e) {
            return new \WP_Error('hskn_me', $e->getMessage(), ['status' => 400]);
        }
    }

    public function lists()
    {
        return self::run(static fn(NotebookService $n) => ['lists' => $n->lists(), 'keys' => $n->keys()]);
    }

    public function createList(\WP_REST_Request $req)
    {
        return self::run(static fn(NotebookService $n) => $n->createList((string) $req['name']));
    }

    public function renameList(\WP_REST_Request $req)
    {
        return self::run(static function (NotebookService $n) use ($req) {
            $n->renameList((int) $req['id'], (string) $req['name']);
            return ['ok' => true];
        });
    }

    public function deleteList(\WP_REST_Request $req)
    {
        return self::run(static function (NotebookService $n) use ($req) {
            $n->deleteList((int) $req['id']);
            return ['ok' => true];
        });
    }

    public function items(\WP_REST_Request $req)
    {
        return self::run(static fn(NotebookService $n) => ['list' => $n->find((int) $req['id']), 'items' => $n->items((int) $req['id'])]);
    }

    public function save(\WP_REST_Request $req)
    {
        return self::run(static fn(NotebookService $n) => $n->save($req->get_json_params() ?: $req->get_params()));
    }

    public function delete(\WP_REST_Request $req)
    {
        return self::run(static function (NotebookService $n) use ($req) {
            $n->delete((int) $req['id']);
            return ['ok' => true];
        });
    }

    public function playlist(\WP_REST_Request $req)
    {
        return self::run(static function (NotebookService $n) use ($req) {
            $content = $n->trackContent((int) $req['id']);
            if (!$content['items']) {
                throw new \RuntimeException('Bài này chưa có câu / từ nào. Vào "Tra câu" để thêm.');
            }
            $settings = Settings::forRequest($req->get_params());
            $built = (new PlaylistService())->buildMixed($content['items'], $settings);
            $audio = null;
            if ($built['steps'] && !$built['missing']) {
                try {
                    $audio = (new AudioRenderer())->render($content['track']['id'] . '-u' . get_current_user_id(), $built['steps']);
                } catch (\RuntimeException) {
                    $audio = null;
                }
            }
            return [
                'track'    => $content['track'],
                'settings' => $settings,
                'audio'    => $audio ? ['src' => $audio['src'], 'duration_ms' => $audio['duration_ms'], 'bytes' => $audio['bytes']] : null,
                'units'    => $built['units'],
                'cues'     => $audio['cues'] ?? [],
                'speech'   => $built['speech'],
                'missing'  => $built['missing'],
            ];
        });
    }
}
