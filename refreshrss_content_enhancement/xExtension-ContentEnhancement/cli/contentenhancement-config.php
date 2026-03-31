#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Read/write Content Enhancement system extension config (same persistence as Administration → Extensions).
 *
 * FreshRSS does not expose extension settings on the Google Reader / Fever HTTP APIs; this uses the
 * same bootstrap as official CLI scripts (cli/_cli.php) and saves via system configuration.
 *
 * Usage (inside FreshRSS tree, e.g. Docker):
 *   php extensions/xExtension-ContentEnhancement/cli/contentenhancement-config.php get [--full]
 *   php extensions/xExtension-ContentEnhancement/cli/contentenhancement-config.php set < patch.json
 *
 * @noinspection PhpUndefinedClassInspection FreshRSS loads after _cli.php
 */

$extRoot = dirname(__DIR__);
$freshRoot = dirname($extRoot, 2);
require $freshRoot . '/cli/_cli.php';
require_once $extRoot . '/Processor.php';

const EXT_NAME = 'ContentEnhancement';

/**
 * @param array<int,string> $argv
 */
function ce_print_usage(): void {
	fwrite(STDERR, "Usage:\n");
	fwrite(STDERR, "  php contentenhancement-config.php get [--full]   # JSON to stdout (api_key redacted unless --full)\n");
	fwrite(STDERR, "  php contentenhancement-config.php set            # merge JSON from stdin into saved config\n");
}

/**
 * @return array<string,mixed>
 */
function ce_load_extension_config(): array {
	if (!FreshRSS_Context::hasSystemConf()) {
		fail('FreshRSS error: system configuration not available.' . "\n");
	}
	$conf = FreshRSS_Context::systemConf();
	if (!$conf->hasParam('extensions')) {
		return [];
	}
	/** @var array<string,array<string,mixed>> $exts */
	$exts = $conf->extensions;
	return $exts[EXT_NAME] ?? [];
}

/**
 * @param array<string,mixed> $data
 */
function ce_save_extension_config(array $data): void {
	if (!FreshRSS_Context::hasSystemConf()) {
		fail('FreshRSS error: system configuration not available.' . "\n");
	}
	$conf = FreshRSS_Context::systemConf();
	$extensions = $conf->hasParam('extensions') ? $conf->extensions : [];
	$extensions[EXT_NAME] = $data;
	$conf->extensions = $extensions;
	$conf->save();
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function ce_redact_for_output(array $row): array {
	$out = $row;
	if (isset($out['api_key']) && is_string($out['api_key']) && $out['api_key'] !== '') {
		$out['api_key'] = '***REDACTED***';
	}

	return $out;
}

// argv[0] = this script
$args = array_slice($_SERVER['argv'] ?? [], 1);
$action = $args[0] ?? '';
$full = in_array('--full', $args, true);

if ($action === 'get') {
	$row = ce_load_extension_config();
	fwrite(STDERR, "Note: config is stored in system extension settings (not the Reader HTTP API).\n");
	if (!$full && isset($row['api_key'])) {
		$row = ce_redact_for_output($row);
	}
	$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR;
	if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
		$jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
	}
	echo json_encode($row, $jsonFlags) . "\n";
	done(true);
}

if ($action === 'set') {
	$raw = stream_get_contents(STDIN);
	if ($raw === false || trim($raw) === '') {
		fail("FreshRSS error: set requires JSON on stdin.\n");
	}
	try {
		/** @var mixed $patch */
		$patch = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
	} catch (JsonException $e) {
		fail('FreshRSS error: invalid JSON on stdin: ' . $e->getMessage() . "\n");
	}
	if (!is_array($patch)) {
		fail("FreshRSS error: JSON root must be an object.\n");
	}
	/** @var array<string,mixed> $patch */
	if (array_key_exists('api_key', $patch) && $patch['api_key'] === '') {
		unset($patch['api_key']);
	}
	$current = ce_load_extension_config();
	if ($current === [] && $patch !== [] && !isset($patch['api_base'])) {
		fail(
			"FreshRSS error: no saved Content Enhancement config yet. Configure once in Administration → Extensions → Content Enhancement (or pass a JSON object that includes at least api_base).\n",
		);
	}
	$merged = array_merge($current, $patch);
	if (array_key_exists('system_prompt', $merged)) {
		$merged['system_prompt'] = ContentEnhancement_Processor::normalizeScoringCriteria((string) $merged['system_prompt']);
	}
	ce_save_extension_config($merged);
	$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
	if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
		$jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
	}
	echo json_encode(['ok' => true, 'saved_keys' => array_keys($merged)], $jsonFlags) . "\n";
	done(true);
}

ce_print_usage();
fail("Invalid arguments.\n");
