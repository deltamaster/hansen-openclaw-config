<?php
/**
 * Connectivity check from inside the FreshRSS container.
 * Prefer cURL: libcurl honors HTTP(S)_PROXY / ALL_PROXY (including socks5h).
 * file_get_contents() HTTP streams often do not use SOCKS proxies reliably.
 */
$url = 'https://www.google.com/robots.txt';

echo 'Env: HTTP_PROXY=' . getenv('HTTP_PROXY') . ' ALL_PROXY=' . getenv('ALL_PROXY') . "\n\n";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$r = curl_exec($ch);
$err = curl_error($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($r !== false && $code >= 200 && $code < 400) {
	echo 'OK: cURL GET ' . $url . ' (HTTP ' . $code . ', ' . strlen($r) . " bytes)\n";
	exit(0);
}

echo 'FAIL: ' . $url . ' — HTTP ' . $code . ' — ' . $err . "\n";
exit(1);
