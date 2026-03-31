<?php
declare(strict_types=1);
/**
 * Force-reload one feed (same core steps as UI "Reload" / feedController::reloadAction).
 *
 * Usage (inside container, paths as below):
 *   php /tmp/freshrss-reload-feed.php <feed_id> [username]
 *
 * From repo root (PowerShell):
 *   docker cp scripts/freshrss-reload-feed.php freshrss-local:/tmp/freshrss-reload-feed.php
 *   docker exec freshrss-local php /tmp/freshrss-reload-feed.php 49 admin
 */
require '/var/www/FreshRSS/cli/_cli.php';

performRequirementCheck(FreshRSS_Context::systemConf()->db['type'] ?? '');

$feedId = isset($argv[1]) ? (int) $argv[1] : 0;
$username = $argv[2] ?? 'admin';

if ($feedId <= 0) {
	fail("Usage: php freshrss-reload-feed.php <feed_id> [username]\n");
}

cliInitUser($username);

if (function_exists('set_time_limit')) {
	@set_time_limit(300);
}

$feedDAO = FreshRSS_Factory::createFeedDao();
$feed = $feedDAO->searchById($feedId);
if ($feed === null) {
	fail("FreshRSS error: feed id {$feedId} not found.\n");
}

fwrite(STDERR, 'FreshRSS reloading feed id=' . $feedId . ' (“' . $feed->name() . '”)…' . "\n");

// Re-fetch as if the feed was new (see feedController::reloadAction).
$feedDAO->updateFeed($feed->id(), ['lastUpdate' => 0]);
[$nbUpdatedFeeds, , $nbNewArticles] = FreshRSS_feed_Controller::actualizeFeedsAndCommit($feedId);

$feedDAO->updateCachedValues();

echo "FreshRSS reloaded feed {$feedId}: updatedFeeds={$nbUpdatedFeeds}, newArticles={$nbNewArticles}\n";

invalidateHttpCache($username);
done(true);
