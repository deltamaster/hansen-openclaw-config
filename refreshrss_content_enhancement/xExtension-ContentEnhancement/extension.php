<?php

declare(strict_types=1);

// Must load before install() — FreshRSS calls install() before init() when enabling the extension.
require_once __DIR__ . '/Processor.php';

/**
 * FreshRSS extension: LLM content enhancement before entries are inserted.
 *
 * Hook: entry_before_insert ({@see Minz_HookType::EntryBeforeInsert} — pass as string for older registerHook signatures).
 * Docs: https://freshrss.github.io/FreshRSS/en/developers/03_Backend/05_Extensions.html
 */
final class ContentEnhancementExtension extends Minz_Extension
{
	/** Log once per HTTP request when disabled so the log is not spammed per entry. */
	private static bool $loggedDisabledThisRequest = false;

	#[\Override]
	public function init(): void
	{
		parent::init();
		// String name required: some FreshRSS builds type registerHook(string) only (not Minz_HookType enum).
		$this->registerHook('entry_before_insert', [$this, 'onEntryBeforeInsert']);
		// Runs once per feed on every refresh — unlike entry_before_insert, which only runs when there are new/updated articles.
		$this->registerHook('feed_before_actualize', [$this, 'onFeedBeforeActualize']);
		// After `_entrytmp` → `_entry` commit, ids are final; apply LLM labels to user labels (tags).
		$this->registerHook('entry_before_display', [$this, 'onEntryBeforeDisplay']);
	}

	/**
	 * FreshRSS inserts new rows into `_entrytmp` first; they are moved to `_entry` when
	 * {@see FreshRSS_feed_Controller::commitNewEntries()} runs after {@see FreshRSS_feed_Controller::actualizeFeeds()}.
	 * Slow `entry_before_insert` (LLM) can hit `max_execution_time`, so the request exits before that commit:
	 * you get `ContentEnhancement: ok` in the log but articles stay in `entrytmp` and never appear in the UI.
	 * Committing at the start of each feed flushes pending tmp from earlier feeds in the same run or from a crashed previous run.
	 */
	public function onFeedBeforeActualize(FreshRSS_Feed $feed): ?FreshRSS_Feed
	{
		if (class_exists('FreshRSS_feed_Controller')) {
			FreshRSS_feed_Controller::commitNewEntries();
		}
		if (!$this->getSystemConfigurationValue('enabled')) {
			return $feed;
		}
		$name = $feed->name();
		$name = function_exists('mb_substr')
			? mb_substr($name, 0, 100, 'UTF-8')
			: substr($name, 0, 100);
		Minz_Log::warning(sprintf(
			'ContentEnhancement: feed_before_actualize feed_id=%d name="%s" url=%s',
			$feed->id(),
			$name,
			$feed->url(false),
		));
		return $feed;
	}

	/**
	 * Called for each new entry before it is written to the database.
	 * Return the (possibly modified) entry, or `null` to **discard** the insert.
	 */
	/**
	 * Apply LLM labels to FreshRSS user labels when the article is rendered (entry is in `_entry`).
	 */
	public function onEntryBeforeDisplay(FreshRSS_Entry $entry): ?FreshRSS_Entry
	{
		$applyLabels = $this->getSystemConfigurationValue('apply_freshrss_labels');
		if ($applyLabels === null) {
			$applyLabels = true;
		}
		if (!$this->getSystemConfigurationValue('enabled') || !$applyLabels) {
			return $entry;
		}
		try {
			return ContentEnhancement_Processor::syncFreshRssUserLabelsFromMetadata($entry);
		} catch (Throwable $e) {
			Minz_Log::warning('ContentEnhancement: entry_before_display — ' . $e->getMessage());
			return $entry;
		}
	}

	public function onEntryBeforeInsert(FreshRSS_Entry $entry): ?FreshRSS_Entry
	{
		if (!$this->getSystemConfigurationValue('enabled')) {
			if (!self::$loggedDisabledThisRequest) {
				self::$loggedDisabledThisRequest = true;
				Minz_Log::warning('ContentEnhancement: extension is disabled — enable it under Administration → Extensions → Content Enhancement.');
			}
			return $entry;
		}

		try {
			return ContentEnhancement_Processor::process($entry, $this);
		} catch (Throwable $e) {
			Minz_Log::warning('ContentEnhancement: ' . $e->getMessage());
			return $entry;
		}
	}

	#[\Override]
	public function install(): bool|string
	{
		if (parent::install()) {
			$this->setSystemConfiguration([
				'enabled' => false,
				'api_base' => 'https://jp-gw2.azure-api.net/gemini/models/gemini-3.1-flash-lite-preview:generateContent',
				'api_key' => '',
				'model' => 'gemini-3.1-flash-lite-preview',
				'min_quality' => 4,
				'mark_low_quality_read' => true,
				'discard_below_threshold' => false,
				'apply_freshrss_labels' => true,
				'system_prompt' => ContentEnhancement_Processor::defaultSystemPrompt(),
			]);
			return true;
		}
		return 'Could not initialize Content Enhancement extension';
	}

	#[\Override]
	public function uninstall(): bool|string
	{
		$this->removeSystemConfiguration();
		return parent::uninstall() ? true : 'Could not uninstall extension';
	}

	#[\Override]
	public function handleConfigureAction(): bool
	{
		parent::handleConfigureAction();
		if (Minz_Request::isPost()) {
			$newKey = Minz_Request::param('api_key', '');
			$apiKey = is_string($newKey) && trim($newKey) !== ''
				? ContentEnhancement_Processor::normalizeApiKey(trim($newKey))
				: (string) $this->getSystemConfigurationValue('api_key', '');
			if ($apiKey !== '' && self::looksLikeInvalidApiKeyPlaceholder($apiKey)) {
				$apiKey = (string) $this->getSystemConfigurationValue('api_key', '');
			}
			$this->setSystemConfiguration([
				'enabled' => Minz_Request::param('enabled') === '1',
				'api_base' => trim((string) Minz_Request::param('api_base', '')),
				'api_key' => $apiKey,
				'model' => trim((string) Minz_Request::param('model', '')),
				'min_quality' => (int) Minz_Request::param('min_quality', 4),
				'mark_low_quality_read' => Minz_Request::param('mark_low_quality_read') === '1',
				'discard_below_threshold' => Minz_Request::param('discard_below_threshold') === '1',
				'apply_freshrss_labels' => Minz_Request::param('apply_freshrss_labels') === '1',
				'system_prompt' => ContentEnhancement_Processor::normalizeSystemPrompt((string) Minz_Request::param('system_prompt', '')),
			]);
			Minz_Request::good(_t('gen.action.done'));
		}
		return true;
	}

	/** Reject error-page text accidentally saved as the API key (e.g. CSRF failure). */
	private static function looksLikeInvalidApiKeyPlaceholder(string $key): bool
	{
		if (stripos($key, 'csrf') !== false && stripos($key, 'permission') !== false) {
			return true;
		}
		if (stripos($key, "don't have permission") !== false || stripos($key, 'do not have permission') !== false) {
			return true;
		}
		if (stripos($key, 'access this page') !== false && stripos($key, 'permission') !== false) {
			return true;
		}
		return false;
	}
}
