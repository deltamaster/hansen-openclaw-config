<?php
declare(strict_types=1);

$htmlPath = $argv[1] ?? '';
$outPath = $argv[2] ?? '';
if ($htmlPath === '' || !is_readable($htmlPath)) {
	fwrite(STDERR, "Usage: php run-strip-test.php <path-to.html> [path-to-output.txt]\n");
	fwrite(STDERR, "  If output path is omitted, prints to stdout only.\n");
	exit(1);
}

$processor = dirname(__DIR__) . '/xExtension-ContentEnhancement/Processor.php';
if (!is_readable($processor)) {
	$processor = '/var/www/FreshRSS/extensions/xExtension-ContentEnhancement/Processor.php';
}
require_once $processor;

$html = file_get_contents($htmlPath);
if ($html === false) {
	fwrite(STDERR, "Could not read file.\n");
	exit(1);
}

$url = 'https://www.nytimes.com/example';
$plain = ContentEnhancement_Processor::extractReadableText($html, $url);

$header = "=== Original size: " . strlen($html) . " bytes ===\n"
	. "=== After extractReadableText (strip + cap 12000 chars): " . strlen($plain) . " bytes ===\n\n";

if ($outPath !== '') {
	$dir = dirname($outPath);
	if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
		@mkdir($dir, 0755, true);
	}
	$written = file_put_contents($outPath, $header . $plain);
	if ($written === false) {
		fwrite(STDERR, "Could not write: {$outPath}\n");
		exit(1);
	}
	echo "Wrote " . $written . " bytes to {$outPath}\n";
} else {
	echo $header;
	echo $plain;
}
