<?php

declare(strict_types=1);

/**
 * Scraping + LLM (OpenAI-compatible chat or Gemini `generateContent` with `responseJsonSchema`) + entry enrichment.
 *
 * Add `fivefilters/readability-php` (or similar) under FreshRSS and call it from
 * {@see extractReadableText()} for production-quality main-text extraction.
 */
final class ContentEnhancement_Processor
{
	private const ATTR_NAMESPACE = 'content_enhancement';

	/**
	 * FreshRSS user label for entries where the publisher returned 401/403 (anti-bot / robots).
	 * Applied when fetch returns 401/403 in {@see process()}; synced by {@see syncFreshRssUserLabelsFromMetadata}.
	 */
	private const LABEL_ROBOTS_DISCOURAGED = '_antirobots';

	/** Applied on every {@see process()} path that stores enriched metadata with {@see normalizeLabelsForStorage()}. */
	private const LABEL_PROCESSED = '_processed';

	/** Exact names preserved by {@see normalizeLabelsForStorage()} (not stripped to lowercase a–z). */
	private const INTERNAL_LABELS = ['_lowquality', '_highquality', '_propaganda', '_ads', '_antirobots', '_processed'];

	/** Set when {@see callLlm} returns null; included in `llm_failed` logs. */
	private static string $lastLlmFailureDetail = '';

	/** Set when {@see fetchHtml} returns null; included in `fetch_failed` logs. */
	private static string $lastFetchFailureDetail = '';

	/** Last HTTP status from {@see fetchHtml}; 401/403 skips fetch and keeps RSS body with prefilter score. */
	private static int $lastFetchHttpCode = 0;

	/**
	 * `summary_zh` JSON Schema description text; also used to detect when the model echoed the schema instead of a real summary.
	 */
	private const SUMMARY_ZH_FIELD_DESCRIPTION = '约100–150字中文摘要，据正文独立撰写。';

	/** Shared relevance rubric + label definitions (used by prefilter and full-scan). Config key: `system_prompt`. */
	public static function defaultScoringCriteria(): string
	{
		return "你是新闻与阅读**相关性**评估助手：输出字段 **relevance_score**（1–10）表示该条对读者的**综合相关度**。请把「客观信息价值 / 稿件质量」与「主观上是否值得读」放在**同一分数**里：低信息密度的通稿、宣传口号稿打低分；对你认为读者会关心、信息清晰或话题重要的条目打高分。可在下文补充个人偏好（例如不感兴趣的话题打低分）。必须**严格**打分：宣传稿、无新事实的通稿通常 **1–3**。\n\n"
			. "【relevance_score 校准（必须遵守）】\n"
			. "- **1–3**：几乎无独立新闻价值或对你设定的读者几乎不值得读。典型：意识形态学习/表态稿；无新事实、无新数据的通稿；仅复述会议精神、政策口号；官样活动报道无实质信息。\n"
			. "- **4–5**：信息稀薄、明显片面，或宣传为主但夹杂少量可核实信息；或话题边缘、兴趣低。\n"
			. "- **6–7**：有可核实的事件要素或一定阅读价值，但深度或吸引力一般。\n"
			. "- **8–9**：事实清楚，有数据、背景、多方信息或明确新闻点；或高度符合读者兴趣。\n"
			. "- **10**：极少使用；深度调查、重大独家、高信息密度或对你定义的读者极具相关性的报道。\n\n"
			. "【低分强制规则】若标题或正文明显属于「学习」「领会」「政绩观教育」等套话类，且**没有**可独立核实的事实增量，则 relevance_score **不得高于 3**\n\n";
	}

	/**
	 * Fixed English instructions: prefilter pass (title + RSS only, relevance_score JSON only). Not user-configurable.
	 */
	private static function prefilterStructurePrompt(): string
	{
		return "[Prefilter — input and output]\n"
			. "- The user message contains only the article title and RSS summary/snippet. There is no fetched web page body. Estimate relevance_score; when the signal is thin, infer conservatively and use the same scoring scale as for a full article.\n"
			. "- Output exactly one JSON object matching the API-enforced JSON response schema (no other top-level fields).\n"
			. "- No Markdown code fences, no explanatory text outside JSON.\n";
	}

	/**
	 * Fixed English instructions: full analysis (body + summary_zh + labels JSON). Not user-configurable.
	 */
	private static function fullscanStructurePrompt(): string
	{
		return "[Full analysis — input and output]\n"
			. "The user message includes the title and the article body as plain text extracted from the page (or RSS snippet when the article is unavailable). Write the Chinese summary and score per the rubric below.\n\n"
			. "Respond with exactly one JSON object that conforms to the API-enforced JSON response schema (property descriptions are authoritative).\n\n"
			. "Emit only one JSON object. No Markdown fences, no extra text before or after.\n"
			. "Do not output chain-of-thought, filler, or tags like `<think>` / `</think>`; the response must start with `{`.\n";
	}

	/** @deprecated Use {@see defaultScoringCriteria()} */
	public static function defaultSystemPrompt(): string
	{
		return self::defaultScoringCriteria() . "\n\n" . self::fullscanStructurePrompt();
	}

