<?php

namespace HSKN;

use HSKN\Admin\AdminPages;
use HSKN\Install\Schema;
use HSKN\Rest\AdminController;
use HSKN\Rest\AuthorController;
use HSKN\Rest\ContentController;
use HSKN\Rest\TopicsController;
use HSKN\Rest\TracksController;
use HSKN\Rest\WordsController;
use HSKN\Shortcode\Player;
use HSKN\Support\Cache;
use HSKN\Support\Settings;
use HSKN\Support\Updater;

defined('ABSPATH') || exit;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        Schema::maybeMigrate();

        add_action('rest_api_init', static function (): void {
            (new TopicsController())->register();
            (new WordsController())->register();
            (new TracksController())->register();
            (new AdminController())->register();
            (new AuthorController())->register();
            (new ContentController())->register();
        });

        (new Player())->register();
        (new Updater())->register();   // chạy cả trong cron (WP kiểm tra cập nhật nền), không chỉ trong admin

        if (is_admin()) {
            (new AdminPages())->register();
        }

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('hskn', Cli\HsknCommand::class);
        }

        // dọn bài nghe đã ghép lâu không ai nghe
        add_action('hskn_cleanup', static function (): void {
            Cache::clearRendered((int) Settings::get('cache_days'));
        });
        if (!wp_next_scheduled('hskn_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'hskn_cleanup');
        }
    }
}
