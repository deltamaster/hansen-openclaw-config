<?php
declare(strict_types=1);
// Run: docker cp scripts/freshrss-list-sqlite-tables.php freshrss-local:/tmp/f.php && docker exec freshrss-local php /tmp/f.php

$base = '/var/www/FreshRSS/data/users';
$glob = glob($base . '/*/db.sqlite') ?: [];
if ($glob === []) {
	fwrite(STDERR, "no db.sqlite under {$base}\n");
	exit(1);
}
$db = $glob[0];
echo "DB: {$db}\n";
$pdo = new PDO('sqlite:' . $db);
foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name") as $row) {
	echo $row['name'], "\n";
}
