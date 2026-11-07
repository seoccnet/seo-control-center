<?php

declare(strict_types=1);

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migration;

/*
 * More Search Console and Bing data (0.14):
 *
 * - search_console_fresh_daily: web search totals of the days Google has not
 *   finalized yet (dataState "all"), replaced on every totals refresh;
 * - search_console_search_types: finalized daily totals of Discover, Google
 *   News, the News tab, image and video search;
 * - search_console_sitemaps: the property's sitemaps as Google processed them;
 * - bing_crawl_stats: Bing's daily crawl and index counts;
 * - bing_link_counts: inbound links per page of the site, as Bing counts them.
 */
return new class implements Migration {
    public function up(Connection $db): void
    {
        $db->statement('CREATE TABLE IF NOT EXISTS search_console_fresh_daily (
            project_id  BIGINT UNSIGNED NOT NULL,
            date        DATE            NOT NULL,
            clicks      INT UNSIGNED    NOT NULL,
            impressions INT UNSIGNED    NOT NULL,
            position    DECIMAL(8,2)    NOT NULL,
            complete    TINYINT(1)      NOT NULL,
            fetched_at  DATETIME        NOT NULL,
            PRIMARY KEY (project_id, date),
            CONSTRAINT sc_fresh_daily_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS search_console_search_types (
            project_id  BIGINT UNSIGNED NOT NULL,
            search_type VARCHAR(16)     NOT NULL,
            date        DATE            NOT NULL,
            clicks      INT UNSIGNED    NOT NULL,
            impressions INT UNSIGNED    NOT NULL,
            position    DECIMAL(8,2)    NOT NULL,
            PRIMARY KEY (project_id, search_type, date),
            CONSTRAINT sc_search_types_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS search_console_sitemaps (
            project_id      BIGINT UNSIGNED NOT NULL,
            url_hash        BINARY(32)      NOT NULL,
            url             VARCHAR(2048)   NOT NULL,
            is_index        TINYINT(1)      NOT NULL,
            is_pending      TINYINT(1)      NOT NULL,
            last_submitted  DATETIME        NULL,
            last_downloaded DATETIME        NULL,
            errors          INT UNSIGNED    NOT NULL,
            warnings        INT UNSIGNED    NOT NULL,
            submitted_urls  INT UNSIGNED    NOT NULL,
            refreshed_at    DATETIME        NOT NULL,
            PRIMARY KEY (project_id, url_hash),
            CONSTRAINT sc_sitemaps_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS bing_crawl_stats (
            project_id        BIGINT UNSIGNED NOT NULL,
            date              DATE            NOT NULL,
            crawled_pages     INT UNSIGNED    NOT NULL,
            crawl_errors      INT UNSIGNED    NOT NULL,
            in_index          INT UNSIGNED    NOT NULL,
            in_links          INT UNSIGNED    NOT NULL,
            code_2xx          INT UNSIGNED    NOT NULL,
            code_3xx          INT UNSIGNED    NOT NULL,
            code_4xx          INT UNSIGNED    NOT NULL,
            code_5xx          INT UNSIGNED    NOT NULL,
            blocked_by_robots INT UNSIGNED    NOT NULL,
            other_codes       INT UNSIGNED    NOT NULL,
            PRIMARY KEY (project_id, date),
            CONSTRAINT bing_crawl_stats_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $db->statement('CREATE TABLE IF NOT EXISTS bing_link_counts (
            project_id BIGINT UNSIGNED NOT NULL,
            url_hash   BINARY(32)      NOT NULL,
            url        VARCHAR(2048)   NOT NULL,
            links      INT UNSIGNED    NOT NULL,
            PRIMARY KEY (project_id, url_hash),
            KEY bing_link_counts_top (project_id, links),
            CONSTRAINT bing_link_counts_project_fk FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
};
