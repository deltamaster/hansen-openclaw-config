<?php
declare(strict_types=1);
// Run in container: php /tmp/freshrss-feed-health-diag.php [feed_id]

$feedId = isset($argv[1]) ? (int) $argv[1] : 41;

$base = '/var/www/FreshRSS/data/users';
$glob = glob($base . '/*/db.sqlite') ?: [];
if ($glob === []) {
	fwrite(STDERR, "no db.sqlite under {$base}\n");
	exit(1);
}
$dbPath = $glob[0];
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "DB: {$dbPath}\n\n";

echo "=== PRAGMA table_info(feed) ===\n";
foreach ($pdo->query('PRAGMA table_info(feed)') as $row) {
	echo $row['name'] . ' (' . $row['type'] . ")\n";
}

echo "\n=== Feed id {$feedId} (full row) ===\n";
$stmt = $pdo->prepare('SELECT * FROM feed WHERE id = ?');
$stmt->execute([$feedId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo $row ? json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n" : "not found\n";

echo "\n=== All feeds (id, lastUpdate, error, cache_nbEntries, name snippet) ===\n";
foreach ($pdo->query('SELECT id, lastUpdate, error, cache_nbEntries, cache_nbUnreads, substr(name,1,40) AS n FROM feed ORDER BY id') as $r) {
	echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n=== entry count for feed {$feedId} ===\n";
$c = (int) $pdo->query("SELECT COUNT(*) FROM entry WHERE id_feed = {$feedId}")->fetchColumn();
echo "entry rows: {$c}\n";

echo "\n=== entrytmp count for feed {$feedId} (if column exists) ===\n";
try {
	$cols = $pdo->query("PRAGMA table_info(entrytmp)")->fetchAll(PDO::FETCH_COLUMN, 1);
	if (in_array('id_feed', $cols, true)) {
		$t = (int) $pdo->query("SELECT COUNT(*) FROM entrytmp WHERE id_feed = {$feedId}")->fetchColumn();
		echo "entrytmp rows: {$t}\n";
	} else {
		echo "entrytmp has no id_feed column; columns: " . implode(', ', $cols) . "\n";
	}
} catch (Throwable $e) {
	echo "entrytmp: " . $e->getMessage() . "\n";
}