	/**
	 * Undo layered HTML entity encoding (e.g. &amp;amp;quot;) from repeated textarea round-trips.
	 *
	 * @param string $prompt Shared scoring criteria (`system_prompt`).
	 */
	public static function normalizeScoringCriteria(string $prompt): string
	{
		$prompt = trim($prompt);
		if ($prompt === '') {
			return self::defaultScoringCriteria();
		}
		$prev = '';
		$guard = 0;
		while ($prompt !== $prev && $guard < 12 && strlen($prompt) < 500000) {
			$prev = $prompt;
			$prompt = html_entity_decode($prompt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$guard++;
		}

		return $prompt;
	}

	/** @deprecated Use {@see normalizeScoringCriteria()} */
	public static function normalizeSystemPrompt(string $prompt): string
	{
		return self::normalizeScoringCriteria($prompt);
	}

	/**
	 * Assembled system message: structure (prefilter) first, then shared scoring criteria.
	 */
	private static function buildPrefilterSystemPrompt(string $structure, string $scoring): string
	{
		return self::capSystemInstruction(trim($structure) . "\n\n" . trim($scoring));
	}

	/**
	 * Assembled system message: structure (full scan) first, then shared scoring criteria.
	 */
	private static function buildFullscanSystemPrompt(string $structure, string $scoring): string
	{
		return self::capSystemInstruction(trim($structure) . "\n\n" . trim($scoring));
	}

	private static function capSystemInstruction(string $combined, int $max = 120000): string
	{
		if (mb_strlen($combined, 'UTF-8') <= $max) {
			return $combined;
		}

		return mb_substr($combined, 0, $max, 'UTF-8') . "\n\n[… system instruction truncated …]";
	}

	public static function process(FreshRSS_Entry $entry, ContentEnhancementExtension $ext): ?FreshRSS_Entry
	{
		$t0 = microtime(true);
		$base = rtrim((string) $ext->getSystemConfigurationValue('api_base'), '/');
		$key = self::normalizeApiKey((string) $ext->getSystemConfigurationValue('api_key'));
		$model = (string) $ext->getSystemConfigurationValue('model');
		$minQ = (int) $ext->getSystemConfigurationValue('min_quality');
		$highQualityThreshold = (int) $ext->getSystemConfigurationValue('high_quality_threshold', 9);
		if ($highQualityThreshold < 1 || $highQualityThreshold > 10) {
			$highQualityThreshold = 9;
		}
		$markRead = (bool) $ext->getSystemConfigurationValue('mark_low_quality_read');
		$discard = (bool) $ext->getSystemConfigurationValue('discard_below_threshold');
		$scoring = self::normalizeScoringCriteria((string) $ext->getSystemConfigurationValue('system_prompt', ''));
		$prefilterStructure = self::prefilterStructurePrompt();
		$fullscanStructure = self::fullscanStructurePrompt();
		$fullSystem = self::buildFullscanSystemPrompt($fullscanStructure, $scoring);
		$prefilterSystem = self::buildPrefilterSystemPrompt($prefilterStructure, $scoring);

		if ($base === '' || $key === '' || (!self::isGeminiGenerateContentUrl($base) && $model === '')) {
			$modelForLog = '-';
			if ($model !== '') {
				$modelForLog = $model;
			} elseif ($base !== '' && self::isGeminiGenerateContentUrl($base)) {
				$modelForLog = self::geminiModelIdFromUrl($base);
			}
			Minz_Log::warning(self::formatContentEnhancementLogLine(
				'fullscan',
				'error',
				$entry,
				null,
				0,
				$modelForLog,
				null,
				null,
				'config_missing',
				'Set api_base, api_key, and model (for OpenAI-style endpoints).',
			));

			return $entry;
		}

		$modelLabel = $model !== '' ? $model : self::geminiModelIdFromUrl($base);

		$prefilterEnabled = $ext->getSystemConfigurationValue('prefilter_before_fetch');
		if ($prefilterEnabled === null) {
			$prefilterEnabled = true;
		} else {
			$prefilterEnabled = (bool) $prefilterEnabled;
		}

		$rssPlainForPrefilter = self::plainTextFromRssEntry($entry);
		$rssPlainForPrefilter = mb_substr($rssPlainForPrefilter, 0, 4000, 'UTF-8');

		/** Set when prefilter ran successfully; used if fetch is blocked (401/403) to avoid assuming a high score. */
		$prefilterRel = null;
		if ($prefilterEnabled) {
			$pre = self::callLlmRelevancePrefilter($base, $key, $model, $entry->title(), $rssPlainForPrefilter, $prefilterSystem);
			if ($pre === null) {
				Minz_Log::warning(self::formatContentEnhancementLogLine(
					'prefilter',
					'error',
					$entry,
					null,
					(int) round((microtime(true) - $t0) * 1000),
					$modelLabel,
					null,
					null,
					'prefilter_failed',
					self::$lastLlmFailureDetail !== '' ? self::$lastLlmFailureDetail : 'unknown',
				));
			} else {
				$rel = (int) ($pre['relevance_score'] ?? 0);
				$prefilterRel = $rel;
				$latencyP = (int) round((microtime(true) - $t0) * 1000);
				$preUsage = self::popLlmUsageFromResult($pre);
				$cutoffScore = $minQ - 1;
				if ($rel < $cutoffScore) {
					$commonMeta = [
						'relevance_score' => $rel,
						'cutoff_score' => $cutoffScore,
						'min_quality' => $minQ,
						'latency_ms' => $latencyP,
						'model' => $modelLabel,
					];
					if ($preUsage !== null) {
						$commonMeta['llm_usage_prefilter'] = $preUsage;
					}
					if ($discard) {
						self::setMeta($entry, array_merge($commonMeta, ['status' => 'prefilter_drop']));
						Minz_Log::warning(self::formatContentEnhancementLogLine(
							'prefilter',
							'drop',
							$entry,
							$rel,
							$latencyP,
							$modelLabel,
							$preUsage,
							null,
							null,
							null,
							'cutoff=' . $cutoffScore . ' min_quality=' . $minQ,
						));

						return null;
					}
					self::setMeta($entry, array_merge($commonMeta, ['status' => 'prefilter_skip_enhance']));
					Minz_Log::warning(self::formatContentEnhancementLogLine(
						'prefilter',
						'keep',
						$entry,
						$rel,
						$latencyP,
						$modelLabel,
						$preUsage,
						null,
						null,
						null,
						'cutoff=' . $cutoffScore . ' min_quality=' . $minQ . ' skip_enhance=1',
					));

					return $entry;
				}
				Minz_Log::warning(self::formatContentEnhancementLogLine(
					'prefilter',
					'proceed',
					$entry,
					$rel,
					$latencyP,
					$modelLabel,
					$preUsage,
					null,
					null,
					null,
				));
			}
		}

		$link = $entry->link();
		$resolved = self::resolveUrl($link);
		if ($resolved !== $link) {
			$entry->_link($resolved);
		}

		$html = self::fetchHtml($resolved);
		if ($html === null) {
			$http = self::$lastFetchHttpCode;
			if ($http === 401 || $http === 403) {
				$latency = (int) round((microtime(true) - $t0) * 1000);
				$score = $prefilterRel !== null ? max(1, min(10, $prefilterRel)) : 5;
				$labels = self::normalizeLabelsForStorage([self::LABEL_ROBOTS_DISCOURAGED], $score, $highQualityThreshold);
				self::setMeta($entry, [
					'status' => 'ok',
					'source_url' => $resolved,
					'relevance_score' => $score,
					'labels' => $labels,
					'latency_ms' => $latency,
					'model' => $modelLabel,
					'fetch_blocked_http' => $http,
				]);

				$rssPlainForLog = self::plainTextFromRssEntry($entry);
				$summarySnippet = mb_substr(preg_replace('/\s+/u', ' ', $rssPlainForLog) ?? '', 0, 120, 'UTF-8');
				if (mb_strlen($rssPlainForLog, 'UTF-8') > 120) {
					$summarySnippet .= '…';
				}

				$decision = 'proceed';
				if ($score > 0 && $score < $minQ) {
					$decision = $discard ? 'drop' : 'keep';
				}

				Minz_Log::warning(self::formatContentEnhancementLogLine(
					'fullscan',
					$decision,
					$entry,
					$score,
					$latency,
					$modelLabel,
					null,
					$summarySnippet,
					null,
					null,
					'fetch_blocked_http=' . $http . ' rss_only=1 source_url=' . self::logSnippet($resolved, 500),
				));

				if ($score > 0 && $score < $minQ) {
					if ($discard) {
						return null;
					}
					if ($markRead) {
						$entry->_isRead(true);
					}
				}

				return $entry;
			}
			$latency = (int) round((microtime(true) - $t0) * 1000);
			self::setMeta($entry, [
				'status' => 'fetch_failed',
				'source_url' => $resolved,
				'latency_ms' => $latency,
				'failure_reason' => self::$lastFetchFailureDetail,
				'fetch_http_code' => $http,
			]);
			Minz_Log::warning(self::formatContentEnhancementLogLine(
				'fullscan',
				'error',
				$entry,
				null,
				$latency,
				$modelLabel,
				null,
				null,
				'fetch_failed',
				self::$lastFetchFailureDetail !== '' ? self::$lastFetchFailureDetail : 'unknown',
				'url=' . self::logSnippet($resolved, 500),
			));
			return $entry;
		}

		$plain = self::extractReadableText($html, $resolved);
		if ($plain === '') {
			$latency = (int) round((microtime(true) - $t0) * 1000);
			self::setMeta($entry, [
				'status' => 'extract_empty',
				'source_url' => $resolved,
				'latency_ms' => $latency,
			]);
			Minz_Log::warning(self::formatContentEnhancementLogLine(
				'fullscan',
				'error',
				$entry,
				null,
				$latency,
				$modelLabel,
				null,
				null,
				'extract_empty',
				'no readable text extracted',
				'url=' . self::logSnippet($resolved, 500),
			));
			return $entry;
		}

		$originalContent = $entry->content(false);
		// Preserve RSS HTML before we replace body with the LLM summary (same pattern as core `original_content`).
		if (!$entry->hasAttribute('original_content')) {
			$entry->_attribute('original_content', $originalContent);
		}

		$llm = self::callLlm(
			$base,
			$key,
			$model,
			$fullSystem,
			$plain,
			$entry->title(),
		);

		$latency = (int) round((microtime(true) - $t0) * 1000);
		$llmUsage = null;
		if (is_array($llm)) {
			$llmUsage = self::popLlmUsageFromResult($llm);
		}

		if ($llm === null) {
			self::setMeta($entry, [
				'status' => 'llm_failed',
				'source_url' => $resolved,
				'latency_ms' => $latency,
				'failure_reason' => self::$lastLlmFailureDetail,
			]);
			$reason = self::$lastLlmFailureDetail !== '' ? self::$lastLlmFailureDetail : 'unknown (no detail recorded)';
			Minz_Log::warning(self::formatContentEnhancementLogLine(
				'fullscan',
				'error',
				$entry,
				null,
				$latency,
				$modelLabel,
				null,
				null,
				'llm_failed',
				$reason,
			));
			return $entry;
		}

		$summary = trim((string) ($llm['summary_zh'] ?? ''));
		$score = self::relevanceScoreFromFullPassLlm($llm);
		$labels = $llm['labels'] ?? [];
		if (!is_array($labels)) {
			$labels = [];
		}
		$labels = self::normalizeLabelsForStorage(array_map('strval', $labels), $score, $highQualityThreshold);

		// Replace visible article content with LLM summary (HTML fragment).
		$entry->_content('<p>' . htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>');

		$okMeta = [
			'status' => 'ok',
			'source_url' => $resolved,
			'relevance_score' => $score,
			'labels' => $labels,
			'latency_ms' => $latency,
			'model' => $modelLabel,
		];
		if ($llmUsage !== null) {
			$okMeta['llm_usage'] = $llmUsage;
		}
		self::setMeta($entry, $okMeta);

		$summarySnippet = mb_substr(preg_replace('/\s+/u', ' ', $summary) ?? '', 0, 120, 'UTF-8');
		if (mb_strlen($summary, 'UTF-8') > 120) {
			$summarySnippet .= '…';
		}

		$decision = 'proceed';
		if ($score > 0 && $score < $minQ) {
			$decision = $discard ? 'drop' : 'keep';
		}

		Minz_Log::warning(self::formatContentEnhancementLogLine(
			'fullscan',
			$decision,
			$entry,
			$score,
			$latency,
			$modelLabel,
			$llmUsage,
			$summarySnippet,
			null,
			null,
			'source_url=' . self::logSnippet($resolved, 500),
		));

		if ($score > 0 && $score < $minQ) {
			if ($discard) {
				return null;
			}
			if ($markRead) {
				$entry->_isRead(true);
			}
		}

		return $entry;
	}

	/**
	 * Full-pass LLM JSON uses relevance_score (quality + subjective interest in one number). Legacy quality_score is still accepted.
	 *
	 * @param array<string,mixed> $llm
	 */
	private static function relevanceScoreFromFullPassLlm(array $llm): int
	{
		if (isset($llm['relevance_score'])) {
			return (int) $llm['relevance_score'];
		}
		if (isset($llm['quality_score'])) {
			return (int) $llm['quality_score'];
		}

		return 0;
	}

	/**
	 * Normalize parsed assistant JSON: prefer relevance_score; map legacy quality_score if present.
	 *
	 * @param array<string,mixed> $j
	 * @return array<string,mixed>
	 */
	private static function normalizeFullPassAssistantJson(array $j): array
	{
		if (!isset($j['relevance_score']) && isset($j['quality_score'])) {
			$j['relevance_score'] = $j['quality_score'];
		}

		return $j;
	}

	/** Plain text from the entry’s RSS/HTML body (before plugin replaces content). */
	private static function plainTextFromRssEntry(FreshRSS_Entry $entry): string
	{
		$html = $entry->content(false);
		$text = strip_tags(is_string($html) ? $html : '');
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/\s+/u', ' ', $text) ?? '';
		$text = trim($text);

		return mb_substr($text, 0, 12000, 'UTF-8');
	}

	/** Short label for logs (title + link); avoids empty logs when debugging feed refresh. */
	private static function logEntryLabel(FreshRSS_Entry $entry): string
	{
		$t = trim($entry->title());
		$t = $t === '' ? '(no title)' : mb_substr($t, 0, 80, 'UTF-8') . (mb_strlen($t, 'UTF-8') > 80 ? '…' : '');
		return 'title="' . $t . '" link=' . $entry->link();
	}

	/**
	 * One-line structured log for grep/ETL: action, decision, fields, optional summary, optional [error: …].
	 *
	 * @param 'prefilter'|'fullscan' $action
	 * @param 'drop'|'proceed'|'keep'|'error' $decision
	 * @param array<string,int>|null $usage LLM usage or null
	 */
	private static function formatContentEnhancementLogLine(
		string $action,
		string $decision,
		FreshRSS_Entry $entry,
		?int $relevanceScore,
		int $latencyMs,
		string $model,
		?array $usage,
		?string $summarySnippet = null,
		?string $errorCode = null,
		?string $errorMessage = null,
		?string $extraBeforeSummary = null,
	): string {
		$title = self::escapeLogTitleForQuotes($entry->title());
		$link = $entry->link();
		$rs = $relevanceScore !== null ? (string) $relevanceScore : '-';
		$usageStr = self::formatLlmUsageTokens($usage);
		$extra = ($extraBeforeSummary !== null && $extraBeforeSummary !== '') ? ' ' . $extraBeforeSummary : '';
		$sum = ' summary=-';
		if ($summarySnippet !== null && trim($summarySnippet) !== '') {
			$sum = ' summary="' . self::escapeLogTitleForQuotes(mb_substr(preg_replace('/\s+/u', ' ', $summarySnippet) ?? '', 0, 200, 'UTF-8')) . '"';
		}
		$err = '';
		if ($errorCode !== null && $errorCode !== '') {
			$msg = ($errorMessage !== null && $errorMessage !== '') ? ' ' . self::logSnippet($errorMessage, 400) : '';
			$err = ' [error: ' . $errorCode . $msg . ']';
		}

		return 'ContentEnhancement: ' . $action . ' ' . $decision
			. ' title="' . $title . '"'
			. ' link=' . $link
			. ' relevance_score=' . $rs
			. ' latency_ms=' . $latencyMs
			. ' model=' . $model
			. ' ' . $usageStr
			. $extra
			. $sum
			. $err;
	}

	private static function escapeLogTitleForQuotes(string $title): string
	{
		$t = trim($title);
		if ($t === '') {
			return '(no title)';
		}
		$t = mb_substr($t, 0, 80, 'UTF-8') . (mb_strlen($title, 'UTF-8') > 80 ? '…' : '');
		return str_replace(['\\', '"'], ['\\\\', '\\"'], $t);
	}

	/**
	 * Freeform LLM labels → lowercase Latin letters only (non-letters removed). Internal tags stay exact.
	 * Adds `_lowquality` when relevance_score ≤ 3 and `_highquality` when relevance_score ≥ the configured threshold.
	 *
	 * @param array<int,mixed> $labels
	 * @return list<string>
	 */
	private static function normalizeLabelsForStorage(array $labels, int $relevanceScore, int $highQualityThreshold): array
	{
		$internalSet = array_fill_keys(self::INTERNAL_LABELS, true);
		$out = [];
		foreach ($labels as $raw) {
			$s = trim((string) $raw);
			if ($s === '') {
				continue;
			}
			if (isset($internalSet[$s])) {
				$out[] = $s;
				continue;
			}
			$alpha = preg_replace('/[^a-z]/', '', strtolower($s)) ?? '';
			if ($alpha !== '') {
				$out[] = $alpha;
			}
		}
		$out = array_values(array_unique($out, SORT_STRING));
		if ($relevanceScore <= 3) {
			if (!in_array('_lowquality', $out, true)) {
				$out[] = '_lowquality';
			}
		}
		if ($relevanceScore >= $highQualityThreshold) {
			if (!in_array('_highquality', $out, true)) {
				$out[] = '_highquality';
			}
		}
		if (!in_array(self::LABEL_PROCESSED, $out, true)) {
			$out[] = self::LABEL_PROCESSED;
		}

		return $out;
	}

	/**
	 * Always emits prompt/completion/total (use `-` when unknown); optional cached=.
	 *
	 * @param array<string,int>|null $usage
	 */
	private static function formatLlmUsageTokens(?array $usage): string
	{
		if ($usage === null || $usage === []) {
			return 'llm_tokens prompt=- completion=- total=-';
		}
		$prompt = isset($usage['prompt_tokens']) ? (string) (int) $usage['prompt_tokens'] : '-';
		$completion = isset($usage['completion_tokens']) ? (string) (int) $usage['completion_tokens'] : '-';
		$total = isset($usage['total_tokens']) ? (string) (int) $usage['total_tokens'] : '-';
		$out = 'llm_tokens prompt=' . $prompt . ' completion=' . $completion . ' total=' . $total;
		if (isset($usage['cached_content_tokens'])) {
			$out .= ' cached=' . (int) $usage['cached_content_tokens'];
		}

		return $out;
	}

	/**
	 * Store plugin metadata under a single attributes key (nested array).
	 *
	 * FreshRSS persists `attributes` as JSON on the entry row. Use {@see FreshRSS_Entry::_attribute()}
	 * with a **string key**; values may be strings or nested arrays (see core: `thumbnail`, `enclosures`).
	 *
	 * @param array<string,mixed> $data
	 */
	private static function setMeta(FreshRSS_Entry $entry, array $data): void
	{
		$prev = $entry->attributeArray(self::ATTR_NAMESPACE);
		if (!is_array($prev)) {
			$prev = [];
		}
		$entry->_attribute(self::ATTR_NAMESPACE, array_merge($prev, $data));
	}

	/**
	 * Apply LLM `labels` as FreshRSS user labels (tags).
	 *
	 * New articles are stored in `_entrytmp` first then committed to `_entry`, sometimes with a **new** id.
	 * `_entrytag` references `_entry.id`, so we must tag **after** commit — on first display (see
	 * {@see Minz_HookType::EntryBeforeDisplay}).
	 */
	public static function syncFreshRssUserLabelsFromMetadata(FreshRSS_Entry $entry): FreshRSS_Entry
	{
		if (!class_exists('FreshRSS_Factory')) {
			return $entry;
		}
		$meta = $entry->attributeArray(self::ATTR_NAMESPACE);
		if (!is_array($meta) || ($meta['status'] ?? '') !== 'ok') {
			return $entry;
		}
		if (!empty($meta['freshrss_labels_synced'])) {
			return $entry;
		}
		$id = $entry->id();
		if ($id === '' || $id === '0' || !ctype_digit((string) $id)) {
			return $entry;
		}

		$labels = $meta['labels'] ?? [];
		if (!is_array($labels)) {
			$labels = [];
		}
		$labels = array_values(array_filter(array_map('strval', $labels), static fn($s) => trim((string) $s) !== ''));

		try {
			$tagDAO = FreshRSS_Factory::createTagDao();
			foreach ($labels as $name) {
				$name = trim((string) $name);
				$tag = $tagDAO->searchByName($name);
				if ($tag === null) {
					$newId = $tagDAO->addTag(['name' => $name, 'attributes' => []]);
					if ($newId === false) {
						Minz_Log::warning('ContentEnhancement: could not create label "' . $name . '" (category name conflict or DB error).');
						continue;
					}
					$tagId = (int) $newId;
				} else {
					$tagId = $tag->id();
				}
				if (!$tagDAO->tagEntry($tagId, $id, true)) {
					Minz_Log::warning('ContentEnhancement: tagEntry failed for label "' . $name . '" entry_id=' . $id);
				}
			}
		} catch (Throwable $e) {
			Minz_Log::warning('ContentEnhancement: label sync — ' . $e->getMessage());
			return $entry;
		}

		$meta['freshrss_labels_synced'] = true;
		$entry->_attribute(self::ATTR_NAMESPACE, $meta);
		try {
			FreshRSS_Factory::createEntryDao()->updateEntry($entry->toArray());
		} catch (Throwable $e) {
			Minz_Log::warning('ContentEnhancement: could not persist freshrss_labels_synced — ' . $e->getMessage());
		}

		return $entry;
	}

	/**
	 * Browser-like UA — aligns with workspace skill `resolve-google-news-url`
	 * (many aggregators gate on User-Agent).
	 */
	private const FETCH_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

	/** Google News RSS/article URLs do not resolve to the publisher via plain HTTP redirects; use batchexecute first. */
	private const GOOGLE_NEWS_BATCHEXECUTE = 'https://news.google.com/_/DotsSplashUi/data/batchexecute';

	/**
	 * Google News / tracking URLs: resolve wrappers (news.google.com/.../articles/...) to publisher URL, then follow redirects.
	 * Mirrors {@see skills/resolve-google-news-url/scripts/resolve_google_news_url.py}: batchexecute decode → optional HTML fallback → HEAD chain.
	 */
	public static function resolveUrl(string $url): string
	{
		$url = trim($url);
		if ($url === '') {
			return $url;
		}

		if (self::googleNewsArticleToken($url) !== null) {
			$origin = $url;
			$decoded = self::decodeGoogleNewsBatchexecute($url, 45.0);
			if ($decoded !== null) {
				$source = self::followRedirectsHead($decoded, 20.0);
				self::logGoogleNewsResolved('batchexecute', $origin, $source, $decoded);
				return $source;
			}
			$fallback = self::resolveGoogleNewsHtmlFallback($url, 45.0);
			if ($fallback !== null
				&& $fallback !== $url
				&& !str_contains(strtolower($fallback), 'news.google.com')
				&& !str_contains(strtolower($fallback), 'google.com/url')) {
				$source = self::followRedirectsHead($fallback, 20.0);
				self::logGoogleNewsResolved('html_fallback', $origin, $source, $fallback);
				return $source;
			}
		}

		return self::followRedirectsHead($url, 15.0);
	}

	/**
	 * Log Google News wrapper URL → publisher URL for debugging (long URLs truncated on one line).
	 *
	 * @param 'batchexecute'|'html_fallback' $via
	 */
	private static function logGoogleNewsResolved(string $via, string $origin, string $source, string $intermediate): void
	{
		$o = self::logSnippet($origin, 700);
		$s = self::logSnippet($source, 700);
		$line = 'ContentEnhancement: google_news_resolved — via=' . $via . ' origin=' . $o . ' => source=' . $s;
		if ($intermediate !== $source) {
			$line .= ' (after_redirect_from=' . self::logSnippet($intermediate, 700) . ')';
		}
		Minz_Log::warning($line);
	}

	/** Encoded article token from /rss/articles/, /articles/, or /read/ paths on news.google.com. */
	private static function googleNewsArticleToken(string $url): ?string
	{
		$parts = parse_url($url);
		if ($parts === false) {
			return null;
		}
		$host = strtolower((string) ($parts['host'] ?? ''));
		if ($host !== 'news.google.com') {
			return null;
		}
		$segments = array_values(array_filter(explode('/', (string) ($parts['path'] ?? '')), static fn($s) => $s !== ''));
		$n = count($segments);
		if ($n < 2) {
			return null;
		}
		if (in_array($segments[$n - 2], ['articles', 'read'], true)) {
			return $segments[$n - 1] !== '' ? $segments[$n - 1] : null;
		}
		if ($n >= 3 && $segments[$n - 3] === 'rss' && $segments[$n - 2] === 'articles') {
			return $segments[$n - 1] !== '' ? $segments[$n - 1] : null;
		}

		return null;
	}

	/** data-n-a-sg and data-n-a-ts from Google News article shell HTML (see resolve_google_news_url.py). */
	private static function extractGoogleNewsNAAttrs(string $html): array
	{
		$sg = null;
		$ts = null;
		if (preg_match('/data-n-a-sg="([^"]*)"/', $html, $m)) {
			$sg = $m[1];
		}
		if (preg_match('/data-n-a-ts="([^"]*)"/', $html, $m)) {
			$ts = $m[1];
		}

		return [$sg, $ts];
	}

	/**
	 * Resolve news.google.com article URL to publisher via batchexecute (same payload shape as the Python skill).
	 */
	private static function decodeGoogleNewsBatchexecute(string $url, float $timeout): ?string
	{
		$token = self::googleNewsArticleToken($url);
		if ($token === null) {
			return null;
		}
		$signature = null;
		$timestamp = null;
		$usedToken = $token;
		foreach (["https://news.google.com/articles/{$token}", "https://news.google.com/rss/articles/{$token}"] as $pageUrl) {
			$html = self::curlGetBody($pageUrl, $timeout);
			if ($html === null) {
				continue;
			}
			[$signature, $timestamp] = self::extractGoogleNewsNAAttrs($html);
			if (is_string($signature) && $signature !== '' && is_string($timestamp) && $timestamp !== '') {
				break;
			}
		}
		if (!is_string($signature) || $signature === '' || !is_string($timestamp) || $timestamp === '') {
			return null;
		}

		$gart = [
			'garturlreq',
			[
				['X', 'X', ['X', 'X'], null, null, 1, 1, 'US:en', null, 1, null, null, null, null, null, 0, 1],
				'X', 'X', 1, [1, 1, 1], 1, 1, null, 0, 0, null, 0,
			],
			$usedToken,
			(int) $timestamp,
			$signature,
		];
		$innerJson = json_encode($gart, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($innerJson === false) {
			return null;
		}
		$payload = ['Fbv4je', $innerJson];
		// Python: json.dumps([[payload]]) — outer list wraps `payload` once → triple JSON array.
		$encoded = json_encode([[$payload]], JSON_UNESCAPED_UNICODE);
		if ($encoded === false) {
			return null;
		}
		$body = 'f.req=' . rawurlencode($encoded);

		$ch = curl_init(self::GOOGLE_NEWS_BATCHEXECUTE);
		if ($ch === false) {
			return null;
		}
		$t = (int) max(15, (int) ceil($timeout));
		$headers = [
			'Content-Type: application/x-www-form-urlencoded;charset=UTF-8',
			'Accept: */*',
			'Accept-Language: en-US,en;q=0.9',
		];
		$opts = [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => $t,
			CURLOPT_CONNECTTIMEOUT => min(30, max(10, (int) ($t / 3))),
			CURLOPT_USERAGENT => self::FETCH_USER_AGENT,
			CURLOPT_HTTPHEADER => $headers,
		];
		$proxy = getenv('HTTPS_PROXY') ?: getenv('HTTP_PROXY') ?: getenv('ALL_PROXY');
		if (is_string($proxy) && $proxy !== '') {
			$opts[CURLOPT_PROXY] = $proxy;
		}
		curl_setopt_array($ch, $opts);
		$text = curl_exec($ch);
		curl_close($ch);
		if (!is_string($text) || $text === '') {
			return null;
		}

		return self::parseBatchexecuteDecodedUrl($text);
	}

	private static function parseBatchexecuteDecodedUrl(string $text): ?string
	{
		$text = trim($text);
		if ($text === '') {
			return null;
		}
		if (str_starts_with($text, ")]}'")) {
			$text = preg_replace('/^\s*\)\]\}\'\s*/', '', $text) ?? $text;
		}
		$chunk = $text;
		if (preg_match('/\r\n\r\n(.+)/s', $text, $m)) {
			$chunk = $m[1];
		} elseif (preg_match('/\n\n(.+)/s', $text, $m)) {
			$chunk = $m[1];
		}
		$parsed = json_decode($chunk, true);
		if (!is_array($parsed)) {
			return null;
		}
		if (count($parsed) >= 2) {
			$parsed = array_slice($parsed, 0, -2);
		}
		if (!isset($parsed[0][2]) || !is_string($parsed[0][2])) {
			return null;
		}
		$inner = json_decode($parsed[0][2], true);
		if (!is_array($inner) || !isset($inner[1])) {
			return null;
		}
		$decodedUrl = $inner[1];

		return is_string($decodedUrl) && str_starts_with($decodedUrl, 'http') ? $decodedUrl : null;
	}

	/** If still on Google after redirect, try to read publisher URL from body (Python skill fallback). */
	private static function resolveGoogleNewsHtmlFallback(string $url, float $timeout): ?string
	{
		$html = self::curlGetBody($url, $timeout);
		if ($html === null) {
			return null;
		}
		$extracted = self::extractGoogleUrlParamFromHtml($html);
		if ($extracted !== null) {
			return $extracted;
		}

		return $url;
	}

	private static function extractGoogleUrlParamFromHtml(string $html): ?string
	{
		if (preg_match_all('#https?://(?:www\.)?google\.com/url\?[^"\'\s<>]+#i', $html, $matches)) {
			foreach ($matches[0] as $raw) {
				$raw = rtrim($raw, '\\)');
				$q = [];
				parse_str((string) parse_url($raw, PHP_URL_QUERY), $q);
				if (!empty($q['q']) && is_string($q['q']) && str_starts_with($q['q'], 'http')) {
					return rawurldecode($q['q']);
				}
			}
		}
		if (preg_match_all('/["\']url["\']\s*:\s*["\'](https?:\/\/[^"\']+)["\']/', $html, $matches)) {
			foreach ($matches[1] as $u) {
				$u = rawurldecode($u);
				if (str_contains(strtolower($u), 'google.com')) {
					continue;
				}
				if (str_starts_with($u, 'http')) {
					return $u;
				}
			}
		}
		if (preg_match('/[?&]q=([^&"\'\s<>]+)/', $html, $m)) {
			$cand = rawurldecode($m[1]);
			$host = strtolower((string) parse_url($cand, PHP_URL_HOST));
			if (str_starts_with($cand, 'http') && $host !== '' && !str_contains($host, 'google.com')) {
				return $cand;
			}
		}

		return null;
	}

	private static function curlGetBody(string $url, float $timeout): ?string
	{
		$ch = curl_init($url);
		if ($ch === false) {
			return null;
		}
		$t = (int) max(10, (int) ceil($timeout));
		$opts = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => $t,
			CURLOPT_CONNECTTIMEOUT => min(30, max(10, (int) ($t / 3))),
			CURLOPT_USERAGENT => self::FETCH_USER_AGENT,
			CURLOPT_HTTPHEADER => [
				'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language: en-US,en;q=0.9',
				'Cache-Control: no-cache',
			],
		];
		$proxy = getenv('HTTPS_PROXY') ?: getenv('HTTP_PROXY') ?: getenv('ALL_PROXY');
		if (is_string($proxy) && $proxy !== '') {
			$opts[CURLOPT_PROXY] = $proxy;
		}
		curl_setopt_array($ch, $opts);
		$body = curl_exec($ch);
		curl_close($ch);

		return is_string($body) && $body !== '' ? $body : null;
	}

	private static function followRedirectsHead(string $url, float $timeout): string
	{
		$ch = curl_init($url);
		if ($ch === false) {
			return $url;
		}
		$t = (int) max(5, (int) ceil($timeout));
		$opts = [
			CURLOPT_NOBODY => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => $t,
			CURLOPT_CONNECTTIMEOUT => min(15, max(5, (int) ($t / 2))),
			CURLOPT_USERAGENT => self::FETCH_USER_AGENT,
		];
		$proxy = getenv('HTTPS_PROXY') ?: getenv('HTTP_PROXY') ?: getenv('ALL_PROXY');
		if (is_string($proxy) && $proxy !== '') {
			$opts[CURLOPT_PROXY] = $proxy;
		}
		curl_setopt_array($ch, $opts);
		curl_exec($ch);
		$final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
		curl_close($ch);

		return is_string($final) && $final !== '' ? $final : $url;
	}

	public static function fetchHtml(string $url): ?string
	{
		self::$lastFetchFailureDetail = '';
		self::$lastFetchHttpCode = 0;
		if ($url === '') {
			self::$lastFetchFailureDetail = 'empty_url';
			return null;
		}
		$ch = curl_init($url);
		if ($ch === false) {
			self::$lastFetchFailureDetail = 'curl_init_failed';
			return null;
		}
		$opts = [
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 25,
			CURLOPT_CONNECTTIMEOUT => 12,
			CURLOPT_USERAGENT => self::FETCH_USER_AGENT,
			CURLOPT_HTTPHEADER => [
				'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language: en-US,en;q=0.9',
			],
		];
		$proxy = getenv('HTTPS_PROXY') ?: getenv('HTTP_PROXY') ?: getenv('ALL_PROXY');
		if (is_string($proxy) && $proxy !== '') {
			$opts[CURLOPT_PROXY] = $proxy;
		}
		curl_setopt_array($ch, $opts);
		$body = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErr = curl_error($ch);
		curl_close($ch);
		self::$lastFetchHttpCode = $code;
		if ($body === false) {
			self::$lastFetchFailureDetail = 'curl_exec_failed: ' . ($curlErr !== '' ? self::logSnippet($curlErr, 200) : 'unknown');
			return null;
		}
		if (!is_string($body) || $code >= 400 || $code === 0) {
			self::$lastFetchFailureDetail = sprintf(
				'http_code=%d%s',
				$code,
				$curlErr !== '' ? ' curl=' . self::logSnippet($curlErr, 150) : '',
			);
			return null;
		}
		return $body;
	}

	/**
	 * Shrink HTML before {@see extractReadableText} strips tags — fewer tokens to the LLM.
	 * Removes head/scripts/styles, site chrome (header/footer/nav), embedded media players (e.g. Video.js) and SVG,
	 * CSS-related attributes, and empty elements.
	 */
	private static function prepareHtmlForLlmExtraction(string $html): string
	{
		if ($html === '') {
			return '';
		}
		$html = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $html) ?? $html;
		$prev = libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$loaded = @$dom->loadHTML(
			'<?xml encoding="utf-8"?>' . $html,
			LIBXML_HTML_NODEFDTD,
		);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		if (!$loaded) {
			return self::prepareHtmlForLlmExtractionFallback($html);
		}

		foreach (['script', 'style', 'noscript', 'head'] as $tag) {
			$nodes = $dom->getElementsByTagName($tag);
			for ($i = $nodes->length - 1; $i >= 0; $i--) {
				$n = $nodes->item($i);
				if ($n !== null && $n->parentNode !== null) {
					$n->parentNode->removeChild($n);
				}
			}
		}

		$links = $dom->getElementsByTagName('link');
		for ($i = $links->length - 1; $i >= 0; $i--) {
			$l = $links->item($i);
			if (!$l instanceof DOMElement) {
				continue;
			}
			$rel = strtolower($l->getAttribute('rel'));
			$as = strtolower($l->getAttribute('as'));
			if ($rel === 'stylesheet' || $as === 'style') {
				$l->parentNode?->removeChild($l);
			}
		}

		foreach (['header', 'footer', 'nav'] as $tag) {
			$nodes = $dom->getElementsByTagName($tag);
			for ($i = $nodes->length - 1; $i >= 0; $i--) {
				$n = $nodes->item($i);
				if ($n !== null && $n->parentNode !== null) {
					$n->parentNode->removeChild($n);
				}
			}
		}

		self::removeMediaPlayerWidgetsAndSvgForLlm($dom);

		$xpath = new DOMXPath($dom);
		$styled = $xpath->query('//*[@style or @class or @id]');
		if ($styled !== false) {
			for ($i = 0; $i < $styled->length; $i++) {
				$el = $styled->item($i);
				if ($el instanceof DOMElement) {
					$el->removeAttribute('style');
					$el->removeAttribute('class');
					$el->removeAttribute('id');
				}
			}
		}

		self::removeEmptyDomElementsForLlm($dom);

		$body = $dom->getElementsByTagName('body')->item(0);
		if ($body !== null) {
			$frag = '';
			foreach ($body->childNodes as $c) {
				$frag .= $dom->saveHTML($c);
			}
			return $frag !== '' ? $frag : $dom->saveHTML();
		}
		$out = $dom->saveHTML();

		return ($out !== false && $out !== '') ? $out : self::prepareHtmlForLlmExtractionFallback($html);
	}

	/**
	 * Drop Video.js / embedded player trees (caption-settings UI text is in-body HTML) and all SVG.
	 * Must run before stripping class attributes.
	 */
	private static function removeMediaPlayerWidgetsAndSvgForLlm(DOMDocument $dom): void
	{
		$xpath = new DOMXPath($dom);
		$queries = [
			"//*[contains(@class,'vjs-wrapper')]",
			"//*[contains(@class,'video-js')]",
			"//*[@data-tracking-name='video-player']",
			"//*[contains(@class,'vjs-modal-dialog')]",
			"//*[contains(@class,'vjs-text-track-settings')]",
			"//*[contains(@class,'vjs-control-bar')]",
			"//*[contains(@class,'vjs-menu')]",
		];
		foreach ($queries as $q) {
			$nodes = $xpath->query($q);
			if ($nodes === false) {
				continue;
			}
			for ($i = $nodes->length - 1; $i >= 0; $i--) {
				$n = $nodes->item($i);
				if ($n !== null && $n->parentNode !== null) {
					$n->parentNode->removeChild($n);
				}
			}
		}

		$svgs = $dom->getElementsByTagName('svg');
		for ($i = $svgs->length - 1; $i >= 0; $i--) {
			$n = $svgs->item($i);
			if ($n !== null && $n->parentNode !== null) {
				$n->parentNode->removeChild($n);
			}
		}

		foreach (['video', 'audio', 'iframe', 'object', 'embed', 'canvas'] as $tag) {
			$els = $dom->getElementsByTagName($tag);
			for ($i = $els->length - 1; $i >= 0; $i--) {
				$n = $els->item($i);
				if ($n !== null && $n->parentNode !== null) {
					$n->parentNode->removeChild($n);
				}
			}
		}
	}

	/**
	 * Remove elements that have no non-whitespace text and no meaningful embedded media.
	 */
	private static function removeEmptyDomElementsForLlm(DOMDocument $dom): void
	{
		$voidOrMedia = ['img', 'br', 'hr', 'input', 'meta', 'link', 'area', 'base', 'col', 'embed', 'source', 'track', 'wbr', 'svg', 'canvas', 'iframe', 'picture', 'video', 'audio', 'object'];
		for ($pass = 0; $pass < 12; $pass++) {
			$xpath = new DOMXPath($dom);
			$all = $xpath->query('//*');
			if ($all === false) {
				break;
			}
			$removed = 0;
			for ($i = $all->length - 1; $i >= 0; $i--) {
				$n = $all->item($i);
				if (!$n instanceof DOMElement) {
					continue;
				}
				$name = strtolower($n->tagName);
				if (in_array($name, $voidOrMedia, true)) {
					continue;
				}
				$text = trim(preg_replace('/\s+/u', ' ', $n->textContent ?? '') ?? '');
				if ($text !== '') {
					continue;
				}
				$hasMediaChild = false;
				foreach ($n->childNodes as $child) {
					if ($child->nodeType === XML_ELEMENT_NODE && $child instanceof DOMElement) {
						$cn = strtolower($child->tagName);
						if (in_array($cn, $voidOrMedia, true)) {
							$hasMediaChild = true;
							break;
						}
					}
				}
				if ($hasMediaChild) {
					continue;
				}
				if ($n->parentNode !== null) {
					$n->parentNode->removeChild($n);
					$removed++;
				}
			}
			if ($removed === 0) {
				break;
			}
		}
	}

	/** If DOM parsing fails, use regex stripping (less accurate). */
	private static function prepareHtmlForLlmExtractionFallback(string $html): string
	{
		$html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? '';
		$html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html) ?? '';
		$html = preg_replace('#<head\b[^>]*>.*?</head>#is', '', $html) ?? '';
		$html = preg_replace('#<noscript\b[^>]*>.*?</noscript>#is', '', $html) ?? '';
		$html = preg_replace('#<(header|footer|nav)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
		$html = preg_replace('#<svg\b[^>]*>.*?</svg>#is', '', $html) ?? '';
		$html = preg_replace('#<(video|audio|iframe|object|embed|canvas)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
		$html = preg_replace('/\sstyle\s*=\s*(["\']).*?\1/iu', '', $html) ?? '';
		$html = preg_replace('/\sclass\s*=\s*(["\']).*?\1/iu', '', $html) ?? '';
		$html = preg_replace('/\sid\s*=\s*(["\']).*?\1/iu', '', $html) ?? '';
		for ($i = 0; $i < 8; $i++) {
			$next = preg_replace('#<([a-z0-9:-]+)[^>]*>\s*</\1>#iu', '', $html) ?? '';
			if ($next === $html) {
				break;
			}
			$html = $next;
		}

		return $html;
	}

	/**
	 * Stub extractor — replace with Readability or similar for real articles.
	 */
	public static function extractReadableText(string $html, string $url): string
	{
		$html = self::prepareHtmlForLlmExtraction($html);
		$text = strip_tags($html);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('/\s+/u', ' ', $text) ?? '';
		$text = trim($text);
		// Safety cap for LLM prompt size
		return mb_substr($text, 0, 12000, 'UTF-8');
	}

	/**
	 * Title + RSS snippet only (no fetch). Returns JSON with relevance_score 1–10.
	 *
	 * @return array{relevance_score?:int,_llm_usage?:array<string,int>}|null
	 */
	private static function callLlmRelevancePrefilter(
		string $apiBase,
		string $apiKey,
		string $model,
		string $title,
		string $rssPlain,
		string $prefilterSystemPrompt,
	): ?array {
		self::$lastLlmFailureDetail = '';
		$system = $prefilterSystemPrompt;
		if (self::isGeminiGenerateContentUrl($apiBase)) {
			return self::callGeminiRelevancePrefilter($apiBase, $apiKey, $system, $title, $rssPlain);
		}

		return self::callOpenAiRelevancePrefilter($apiBase, $apiKey, $model, $system, $title, $rssPlain);
	}

	/**
	 * @return array{relevance_score?:int}|null
	 */
	private static function parsePrefilterJsonFromLlmText(string $content): ?array
	{
		$json = json_decode($content, true);
		if (!is_array($json) || !isset($json['relevance_score'])) {
			self::$lastLlmFailureDetail = 'prefilter_json_invalid: ' . self::logSnippet($content, 600);

			return null;
		}

		return $json;
	}

	/**
	 * @return array{relevance_score?:int,_llm_usage?:array<string,int>}|null
	 */
	private static function callGeminiRelevancePrefilter(
		string $generateContentUrl,
		string $apiKey,
		string $systemPrompt,
		string $title,
		string $rssPlain,
	): ?array {
		$key = self::normalizeApiKey($apiKey);
		$url = trim($generateContentUrl);
		$generationConfig = [
			'temperature' => 0.3,
			'responseMimeType' => 'application/json',
			'maxOutputTokens' => 256,
			'responseJsonSchema' => self::prefilterRelevanceOnlyJsonSchema(),
		];
		$thinkingOff = self::geminiThinkingOffConfig($url);
		if ($thinkingOff !== []) {
			$generationConfig['thinkingConfig'] = $thinkingOff;
		}
		$userText = '标题：' . $title . "\n\nRSS摘要或片段：\n" . ($rssPlain !== '' ? $rssPlain : '（无摘要，仅标题。）');
		$payload = [
			'systemInstruction' => [
				'parts' => [['text' => $systemPrompt]],
			],
			'contents' => [
				[
					'role' => 'user',
					'parts' => [['text' => $userText]],
				],
			],
			'generationConfig' => $generationConfig,
		];
		$r = self::postGeminiGenerateContent($url, $key, $payload);
		if (!$r['ok']) {
			self::$lastLlmFailureDetail = $r['reason'];

			return null;
		}
		$raw = $r['body'];
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			self::$lastLlmFailureDetail = 'response_body_not_json: ' . self::logSnippet($raw, 600);

			return null;
		}
		if (isset($decoded['error'])) {
			$err = $decoded['error'];
			$msg = is_array($err) ? (string) ($err['message'] ?? json_encode($err, JSON_UNESCAPED_UNICODE)) : (string) $err;
			self::$lastLlmFailureDetail = 'gemini_error: ' . self::logSnippet($msg, 500);

			return null;
		}
		$candidates = $decoded['candidates'] ?? null;
		if (!is_array($candidates) || $candidates === []) {
			$pb = $decoded['promptFeedback'] ?? null;
			$block = is_array($pb) ? ($pb['blockReason'] ?? null) : null;
			$extra = $block !== null && $block !== '' ? ' blockReason=' . (string) $block : '';
			self::$lastLlmFailureDetail = 'no_candidates' . $extra . ': ' . self::logSnippet($raw, 600);

			return null;
		}
		$finish = $candidates[0]['finishReason'] ?? '';
		if (is_string($finish) && ($finish === 'SAFETY' || $finish === 'BLOCKLIST' || $finish === 'PROHIBITED_CONTENT')) {
			self::$lastLlmFailureDetail = 'finishReason=' . $finish . ': ' . self::logSnippet($raw, 400);

			return null;
		}
		$parts = $candidates[0]['content']['parts'] ?? null;
		if (!is_array($parts) || $parts === []) {
			self::$lastLlmFailureDetail = 'no_content_parts: ' . self::logSnippet($raw, 600);

			return null;
		}
		$text = '';
		foreach ($parts as $p) {
			if (is_array($p) && isset($p['text']) && is_string($p['text'])) {
				$text .= $p['text'];
			}
		}
		if ($text === '') {
			self::$lastLlmFailureDetail = 'empty_text_parts: ' . self::logSnippet($raw, 600);

			return null;
		}
		$parsed = self::parsePrefilterJsonFromLlmText($text);
		if ($parsed === null) {
			return null;
		}
		$usage = self::usageFromGeminiDecoded($decoded);
		if ($usage !== null) {
			$parsed['_llm_usage'] = $usage;
		}

		return $parsed;
	}

	/**
	 * Prefilter via OpenAI-compatible API (no MiniMax json_schema branch — schema targets full article JSON).
	 *
	 * @return array{relevance_score?:int,_llm_usage?:array<string,int>}|null
	 */
	private static function callOpenAiRelevancePrefilter(
		string $apiBase,
		string $apiKey,
		string $model,
		string $systemPrompt,
		string $title,
		string $rssPlain,
	): ?array {
		self::$lastLlmFailureDetail = '';
		$url = rtrim($apiBase, '/') . '/chat/completions';
		$userText = "标题：" . $title . "\n\nRSS摘要或片段：\n" . ($rssPlain !== '' ? $rssPlain : '（无摘要，仅标题。）');
		$basePayload = [
			'model' => $model,
			'messages' => [
				['role' => 'system', 'content' => $systemPrompt],
				['role' => 'user', 'content' => $userText],
			],
			'temperature' => 0.3,
			'max_tokens' => 256,
		];
		if (stripos($apiBase, 'minimax') !== false) {
			$basePayload['reasoning_split'] = true;
		}

		$failReasons = [];
		$raw = null;

		$r1 = self::postChatCompletion($url, $apiKey, array_merge($basePayload, [
			'response_format' => ['type' => 'json_object'],
		]));
		if ($r1['ok']) {
			$raw = $r1['body'];
		} else {
			$failReasons[] = 'json_object: ' . $r1['reason'];
			$r2 = self::postChatCompletion($url, $apiKey, $basePayload);
			if (!$r2['ok']) {
				$failReasons[] = 'plain: ' . $r2['reason'];
				self::$lastLlmFailureDetail = implode('; ', $failReasons);

				return null;
			}
			$raw = $r2['body'];
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			self::$lastLlmFailureDetail = 'response_body_not_json: ' . self::logSnippet($raw, 600);

			return null;
		}
		$content = $decoded['choices'][0]['message']['content'] ?? '';
		if (!is_string($content)) {
			self::$lastLlmFailureDetail = 'choices[0].message.content_not_string: ' . self::logSnippet(json_encode($decoded['choices'][0] ?? null, JSON_UNESCAPED_UNICODE), 500);

			return null;
		}
		$parsed = self::parsePrefilterJsonFromLlmText($content);
		if ($parsed === null) {
			return null;
		}
		$usage = self::usageFromOpenAiDecoded($decoded);
		if ($usage !== null) {
			$parsed['_llm_usage'] = $usage;
		}

		return $parsed;
	}

	/**
	 * Gemini `generateContent` URL (full path) or OpenAI-style `/v1` base for `/chat/completions`.
	 *
	 * @return array{summary_zh?:string,relevance_score?:int,quality_score?:int,labels?:array}|null
	 */
	public static function callLlm(
		string $apiBase,
		string $apiKey,
		string $model,
		string $systemPrompt,
		string $articlePlainText,
		string $title,
	): ?array {
		self::$lastLlmFailureDetail = '';
		if (self::isGeminiGenerateContentUrl($apiBase)) {
			return self::callGeminiGenerateContent($apiBase, $apiKey, $systemPrompt, $articlePlainText, $title);
		}
		return self::callOpenAiCompatibleChat($apiBase, $apiKey, $model, $systemPrompt, $articlePlainText, $title);
	}

	/** True when `api_base` is a full Gemini REST `…:generateContent` URL (e.g. Azure APIM gateway). */
	public static function isGeminiGenerateContentUrl(string $apiBase): bool
	{
		$t = trim($apiBase);
		return $t !== '' && str_contains($t, 'generateContent');
	}

	/** Model id from `…/models/MODEL_ID:generateContent` for logs when the Model field is left empty. */
	private static function geminiModelIdFromUrl(string $url): string
	{
		if (preg_match('#/models/([^/:]+):generateContent#', $url, $m)) {
			return $m[1];
		}
		return 'gemini';
	}

	/**
	 * Turn off / minimize thinking per Gemini API: Gemini 3 uses thinkingLevel; 2.5+ uses thinkingBudget.
	 *
	 * @return array<string, mixed>
	 */
	private static function geminiThinkingOffConfig(string $generateContentUrl): array
	{
		$mid = self::geminiModelIdFromUrl($generateContentUrl);
		if (preg_match('/gemini-3/i', $mid)) {
			return ['thinkingLevel' => 'MINIMAL'];
		}
		if (preg_match('/gemini-2\.5/i', $mid)) {
			return ['thinkingBudget' => 0];
		}

		return [];
	}

	/**
	 * OpenAI-compatible Chat Completions (DeepSeek, Ollama `/v1`, MiniMax, …).
	 *
	 * @return array{summary_zh?:string,relevance_score?:int,quality_score?:int,labels?:array}|null
	 */
	public static function callOpenAiCompatibleChat(
		string $apiBase,
		string $apiKey,
		string $model,
		string $systemPrompt,
		string $articlePlainText,
		string $title,
	): ?array {
		self::$lastLlmFailureDetail = '';
		$url = rtrim($apiBase, '/') . '/chat/completions';
		$basePayload = [
			'model' => $model,
			'messages' => [
				['role' => 'system', 'content' => $systemPrompt],
				['role' => 'user', 'content' => "Title: " . $title . "\n\nContent: " . $articlePlainText],
			],
			'temperature' => 1.0,
		];
		// MiniMax: reasoning cannot be fully disabled; split it out of `message.content` for JSON parsing.
		if (stripos($apiBase, 'minimax') !== false) {
			$basePayload['reasoning_split'] = true;
		}

		$failReasons = [];
		$raw = null;

		// MiniMax OpenAI-compatible API: prefer JSON Schema structured output (docs: response_format.type = json_schema).
		if (self::shouldTryJsonSchemaStructuredOutput($apiBase)) {
			$rSchema = self::postChatCompletion($url, $apiKey, array_merge($basePayload, [
				'response_format' => self::miniMaxJsonSchemaResponseFormat(),
			]));
			if ($rSchema['ok']) {
				$raw = $rSchema['body'];
			} else {
				$failReasons[] = 'json_schema: ' . $rSchema['reason'];
			}
		}

		if ($raw === null) {
			$r1 = self::postChatCompletion($url, $apiKey, array_merge($basePayload, [
				'response_format' => ['type' => 'json_object'],
			]));
			if ($r1['ok']) {
				$raw = $r1['body'];
			} else {
				$failReasons[] = 'json_object: ' . $r1['reason'];
				$r2 = self::postChatCompletion($url, $apiKey, $basePayload);
				if (!$r2['ok']) {
					$failReasons[] = 'plain: ' . $r2['reason'];
					self::$lastLlmFailureDetail = implode('; ', $failReasons);
					return null;
				}
				$raw = $r2['body'];
			}
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			self::$lastLlmFailureDetail = 'response_body_not_json: ' . self::logSnippet($raw, 600);
			return null;
		}
		$content = $decoded['choices'][0]['message']['content'] ?? '';
		if (!is_string($content)) {
			self::$lastLlmFailureDetail = 'choices[0].message.content_not_string: ' . self::logSnippet(json_encode($decoded['choices'][0] ?? null, JSON_UNESCAPED_UNICODE), 500);
			return null;
		}
		$parsed = self::parseAssistantJsonFromLlmText($content);
		if ($parsed === null) {
			return null;
		}
		$usage = self::usageFromOpenAiDecoded($decoded);
		if ($usage !== null) {
			$parsed['_llm_usage'] = $usage;
		}

		return $parsed;
	}

	/**
	 * Google Gemini REST {@see https://ai.google.dev/api/rest/v1beta/models.generateContent} (and proxies).
	 *
	 * @return array{summary_zh?:string,relevance_score?:int,quality_score?:int,labels?:array}|null
	 */
	private static function callGeminiGenerateContent(
		string $generateContentUrl,
		string $apiKey,
		string $systemPrompt,
		string $articlePlainText,
		string $title,
	): ?array {
		$key = self::normalizeApiKey($apiKey);
		$url = trim($generateContentUrl);
		$generationConfig = [
			'temperature' => 1.0,
			'responseMimeType' => 'application/json',
			'responseJsonSchema' => self::contentEnhancementFullPassJsonSchema(),
		];
		$thinkingOff = self::geminiThinkingOffConfig($url);
		if ($thinkingOff !== []) {
			$generationConfig['thinkingConfig'] = $thinkingOff;
		}
		$payload = [
			'systemInstruction' => [
				'parts' => [['text' => $systemPrompt]],
			],
			'contents' => [
				[
					'role' => 'user',
					'parts' => [['text' => '标题：' . $title . "\n\n正文：\n" . $articlePlainText]],
				],
			],
			'generationConfig' => $generationConfig,
		];
		$r = self::postGeminiGenerateContent($url, $key, $payload);
		if (!$r['ok']) {
			self::$lastLlmFailureDetail = $r['reason'];
			return null;
		}
		$raw = $r['body'];
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			self::$lastLlmFailureDetail = 'response_body_not_json: ' . self::logSnippet($raw, 600);
			return null;
		}
		if (isset($decoded['error'])) {
			$err = $decoded['error'];
			$msg = is_array($err) ? (string) ($err['message'] ?? json_encode($err, JSON_UNESCAPED_UNICODE)) : (string) $err;
			self::$lastLlmFailureDetail = 'gemini_error: ' . self::logSnippet($msg, 500);
			return null;
		}
		$candidates = $decoded['candidates'] ?? null;
		if (!is_array($candidates) || $candidates === []) {
			$pb = $decoded['promptFeedback'] ?? null;
			$block = is_array($pb) ? ($pb['blockReason'] ?? null) : null;
			$extra = $block !== null && $block !== '' ? ' blockReason=' . (string) $block : '';
			self::$lastLlmFailureDetail = 'no_candidates' . $extra . ': ' . self::logSnippet($raw, 600);
			return null;
		}
		$finish = $candidates[0]['finishReason'] ?? '';
		if (is_string($finish) && ($finish === 'SAFETY' || $finish === 'BLOCKLIST' || $finish === 'PROHIBITED_CONTENT')) {
			self::$lastLlmFailureDetail = 'finishReason=' . $finish . ': ' . self::logSnippet($raw, 400);
			return null;
		}
		$parts = $candidates[0]['content']['parts'] ?? null;
		if (!is_array($parts) || $parts === []) {
			self::$lastLlmFailureDetail = 'no_content_parts: ' . self::logSnippet($raw, 600);
			return null;
		}
		$text = '';
		foreach ($parts as $p) {
			if (is_array($p) && isset($p['text']) && is_string($p['text'])) {
				$text .= $p['text'];
			}
		}
		if ($text === '') {
			self::$lastLlmFailureDetail = 'empty_text_parts: ' . self::logSnippet($raw, 600);
			return null;
		}
		$parsed = self::parseAssistantJsonFromLlmText($text);
		if ($parsed === null) {
			return null;
		}
		$usage = self::usageFromGeminiDecoded($decoded);
		if ($usage !== null) {
			$parsed['_llm_usage'] = $usage;
		}

		return $parsed;
	}

