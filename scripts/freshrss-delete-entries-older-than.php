<?php
/**
 * Delete FreshRSS articles whose publication time `date` is older than N hours (SQLite user DB).
 *
 * Only rows with `date > 0` are considered (Unix seconds, as stored by FreshRSS). We do **not**
 * use `lastSeen` here: that is when the reader last refreshed the row, not pubdate. We also
 * avoid `COALESCE(date, lastSeen)`: in SQL, `COALESCE(0, lastSeen)` is `0`, so bogus `date=0`
 * would compare as epoch and could wipe the whole database against a normal cutoff.
 *
 * Updates per-feed cache counters after deletion.
 *
 * Usage (inside container):
 *   php /path/to/freshrss-delete-entries-older-than.php [hours] [path/to/db.sqlite]
 * Defaults: hours=24, db=/var/www/FreshRSS/data/users/admin/db.sqlite
 */
declare(strict_types=1);

$hours = isset($argv[1]) && $argv[1] !== '' ? (int) $argv[1] : 24;
$db = $argv[2] ?? '/var/www/FreshRSS/data/users/admin/db.sqlite';

if ($hours < 0) {
	fwrite(STDERR, "hours must be >= 0\n");
	exit(1);
}
if (!is_readable($db)) {
	fwrite(STDERR, "Database not readable: {$db}\n");
	exit(1);
}

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

$cutoff = time() - $hours * 3600;

$countStm = $pdo->prepare('SELECT COUNT(*) FROM entry WHERE date > 0 AND date < ?');
$countStm->execute([$cutoff]);
$before = (int) $countStm->fetchColumn();

$pdo->beginTransaction();
$del = $pdo->prepare('DELETE FROM entry WHERE date > 0 AND date < ?');
$del->execute([$cutoff]);
$deleted = $del->rowCount();

$pdo->exec(
	'UPDATE feed SET '
	. 'cache_nbEntries = (SELECT COUNT(*) FROM entry WHERE id_feed = feed.id), '
	. 'cache_nbUnreads = (SELECT COUNT(*) FROM entry WHERE id_feed = feed.id AND is_read = 0)'
);
$pdo->commit();

echo "Cutoff: UNIX {$cutoff} (rows with date > 0 and date before this are removed)\n";
echo "Matched before delete: {$before}, deleted: {$deleted}, hours threshold: {$hours}\n";
