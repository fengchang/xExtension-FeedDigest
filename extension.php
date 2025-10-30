<?php
declare(strict_types=1);

/**
 * Feed Digest Extension
 *
 * Automatically summarizes newly retrieved RSS articles using LLM APIs (OpenAI-compatible).
 * Processes articles during feed updates, creates combined summary articles, and marks originals as read.
 */
final class FeedDigestExtension extends Minz_Extension {

	/**
	 * Initialize the extension and register hooks
	 */
	#[\Override]
	public function init(): void {
		parent::init();

		$this->registerHook('freshrss_user_maintenance', [$this, 'handleUserMaintenance']);
		$this->registerHook('feed_before_insert', [$this, 'handleFeedBeforeInsert']);
		$this->registerHook('freshrss_init', [$this, 'handleFreshRSSInit']);
		$this->registerTranslates();
		$this->registerViews();
	}

	/**
	 * Hook to save per-feed setting when NEW feed is created
	 */
	public function handleFeedBeforeInsert(FreshRSS_Feed $feed): FreshRSS_Feed {
		// Check if the feed form was submitted with our settings
		$enabled = Minz_Request::paramTernary('feed_digest_enabled');
		$feed->_attribute('feed_digest_enabled', $enabled);

		$batchSize = Minz_Request::paramInt('feed_digest_batch_size');
		$feed->_attribute('feed_digest_batch_size', $batchSize > 0 ? $batchSize : 10);

		return $feed;
	}

	/**
	 * Hook to handle feed update form submissions
	 */
	public function handleFreshRSSInit(): void {
		// Check if we're on a feed update POST request
		if (Minz_Request::controllerName() === 'subscription' &&
		    Minz_Request::actionName() === 'feed' &&
		    Minz_Request::isPost()) {

			$feedId = Minz_Request::paramInt('id');

			if ($feedId > 0) {
				// Get the feed
				$feedDAO = FreshRSS_Factory::createFeedDao();
				$feed = $feedDAO->searchById($feedId);

				if ($feed !== null) {
					// Read our form fields and save them
					$enabled = Minz_Request::paramTernary('feed_digest_enabled');
					$feed->_attribute('feed_digest_enabled', $enabled);

					$batchSize = Minz_Request::paramInt('feed_digest_batch_size');
					$feed->_attribute('feed_digest_batch_size', $batchSize > 0 ? $batchSize : 10);

					// Update the feed with the new attributes
					$feedDAO->updateFeed($feedId, ['attributes' => $feed->attributes()]);

					Minz_Log::notice("Feed Digest: Settings saved for feed {$feed->name()}");
				} else {
					Minz_Log::warning("Feed Digest: Feed not found with ID {$feedId}");
				}
			}
		}
	}

	/**
	 * Main hook handler - called after feed updates during cron/batch refresh
	 */
	public function handleUserMaintenance(): void {
		try {
			Minz_Log::warning('Feed Digest: Maintenance hook triggered');

			// Get configuration
			$apiEndpoint = $this->getSystemConfigurationValue('api_endpoint', 'https://api.openai.com/v1');
			$secretKey = $this->getSystemConfigurationValue('secret_key', '');
			$model = $this->getSystemConfigurationValue('model', 'gpt-5-nano');
			$destLanguage = $this->getSystemConfigurationValue('dest_language', 'English');
			$maxContentLength = (int)$this->getSystemConfigurationValue('max_content_length', 4000);

			// Skip if API not configured
			if (empty($secretKey)) {
				Minz_Log::warning('Feed Digest: Skipping - no API key configured');
				return;
			}

			// Get all feeds
			$feedDAO = FreshRSS_Factory::createFeedDao();
			$feeds = $feedDAO->listFeeds();

			// Process each feed with summarization enabled
			$enabledCount = 0;
			foreach ($feeds as $feed) {
				if (!$feed->attributeBoolean('feed_digest_enabled')) {
					continue;
				}
				$enabledCount++;

				$this->processFeed($feed, $apiEndpoint, $secretKey, $model, $destLanguage, $maxContentLength);
			}

			if ($enabledCount === 0) {
				Minz_Log::warning('Feed Digest: No feeds have summarization enabled');
			}
		} catch (Exception $e) {
			Minz_Log::error('Feed Digest error: ' . $e->getMessage());
		}
	}

