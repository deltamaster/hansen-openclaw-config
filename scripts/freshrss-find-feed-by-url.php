<?php
declare(strict_types=1);
// Usage: php freshrss-find-feed-by-url.php [url]
// Docker: docker cp ... freshrss-local:/tmp/f.php && docker exec freshrss-local php /tmp/f.php

$needle = $argv[1] ?? 'https://news.google.com/rss?hl=en-US&gl=US&ceid=US:en';

$base = '/var/www/FreshRSS/data/users';
$glob = glob($base . '/*/db.sqlite') ?: [];
if ($glob === []) {
	fwrite(STDERR, "no db.sqlite under {$base}\n");
	exit(1);
}
$dbPath = $glob[0];
echo "DB: {$dbPath}\n";
echo "Looking for URL (exact or substring): {$needle}\n\n";

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// FreshRSS may store query strings with &amp; as in RSS XML
$needleAmp = str_replace('&', '&amp;', $needle);
$stmt = $pdo->prepare(
	'SELECT * FROM feed WHERE url = :u OR url = :ua OR url LIKE :like OR url LIKE :like2 OR url LIKE :likea'
);
$like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $needle) . '%';
$likea = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $needleAmp) . '%';
// Also try without scheme in case of legacy storage
$short = preg_replace('#^https?://#', '', $needle) ?? $needle;
$like2 = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $short) . '%';
$stmt->execute([':u' => $needle, ':ua' => $needleAmp, ':like' => $like, ':like2' => $like2, ':likea' => $likea]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($rows === []) {
	echo "No matching feed row.\n";
	echo "All feeds containing 'google' in url:\n";
	$all = $pdo->query(
		"SELECT id, name, url FROM feed WHERE url LIKE '%google%' OR url LIKE '%news.google%'"
	)->fetchAll(PDO::FETCH_ASSOC);
	foreach ($all as $r) {
		echo json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
	}
	exit(0);
}

foreach ($rows as $row) {
	echo json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}
