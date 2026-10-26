<?php

namespace HSKN\Rest;

use HSKN\Author\AudioService;
use HSKN\Author\AuthorService;
use HSKN\Author\Generator;
use HSKN\Author\Tts;
use HSKN\Repository\TopicRepo;
use HSKN\Support\ApiSettings;

defined('ABSPATH') || exit;

/**
 * Trang "Thêm nội dung": đề xuất từ → sinh câu (theo lô) → lưu → tạo audio (theo lô).
 * Mỗi request làm một phần nhỏ để không vượt max_execution_time.
 */
final class AuthorController
{
    public function register(): void
    {
        $perm = static fn() => current_user_can('manage_options');
        $route = static function (string $path, string $method, callable $cb) use ($perm): void {
            register_rest_route('hskn/v1', '/admin/author' . $path, ['methods' => $method, 'callback' => $cb, 'permission_callback' => $perm]);
        };
        $route('', 'GET', [$this, 'state']);
        $route('/words', 'POST', [$this, 'words']);
        $route('/sentences', 'POST', [$this, 'sentences']);
        $route('/save', 'POST', [$this, 'save']);
        $route('/audio', 'POST', [$this, 'audio']);
        $route('/topics/(?P<id>\d{2})', 'DELETE', [$this, 'deleteTopic']);
    }

    private static function wrap(callable $fn)
    {
        try {
            @set_time_limit(120);
            return new \WP_REST_Response($fn());
        } catch (\Throwable $e) {
            return new \WP_Error('hskn_author', $e->getMessage(), ['status' => 400]);
        }
    }

    public function state()
    {
        return self::wrap(static function () {
            $api = ApiSettings::all();
            return [
                'topics'        => array_map(static fn($t) => ['id' => $t['id'], 'name_vi' => $t['name_vi'], 'name_zh' => $t['name_zh'],
                                                                'desc' => $t['description'], 'source' => $t['source']], (new TopicRepo())->all()),
                'wp_topics'     => (new AuthorService())->wpTopics(),
                'pending_audio' => count((new AudioService())->pending()),
                'deepseek'      => $api['deepseek_key'] !== '',
                'tts'           => $api['tts'],
                'tts_error'     => (new Tts())->ready(),
            ];
        });
    }

    /** body: topic_id | {name_vi, desc}, count, list[] */
    public function words(\WP_REST_Request $req)
    {
        return self::wrap(static function () use ($req) {
            $topicId = (string) $req['topic_id'];
            $exclude = [];
            if ($topicId !== '') {
                $t = (new TopicRepo())->find($topicId);
                if (!$t) {
                    throw new \RuntimeException('Chủ đề không tồn tại.');
                }
                $topic = ['name_vi' => $t['name_vi'], 'name_zh' => $t['name_zh'], 'desc' => $t['description']];
                $exclude = array_column((new AuthorService())->topicContext($topicId)['words'], 'hanzi');
            } else {
                $topic = ['name_vi' => sanitize_text_field((string) $req['name_vi']), 'desc' => sanitize_text_field((string) $req['desc'])];
                if ($topic['name_vi'] === '') {
                    throw new \RuntimeException('Nhập tên chủ đề.');
                }
            }
            $list = array_values(array_filter(array_map('trim', preg_split('/[,，、\s]+/u', (string) $req['list']) ?: [])));
            $count = max(1, min(50, (int) ($req['count'] ?: 10)));
            $gen = new Generator();
            $res = $gen->proposeWords($topic, $count, $list, $exclude);
            $res['topic'] = $topic + ['id' => $topicId ?: null];
            if ($topicId === '') {
                $res['topic']['name_zh'] = $res['name_zh'];
                $res['topic']['desc'] = $topic['desc'] ?: $res['desc'];
            }
            $res['tokens'] = $gen->tokens();
            return $res;
        });
    }

    /** body: topic {id?, name_vi, name_zh, desc}, words [{hanzi, vi, id?}] (≤ 8), per_word, max_len */
    public function sentences(\WP_REST_Request $req)
    {
        return self::wrap(static function () use ($req) {
            $topic = (array) $req['topic'];
            $words = array_slice(array_values(array_filter((array) $req['words'], 'is_array')), 0, 8);
            $have = !empty($topic['id']) ? (new AuthorService())->topicContext((string) $topic['id'])['sentences'] : [];
            $words = array_map(static fn($w) => [
                'hanzi' => (string) ($w['hanzi'] ?? ''), 'vi' => (string) ($w['vi'] ?? ''),
                'have'  => $have[$w['id'] ?? ''] ?? [],
            ], $words);
            $gen = new Generator();
            $items = $gen->sentences($topic, $words, max(1, min(5, (int) ($req['per_word'] ?: 2))), max(8, min(30, (int) ($req['max_len'] ?: 18))));
            return ['items' => $items, 'tokens' => $gen->tokens()];
        });
    }

    public function save(\WP_REST_Request $req)
    {
        return self::wrap(static fn() => (new AuthorService())->save(['topic' => (array) $req['topic'], 'words' => (array) $req['words']]));
    }

    public function audio()
    {
        return self::wrap(static function () {
            $err = (new Tts())->ready();
            if ($err) {
                throw new \RuntimeException($err);
            }
            return (new AudioService())->step(40);
        });
    }

    public function deleteTopic(\WP_REST_Request $req)
    {
        return self::wrap(static fn() => ['ok' => (new AuthorService())->deactivateTopic((string) $req['id'])]);
    }
}
