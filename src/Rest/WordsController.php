<?php

namespace HSKN\Rest;

use HSKN\Repository\SentenceRepo;
use HSKN\Repository\WordRepo;
use HSKN\Support\Ids;

defined('ABSPATH') || exit;

final class WordsController
{
    public function register(): void
    {
        register_rest_route('hskn/v1', '/words', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => '__return_true',
            'args'                => [
                'level'    => ['type' => 'integer', 'enum' => [2, 3]],
                'topic'    => ['type' => 'string', 'pattern' => '^\d{2}$'],
                'q'        => ['type' => 'string', 'maxLength' => 50],
                'page'     => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200],
            ],
        ]);
        register_rest_route('hskn/v1', '/words/(?P<id>[HT][0-9-]{5,6})', [
            'methods'             => 'GET',
            'callback'            => [$this, 'show'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function index(\WP_REST_Request $req): \WP_REST_Response
    {
        $page = (int) $req['page'];
        $per = (int) $req['per_page'];
        [$items, $total] = (new WordRepo())->search([
            'level' => $req['level'],
            'topic' => $req['topic'],
            'q'     => trim((string) $req['q']),
        ], $page, $per);
        $res = new \WP_REST_Response(['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per]);
        $res->header('X-WP-Total', (string) $total);
        return $res;
    }

    public function show(\WP_REST_Request $req)
    {
        $id = (string) $req['id'];
        $word = Ids::isWord($id) ? (new WordRepo())->find($id) : null;
        if (!$word) {
            return new \WP_Error('hskn_not_found', 'Không có từ này.', ['status' => 404]);
        }
        $word['sentences'] = (new SentenceRepo())->containingWord($id);
        return new \WP_REST_Response($word);
    }
}