	/**
	 * @return array{summary_zh?:string,relevance_score?:int,quality_score?:int,labels?:array}|null
	 */
	private static function parseAssistantJsonFromLlmText(string $content): ?array
	{
		$json = json_decode($content, true);
		if (!is_array($json) || !isset($json['summary_zh'])) {
			self::$lastLlmFailureDetail = 'assistant_content_not_json: ' . self::logSnippet($content, 600);

			return null;
		}

		return self::normalizeFullPassAssistantJson($json);
	}

	/** MiniMax documents `response_format` with `type: json_schema` (not all models may support it). */
	private static function shouldTryJsonSchemaStructuredOutput(string $apiBase): bool
	{
		return stripos($apiBase, 'minimax') !== false;
	}

	/**
	 * JSON Schema for full-pass LLM output (MiniMax `json_schema.schema`, Gemini `generationConfig.responseJsonSchema`).
	 *
	 * @return array<string, mixed>
	 */
	private static function contentEnhancementFullPassJsonSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'summary_zh' => [
					'type' => 'string',
					'description' => self::SUMMARY_ZH_FIELD_DESCRIPTION,
				],
				'relevance_score' => [
					'type' => 'integer',
					'description' => '1–10: low for propaganda/no new facts; higher for strong journalism and for topics the reader cares about (per prompt).',
				],
				'labels' => [
					'type' => 'array',
					'items' => ['type' => 'string'],
					'description' => 'Freeform semantic tags; lowercase a-z only; can have multiple labels.',
				],
			],
			'required' => ['summary_zh', 'relevance_score', 'labels'],
		];
	}

	/**
	 * Prefilter-only JSON (Gemini `generationConfig.responseJsonSchema` / OpenAI `json_object` shape).
	 *
	 * @return array<string, mixed>
	 */
	private static function prefilterRelevanceOnlyJsonSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'relevance_score' => [
					'type' => 'integer',
					'description' => '1–10 estimated relevance from title + RSS snippet only (same scale as full pass).',
				],
			],
			'required' => ['relevance_score'],
		];
	}

	/**
	 * @return array{type: string, json_schema: array{name: string, description: string, schema: array<string, mixed>}}
	 */
	private static function miniMaxJsonSchemaResponseFormat(): array
	{
		return [
			'type' => 'json_schema',
			'json_schema' => [
				'name' => 'content_enhancement',
				'description' => 'relevance_score: 1–10 combines objective news value and subjective reader interest (customize via system prompt).',
				'schema' => self::contentEnhancementFullPassJsonSchema(),
			],
		];
	}

	/**
	 * Trim whitespace, strip BOM, remove line breaks (common copy/paste issues from portals).
	 */
	public static function normalizeApiKey(string $key): string
	{
		$key = trim($key);
		$key = preg_replace('/^\xEF\xBB\xBF/', '', $key) ?? $key;
		$key = str_replace(["\r\n", "\r", "\n", "\t"], '', $key);

		return trim($key);
	}

	/** One-line safe excerpt for logs (no newlines). */
	private static function logSnippet(?string $s, int $max = 400): string
	{
		if ($s === null || $s === '') {
			return '(empty)';
		}
		$t = preg_replace('/\s+/u', ' ', $s) ?? $s;
		$t = mb_substr($t, 0, $max, 'UTF-8');
		return $t . (mb_strlen($s, 'UTF-8') > $max ? '…' : '');
	}

	/**
	 * @param array<string,mixed> $llm
	 * @return array<string,int>|null
	 */
	private static function popLlmUsageFromResult(array &$llm): ?array
	{
		if (!isset($llm['_llm_usage']) || !is_array($llm['_llm_usage'])) {
			return null;
		}
		/** @var array<string,int> $u */
		$u = $llm['_llm_usage'];
		unset($llm['_llm_usage']);

		return $u;
	}

	/**
	 * @return array<string,int>|null
	 */
	private static function usageFromGeminiDecoded(array $decoded): ?array
	{
		$um = $decoded['usageMetadata'] ?? null;
		if (!is_array($um)) {
			return null;
		}
		$out = [
			'prompt_tokens' => (int) ($um['promptTokenCount'] ?? 0),
			'completion_tokens' => (int) ($um['candidatesTokenCount'] ?? 0),
			'total_tokens' => (int) ($um['totalTokenCount'] ?? 0),
		];
		if (isset($um['cachedContentTokenCount'])) {
			$out['cached_content_tokens'] = (int) $um['cachedContentTokenCount'];
		}

		return $out;
	}

	/**
	 * @return array<string,int>|null
	 */
	private static function usageFromOpenAiDecoded(array $decoded): ?array
	{
		$u = $decoded['usage'] ?? null;
		if (!is_array($u)) {
			return null;
		}
		$out = [
			'prompt_tokens' => (int) ($u['prompt_tokens'] ?? 0),
			'completion_tokens' => (int) ($u['completion_tokens'] ?? 0),
			'total_tokens' => (int) ($u['total_tokens'] ?? 0),
		];

		return $out;
	}

	/**
	 * Auth for Gemini and proxies.
	 *
	 * - **Google** (`googleapis.com`): `x-goog-api-key` only.
	 * - **Azure API Management** (and similar): `Ocp-Apim-Subscription-Key` only. Do not add `subscription-key` to the URL
	 *   (APIM may forward the query to Google, which rejects unknown parameters). Do **not** send `Authorization: Bearer`
	 *   with the subscription key — APIM may forward it to Google, which expects OAuth (`ACCESS_TOKEN_TYPE_UNSUPPORTED`).
	 *
	 * @return list<string>
	 */
	private static function geminiHttpHeaders(string $apiKey, string $url): array
	{
		$host = parse_url($url, PHP_URL_HOST);
		$host = is_string($host) ? strtolower($host) : '';
		if (str_contains($host, 'googleapis.com')) {
			return ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey];
		}
		if ($apiKey === '') {
			return ['Content-Type: application/json'];
		}
		// APIM subscription key only — no Bearer (see docblock).
		return [
			'Content-Type: application/json',
			'Ocp-Apim-Subscription-Key: ' . $apiKey,
		];
	}

	/**
	 * @return array{ok: true, body: string}|array{ok: false, reason: string, http_code: int}
	 */
	private static function postGeminiGenerateContentOnce(string $url, string $apiKey, array $payload): array
	{
		$ch = curl_init($url);
		if ($ch === false) {
			return ['ok' => false, 'reason' => 'curl_init_failed', 'http_code' => 0];
		}
		$body = json_encode($payload, JSON_UNESCAPED_UNICODE);
		if ($body === false) {
			curl_close($ch);
			return ['ok' => false, 'reason' => 'json_encode_payload_failed', 'http_code' => 0];
		}
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_HTTPHEADER => self::geminiHttpHeaders($apiKey, $url),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_TIMEOUT => 120,
		]);
		$raw = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErr = curl_error($ch);
		curl_close($ch);
		if ($raw === false) {
			return ['ok' => false, 'reason' => 'curl_exec_failed: ' . ($curlErr !== '' ? self::logSnippet($curlErr, 200) : 'unknown'), 'http_code' => 0];
		}
		if ($code >= 400 || $code === 0) {
			return ['ok' => false, 'reason' => sprintf(
				'http_%d%s api_body=%s',
				$code,
				$curlErr !== '' ? ' curl=' . self::logSnippet($curlErr, 120) : '',
				self::logSnippet(is_string($raw) ? $raw : null, 600),
			), 'http_code' => $code];
		}
		if (!is_string($raw) || $raw === '') {
			return ['ok' => false, 'reason' => 'empty_response_body_http_' . $code, 'http_code' => $code];
		}
		return ['ok' => true, 'body' => $raw];
	}

	/**
	 * @return array{ok: true, body: string}|array{ok: false, reason: string}
	 */
	private static function postGeminiGenerateContent(string $url, string $apiKey, array $payload): array
	{
		$r = self::postGeminiGenerateContentOnce($url, $apiKey, $payload);
		if ($r['ok']) {
			return ['ok' => true, 'body' => $r['body']];
		}
		$code = (int) ($r['http_code'] ?? 0);
		if ($code >= 500 && $code < 600) {
			Minz_Log::warning(sprintf(
				'ContentEnhancement: LLM Gemini HTTP %d, retrying once after 5s url=%s',
				$code,
				self::logSnippet($url, 200),
			));
			sleep(5);
			$r = self::postGeminiGenerateContentOnce($url, $apiKey, $payload);
		}
		if ($r['ok']) {
			return ['ok' => true, 'body' => $r['body']];
		}
		return ['ok' => false, 'reason' => $r['reason']];
	}

	/**
	 * @return array{ok: true, body: string}|array{ok: false, reason: string, http_code: int}
	 */
	private static function postChatCompletionOnce(string $url, string $apiKey, array $payload): array
	{
		$ch = curl_init($url);
		if ($ch === false) {
			return ['ok' => false, 'reason' => 'curl_init_failed', 'http_code' => 0];
		}
		$body = json_encode($payload, JSON_UNESCAPED_UNICODE);
		if ($body === false) {
			curl_close($ch);
			return ['ok' => false, 'reason' => 'json_encode_payload_failed', 'http_code' => 0];
		}
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'Authorization: Bearer ' . $apiKey,
			],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_TIMEOUT => 120,
		]);
		$raw = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErr = curl_error($ch);
		curl_close($ch);
		if ($raw === false) {
			return ['ok' => false, 'reason' => 'curl_exec_failed: ' . ($curlErr !== '' ? self::logSnippet($curlErr, 200) : 'unknown'), 'http_code' => 0];
		}
		if ($code >= 400 || $code === 0) {
			return ['ok' => false, 'reason' => sprintf(
				'http_%d%s api_body=%s',
				$code,
				$curlErr !== '' ? ' curl=' . self::logSnippet($curlErr, 120) : '',
				self::logSnippet(is_string($raw) ? $raw : null, 600),
			), 'http_code' => $code];
		}
		if (!is_string($raw) || $raw === '') {
			return ['ok' => false, 'reason' => 'empty_response_body_http_' . $code, 'http_code' => $code];
		}
		return ['ok' => true, 'body' => $raw];
	}

	/**
	 * @return array{ok: true, body: string}|array{ok: false, reason: string}
	 */
	private static function postChatCompletion(string $url, string $apiKey, array $payload): array
	{
		$r = self::postChatCompletionOnce($url, $apiKey, $payload);
		if ($r['ok']) {
			return ['ok' => true, 'body' => $r['body']];
		}
		$code = (int) ($r['http_code'] ?? 0);
		if ($code >= 500 && $code < 600) {
			Minz_Log::warning(sprintf(
				'ContentEnhancement: LLM OpenAI-compatible HTTP %d, retrying once after 5s url=%s',
				$code,
				self::logSnippet($url, 200),
			));
			sleep(5);
			$r = self::postChatCompletionOnce($url, $apiKey, $payload);
		}
		if ($r['ok']) {
			return ['ok' => true, 'body' => $r['body']];
		}
		return ['ok' => false, 'reason' => $r['reason']];
	}
}