	/**
	 * Process a single feed: get unread articles, summarize in batches, and mark as read
	 */
	private function processFeed(FreshRSS_Feed $feed, string $apiEndpoint, string $secretKey,
	                             string $model, string $destLanguage, int $maxContentLength): void {
		try {
			$entryDAO = FreshRSS_Factory::createEntryDao();

			// Get batch size for this feed (default 10)
			$batchSize = $feed->attributeInt('feed_digest_batch_size') ?: 10;

			// Fetch plenty of articles (max 200)
			$fetchLimit = 200;

			// Get unread articles for this feed
			$entries = iterator_to_array(
				$entryDAO->listWhere('f', $feed->id(), FreshRSS_Entry::STATE_NOT_READ,
				                    order: 'ASC', limit: $fetchLimit)
			);

			// Skip if no unread articles
			if (empty($entries)) {
				return;
			}

			// Filter out summary articles (those we previously created)
			$nonSummaryEntries = [];

			foreach ($entries as $entry) {
				if (!$this->isSummaryArticle($entry)) {
					$nonSummaryEntries[] = $entry;
				}
			}

			// Filter articles: separate worth summarizing vs. too short/image-only
			$worthSummarizing = [];
			$skippedArticles = [];

			foreach ($nonSummaryEntries as $entry) {
				$skipReason = $this->getSkipReason($entry);
				if ($skipReason === null) {
					$worthSummarizing[] = $entry;
				} else {
					$skippedArticles[] = array('entry' => $entry, 'reason' => $skipReason);
				}
			}

			// Add explanatory notes to skipped articles (only if not already added)
			foreach ($skippedArticles as $skipped) {
				$entry = $skipped['entry'];
				$reason = $skipped['reason'];
				$originalContent = $entry->content();

				// Check if note was already added to avoid duplicates on subsequent updates
				if (strpos($originalContent, 'Feed Digest:</strong> This article was not summarized') === false) {
					$note = '<div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 10px; margin-bottom: 15px;">'
					      . '<strong>Feed Digest:</strong> This article was not summarized. Reason: ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8')
					      . '</div>';

					$newContent = $note . $originalContent;
					$entry->_content($newContent);
					$entry->_hash(md5($newContent)); // Update hash since content changed
					$entry->_lastSeen(time()); // Update lastSeen timestamp

					$entryDAO->updateEntry($entry->toArray());
				}
			}

			$totalWorthy = count($worthSummarizing);
			$totalImageOnly = count($skippedArticles);

			// Check if we have enough articles to process at least one batch
			if ($totalWorthy < $batchSize) {
				Minz_Log::warning("Feed Digest: Skipping {$feed->name()} - only {$totalWorthy} articles worth summarizing (batch size: {$batchSize})");
				return; // Don't mark as read, wait for more articles
			}

			// Process in batches
			$batchNumber = 0;
			$totalProcessed = 0;

			while (count($worthSummarizing) >= $batchSize) {
				$batchNumber++;

				// Take first $batchSize articles
				$batch = array_slice($worthSummarizing, 0, $batchSize);
				$worthSummarizing = array_slice($worthSummarizing, $batchSize);

				try {
					Minz_Log::notice("Feed Digest: Processing {$feed->name()} batch #{$batchNumber} - {$batchSize} articles");

					// Call LLM API to summarize this batch
					$summaries = $this->callLLMAPI($feed, $batch, $apiEndpoint, $secretKey,
					                               $model, $destLanguage, $maxContentLength);

					// Create and insert synthetic summary article for this batch
					$this->createSummaryArticle($feed, $batch, $summaries);

					// Mark ONLY this batch as read
					$batchEntryIds = array_map(fn($entry) => $entry->id(), $batch);
					$entryDAO->markRead($batchEntryIds, true);

					$totalProcessed += count($batch);

					Minz_Log::notice("Feed Digest: Successfully processed {$feed->name()} batch #{$batchNumber}");

				} catch (Exception $e) {
					Minz_Log::error("Feed Digest: Batch #{$batchNumber} failed for {$feed->name()}: " . $e->getMessage());
					// This batch failed, but continue with next batch
					// Failed articles stay unread and will be retried next time
				}
			}

			$remainingWorthy = count($worthSummarizing);
			$totalRemaining = $remainingWorthy + $totalImageOnly;

			Minz_Log::notice("Feed Digest: {$feed->name()} complete - processed {$totalProcessed} articles in {$batchNumber} batches, {$totalRemaining} left unread ({$remainingWorthy} waiting for batch, {$totalImageOnly} image-only)");

		} catch (Exception $e) {
			Minz_Log::error("Feed Digest error for feed {$feed->name()}: " . $e->getMessage());
			// Articles stay unread - will retry next time
		}
	}

	/**
	 * Check if an article is a summary article (one we created)
	 */
	private function isSummaryArticle(FreshRSS_Entry $entry): bool {
		// Check GUID pattern
		if (str_starts_with($entry->guid(), 'llm-summary-')) {
			return true;
		}

		// Check title pattern
		if (str_starts_with($entry->title(), '[Summary]')) {
			return true;
		}

		return false;
	}

	/**
	 * Check if an article is worth summarizing (not too short, not image-only)
	 */
	private function isWorthSummarizing(FreshRSS_Entry $entry): bool {
		return $this->getSkipReason($entry) === null;
	}

	/**
	 * Get the reason why an article should be skipped, or null if worth summarizing
	 *
	 * @return string|null Reason for skipping, or null if article should be summarized
	 */
	private function getSkipReason(FreshRSS_Entry $entry) {
		$content = $entry->content();

		// Strip HTML tags to get plain text
		$plainText = strip_tags($content);
		$plainText = html_entity_decode($plainText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$plainText = trim(preg_replace('/\s+/', ' ', $plainText));

		// Minimum length threshold: 100 characters of actual text
		if (strlen($plainText) < 100) {
			return 'Article is too short (less than 100 characters)';
		}

		// Check if content is mostly images (more than 70% of content is image tags)
		$imageTagCount = preg_match_all('/<img[^>]*>/i', $content);
		$totalHtmlLength = strlen($content);
		$textLength = strlen($plainText);

		// If the text is less than 30% of the HTML, it's probably just images/markup
		if ($totalHtmlLength > 0 && ($textLength / $totalHtmlLength) < 0.3) {
			return 'Article is mostly images with minimal text';
		}

		// Check if it's just a single image with minimal text
		if ($imageTagCount > 0 && strlen($plainText) < 200) {
			return 'Article contains images but has insufficient text (less than 200 characters)';
		}

		return null; // Article is worth summarizing
	}

	/**
	 * Call OpenAI-compatible API to summarize all articles in one batch request
	 *
	 * @param FreshRSS_Feed $feed The feed being processed
	 * @param array<FreshRSS_Entry> $entries Articles to summarize
	 * @return array<array{title: string, summary: string}> Summaries with translated titles
	 */
	private function callLLMAPI(FreshRSS_Feed $feed, array $entries, string $apiEndpoint,
	                            string $secretKey, string $model, string $destLanguage, int $maxContentLength): array {
		$url = rtrim($apiEndpoint, '/') . '/chat/completions';

		// Build system prompt with feed context
		$systemPrompt = $this->buildSystemPrompt($feed, $destLanguage);

		// Build user prompt with all articles
		$userPrompt = $this->buildArticlesPrompt($entries, $maxContentLength);

		// Prepare API request
		$payload = [
			'model' => $model,
			'messages' => [
				['role' => 'system', 'content' => $systemPrompt],
				['role' => 'user', 'content' => $userPrompt]
			],
		];

		$payloadJson = json_encode($payload);

		// Make API call
		$ch = curl_init($url);
		if ($ch === false) {
			throw new Exception('Failed to initialize cURL');
		}

		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'Authorization: Bearer ' . $secretKey,
			],
			CURLOPT_POSTFIELDS => $payloadJson,
			CURLOPT_TIMEOUT => 180, // 3 minutes timeout for large batches
			CURLOPT_CONNECTTIMEOUT => 30,
		]);

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);

		if ($response === false || !empty($error)) {
			throw new Exception("API call failed: $error");
		}

		if ($httpCode !== 200) {
			throw new Exception("API returned HTTP $httpCode: $response");
		}

		// Parse response
		$data = json_decode($response, true);
		if (!isset($data['choices'][0]['message']['content'])) {
			throw new Exception("Invalid API response format");
		}

		$content = $data['choices'][0]['message']['content'];

		// Parse the structured response
		return $this->parseLLMResponse($content, count($entries));
	}

	/**
	 * Build system prompt with feed context
	 */
	private function buildSystemPrompt(FreshRSS_Feed $feed, string $destLanguage): string {
		$feedTitle = htmlspecialchars($feed->name(), ENT_QUOTES, 'UTF-8');
		$feedDesc = htmlspecialchars($feed->description(), ENT_QUOTES, 'UTF-8');

		return <<<PROMPT
You are summarizing articles from the RSS feed:
- Feed Title: $feedTitle
- Feed Description: $feedDesc
- Target Language: $destLanguage

For each article provided, you must:
1. Summarize the article concisely in $destLanguage (2-4 sentences)
2. Translate the title to $destLanguage if it's not already in that language

Respond with a JSON array where each element has:
- "title": the translated title in $destLanguage
- "summary": a concise summary in $destLanguage

Example format:
[
  {"title": "Translated Title 1", "summary": "Summary of article 1 in $destLanguage..."},
  {"title": "Translated Title 2", "summary": "Summary of article 2 in $destLanguage..."}
]

IMPORTANT: Return ONLY the JSON array, no other text.
PROMPT;
	}

	/**
	 * Build user prompt with all articles to summarize
	 */
	private function buildArticlesPrompt(array $entries, int $maxContentLength): string {
		$articlesJson = [];

		foreach ($entries as $index => $entry) {
			$content = $entry->content();

			// Truncate if too long
			if (strlen($content) > $maxContentLength) {
				$content = substr($content, 0, $maxContentLength) . '... [truncated]';
			}

			// Strip HTML tags for cleaner content
			$content = strip_tags($content);
			$content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$content = trim(preg_replace('/\s+/', ' ', $content));

			// Fix UTF-8 encoding issues that prevent JSON encoding
			// Convert to UTF-8 and remove any invalid sequences
			$content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');

			// Also clean the title
			$title = $entry->title();
			$title = mb_convert_encoding($title, 'UTF-8', 'UTF-8');

			$articlesJson[] = [
				'index' => $index + 1,
				'title' => $title,
				'content' => $content,
			];
		}

		$jsonEncoded = json_encode($articlesJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

		if ($jsonEncoded === false) {
			$jsonError = json_last_error_msg();
			throw new Exception("Failed to encode articles as JSON: " . $jsonError);
		}

		return "Articles to summarize:\n\n" . $jsonEncoded;
	}

	/**
	 * Parse LLM response into structured summaries
	 *
	 * @return array<array{title: string, summary: string}>
	 */
	private function parseLLMResponse(string $content, int $expectedCount): array {
		// Try to extract JSON from response (in case LLM added extra text)
		if (preg_match('/\[.*\]/s', $content, $matches)) {
			$content = $matches[0];
		}

		$summaries = json_decode($content, true);

		if (!is_array($summaries) || count($summaries) !== $expectedCount) {
			throw new Exception("Expected $expectedCount summaries, got " . (is_array($summaries) ? count($summaries) : 0));
		}

		// Validate structure
		foreach ($summaries as $summary) {
			if (!isset($summary['title']) || !isset($summary['summary'])) {
				throw new Exception("Invalid summary structure in LLM response");
			}
		}

		return $summaries;
	}

	/**
	 * Create and insert synthetic summary article
	 */
	private function createSummaryArticle(FreshRSS_Feed $feed, array $entries, array $summaries): void {
		$entryDAO = FreshRSS_Factory::createEntryDao();

		// Build summary content
		$content = $this->formatSummaryContent($entries, $summaries);

		// Generate summary article metadata
		$timestamp = time();
		$title = '[Summary] ' . $feed->name() . ' - ' . date('Y-m-d H:i:s', $timestamp);
		$guid = 'llm-summary-' . $feed->id() . '-' . $timestamp;

		// Use first article's link or feed website
		$link = !empty($entries) ? $entries[0]->link() : $feed->website();

		// Prepare entry data
		$values = [
			'id' => uTimeString(),
			'guid' => $guid,
			'title' => $title,
			'author' => 'AI Summary',
			'content' => $content,
			'link' => $link,
			'date' => $timestamp,
			'lastSeen' => $timestamp,
			'hash' => md5($content),
			'is_read' => false,
			'is_favorite' => false,
			'id_feed' => $feed->id(),
			'tags' => '',
		];

		$entryDAO->addEntry($values, false);
	}

	/**
	 * Format summary content as HTML
	 */
	private function formatSummaryContent(array $entries, array $summaries): string {
		$html = '<div class="llm-summary">';

		foreach ($entries as $index => $entry) {
			$summary = $summaries[$index];

			$title = htmlspecialchars($summary['title'], ENT_QUOTES, 'UTF-8');
			$summaryText = htmlspecialchars($summary['summary'], ENT_QUOTES, 'UTF-8');
			$link = htmlspecialchars($entry->link(), ENT_QUOTES, 'UTF-8');

			$html .= '<div class="summary-item">';
			$html .= '<h3><a href="' . $link . '" target="_blank">' . $title . '</a></h3>';
			$html .= '<p>' . $summaryText . '</p>';
			$html .= '</div>';
			$html .= '<hr>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Handle configuration form submission
	 */
	#[\Override]
	public function handleConfigureAction(): void {
		parent::handleConfigureAction();
		$this->registerTranslates();

		// Initialize test result properties on extension object itself
		$this->test_result = null;
		$this->test_success = null;

		if (Minz_Request::isPost()) {
			$config = [
				'api_endpoint' => Minz_Request::paramString('api_endpoint') ?: 'https://api.openai.com/v1',
				'secret_key' => Minz_Request::paramString('secret_key'),
				'model' => Minz_Request::paramString('model') ?: 'gpt-5-nano',
				'dest_language' => Minz_Request::paramString('dest_language') ?: 'English',
				'max_content_length' => max(500, min(16000, Minz_Request::paramInt('max_content_length') ?: 4000)),
			];

			// Handle test API button - don't save, just test
			if (Minz_Request::paramString('test_api') === '1') {
				$result = $this->testAPIConnection($config);
				$this->test_result = $result['message'];
				$this->test_success = $result['success'];
			} else {
				// Regular submit - save configuration
				$this->setSystemConfiguration($config);
			}
		}
	}

	/**
	 * Test API connection with a simple prompt
	 *
	 * @return array{success: bool, message: string}
	 */
	private function testAPIConnection(array $config): array {
		try {
			$url = rtrim($config['api_endpoint'], '/') . '/chat/completions';

			// Create a test article to summarize
			$testFeed = new stdClass();
			$testFeed->name = 'Test Feed';
			$testFeed->description = 'A test RSS feed';

			$systemPrompt = <<<PROMPT
You are testing an API connection. Summarize the following article concisely in {$config['dest_language']}.
Respond with a JSON object: {"title": "translated title", "summary": "your summary"}
PROMPT;

			$userPrompt = <<<PROMPT
Article to summarize:
Title: "New AI Model Released"
Content: "A new artificial intelligence model was released today by researchers. The model shows significant improvements in natural language understanding and generation tasks. It is now available for testing."
PROMPT;

			$payload = [
				'model' => $config['model'],
				'messages' => [
					['role' => 'system', 'content' => $systemPrompt],
					['role' => 'user', 'content' => $userPrompt]
				],
			];

			$ch = curl_init($url);
			if ($ch === false) {
				throw new Exception('Failed to initialize cURL');
			}

			curl_setopt_array($ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_POST => true,
				CURLOPT_HTTPHEADER => [
					'Content-Type: application/json',
					'Authorization: Bearer ' . $config['secret_key'],
				],
				CURLOPT_POSTFIELDS => json_encode($payload),
				CURLOPT_TIMEOUT => 30,
			]);

			$response = curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$error = curl_error($ch);
			curl_close($ch);

			if ($response === false || !empty($error)) {
				throw new Exception("Connection failed: $error");
			}

			if ($httpCode !== 200) {
				$data = json_decode($response, true);
				$errorMsg = $data['error']['message'] ?? "HTTP $httpCode";
				throw new Exception("API Error: $errorMsg");
			}

			$data = json_decode($response, true);
			$result = $data['choices'][0]['message']['content'] ?? '';

			return [
				'success' => true,
				'message' => 'API connection successful! Response: ' . $result
			];

		} catch (Exception $e) {
			return [
				'success' => false,
				'message' => 'API connection failed: ' . $e->getMessage()
			];
		}
	}
}
