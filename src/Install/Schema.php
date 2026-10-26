<?php

namespace HSKN\Install;

use HSKN\Support\Paths;

defined('ABSPATH') || exit;

/**
 * Cột source: 'pack' = nội dung từ gói import (repo), 'wp' = tạo trong WP Admin (trang Thêm nội dung).
 * Import chỉ ghi đè / ngừng dùng dòng 'pack', không bao giờ đụng tới dòng 'wp'.
 *
 * Bảng riêng, tạo bằng dbDelta. Đổi cấu trúc thì tăng DB_VERSION; migrate() chạy lại dbDelta khi số phiên bản khác.
 */
final class Schema
{
    public const DB_VERSION = '2';

    public static function activate(): void
    {
        self::migrate();
        Paths::ensureDirs();
    }

    public static function maybeMigrate(): void
    {
        if (get_option('hskn_db_version') !== self::DB_VERSION) {
            self::migrate();
        }
    }

    public static function tables(): array
    {
        global $wpdb;
        $p = $wpdb->prefix . 'hskn_';
        return [
            'packs'         => $p . 'packs',
            'topics'        => $p . 'topics',
            'words'         => $p . 'words',
            'topic_words'   => $p . 'topic_words',
            'sentences'     => $p . 'sentences',
            'word_sentence' => $p . 'word_sentence',
            'audio'         => $p . 'audio',
            'progress'      => $p . 'progress',
            'listen_log'    => $p . 'listen_log',
            'plan'          => $p . 'plan',
        ];
    }

    public static function migrate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::tables();
        $c = $wpdb->get_charset_collate();

        // ---- nội dung (ghi đè khi import)
        dbDelta("CREATE TABLE {$t['packs']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version VARCHAR(32) NOT NULL,
  imported_at DATETIME NOT NULL,
  counts LONGTEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY version (version)
) $c;");

        dbDelta("CREATE TABLE {$t['topics']} (
  id CHAR(2) NOT NULL,
  slug VARCHAR(64) NOT NULL DEFAULT '',
  name_vi VARCHAR(191) NOT NULL DEFAULT '',
  name_zh VARCHAR(64) NOT NULL DEFAULT '',
  description TEXT NULL,
  sort INT NOT NULL DEFAULT 0,
  stats LONGTEXT NULL,
  content_hash CHAR(16) NOT NULL DEFAULT '',
  source VARCHAR(8) NOT NULL DEFAULT 'pack',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id)
) $c;");

        dbDelta("CREATE TABLE {$t['words']} (
  id VARCHAR(12) NOT NULL,
  hanzi VARCHAR(32) NOT NULL,
  pinyin VARCHAR(128) NOT NULL DEFAULT '',
  level TINYINT NOT NULL DEFAULT 0,
  pos VARCHAR(128) NOT NULL DEFAULT '',
  vi VARCHAR(255) NOT NULL DEFAULT '',
  en VARCHAR(255) NOT NULL DEFAULT '',
  freq_rank INT NULL,
  example_zh VARCHAR(255) NOT NULL DEFAULT '',
  example_pinyin VARCHAR(512) NOT NULL DEFAULT '',
  example_vi VARCHAR(255) NOT NULL DEFAULT '',
  example_en VARCHAR(255) NOT NULL DEFAULT '',
  content_hash CHAR(16) NOT NULL DEFAULT '',
  source VARCHAR(8) NOT NULL DEFAULT 'pack',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  KEY level (level),
  KEY hanzi (hanzi)
) $c;");

        dbDelta("CREATE TABLE {$t['topic_words']} (
  topic_id CHAR(2) NOT NULL,
  word_id VARCHAR(12) NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  source VARCHAR(8) NOT NULL DEFAULT 'pack',
  PRIMARY KEY  (topic_id,word_id),
  KEY topic_sort (topic_id,sort)
) $c;");

        dbDelta("CREATE TABLE {$t['sentences']} (
  id VARCHAR(12) NOT NULL,
  topic_id CHAR(2) NOT NULL,
  type CHAR(1) NOT NULL DEFAULT 'S',
  dialogue_id VARCHAR(10) NULL,
  speaker CHAR(1) NULL,
  word_id VARCHAR(12) NULL,
  zh VARCHAR(255) NOT NULL,
  pinyin VARCHAR(512) NOT NULL DEFAULT '',
  vi VARCHAR(255) NOT NULL DEFAULT '',
  en VARCHAR(255) NOT NULL DEFAULT '',
  grammar VARCHAR(191) NOT NULL DEFAULT '',
  max_level TINYINT NOT NULL DEFAULT 0,
  tokens LONGTEXT NULL,
  sort INT NOT NULL DEFAULT 0,
  content_hash CHAR(16) NOT NULL DEFAULT '',
  source VARCHAR(8) NOT NULL DEFAULT 'pack',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  KEY topic_type_sort (topic_id,type,sort),
  KEY dialogue_id (dialogue_id),
  KEY word_id (word_id)
) $c;");

        dbDelta("CREATE TABLE {$t['word_sentence']} (
  word_id VARCHAR(12) NOT NULL,
  sentence_id VARCHAR(12) NOT NULL,
  source VARCHAR(8) NOT NULL DEFAULT 'pack',
  PRIMARY KEY  (word_id,sentence_id),
  KEY sentence_id (sentence_id)
) $c;");

        dbDelta("CREATE TABLE {$t['audio']} (
  item_type VARCHAR(8) NOT NULL,
  item_id VARCHAR(12) NOT NULL,
  kind VARCHAR(16) NOT NULL,
  file VARCHAR(64) NOT NULL,
  duration_ms INT NULL,
  source VARCHAR(8) NOT NULL DEFAULT 'pack',
  PRIMARY KEY  (item_type,item_id,kind)
) $c;");

        // ---- người học (không bao giờ ghi đè khi import)
        dbDelta("CREATE TABLE {$t['progress']} (
  user_id BIGINT UNSIGNED NOT NULL,
  item_type VARCHAR(8) NOT NULL,
  item_id VARCHAR(12) NOT NULL,
  box TINYINT NOT NULL DEFAULT 0,
  listen_count INT NOT NULL DEFAULT 0,
  last_listened_at DATETIME NULL,
  due_at DATETIME NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'new',
  PRIMARY KEY  (user_id,item_type,item_id),
  KEY user_due (user_id,due_at)
) $c;");

        dbDelta("CREATE TABLE {$t['listen_log']} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  track_id VARCHAR(24) NOT NULL,
  session_id CHAR(36) NOT NULL,
  seconds INT NOT NULL DEFAULT 0,
  items_heard INT NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY session_id (session_id),
  KEY user_started (user_id,started_at)
) $c;");

        dbDelta("CREATE TABLE {$t['plan']} (
  user_id BIGINT UNSIGNED NOT NULL,
  plan_key VARCHAR(32) NOT NULL,
  start_date DATE NOT NULL,
  settings LONGTEXT NULL,
  PRIMARY KEY  (user_id)
) $c;");

        update_option('hskn_db_version', self::DB_VERSION, false);
    }

    public static function drop(): void
    {
        global $wpdb;
        foreach (self::tables() as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL
        }
        delete_option('hskn_db_version');
    }
}
