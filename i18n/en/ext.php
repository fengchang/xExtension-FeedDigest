<?php

/**
 * English translation for Feed Digest extension
 */

return [
	'feed_digest' => [
		// Configuration page
		'config_title' => 'Feed Digest Configuration',
		'warning_title' => 'Important Warnings:',
		'warning_cost' => 'API calls cost money. Monitor your usage and costs regularly.',
		'warning_retry' => 'Failed API calls will automatically retry on every feed update until successful.',
		'warning_timeout' => 'You may need to increase PHP max_execution_time for large batches (recommended: 300+ seconds).',
		'warning_rate_limit' => 'High article counts may hit API rate limits.',

		'api_endpoint' => 'API Endpoint',
		'api_endpoint_help' => 'OpenAI-compatible API endpoint. Examples: https://api.openai.com/v1, https://api.anthropic.com, or local model endpoints.',

		'secret_key' => 'API Secret Key',
		'secret_key_help' => 'Your API secret key for authentication. Keep this secure!',

		'model' => 'Model Name',
		'model_help' => 'LLM model name. Examples: gpt-5-nano, gpt-4o-mini, claude-3-5-sonnet-20241022, etc.',

		'dest_language' => 'Destination Language',
		'dest_language_help' => 'Target language for summaries and title translations. Examples: English, Spanish, Chinese, French, Japanese, etc.',

		'max_content_length' => 'Max Content Length per Article',
		'max_content_length_help' => 'Maximum characters per article content before truncation (500-16000). Helps avoid exceeding LLM context limits. Default 4000 is safe for most models.',

		'test_api' => 'Test API Connection',
		'test_success' => 'API connection successful!',
		'test_failed' => 'API connection failed: %s',

		// Per-feed configuration
		'per_feed_title' => 'Per-Feed Configuration',
		'per_feed_help' => 'To enable LLM summarization for a specific feed, go to the feed\'s settings page and check the "Summarize articles with LLM" option.',
		'per_feed_location' => 'Location: Settings → Feeds → [Select a feed] → Advanced Settings → Summarize articles with LLM',

		// Feed settings
		'feed_setting_title' => 'Feed Digest',
		'feed_setting_label' => 'Summarize articles with LLM',
		'feed_setting_help' => 'Automatically summarize new articles from this feed and mark originals as read.',
		'batch_size_label' => 'Articles per summary batch',
		'batch_size_help' => 'Number of articles to include in each summary. Default: 10',

		// How it works
		'how_it_works_title' => 'How It Works',
		'how_step1' => 'During scheduled feed updates, the extension checks each feed with summarization enabled.',
		'how_step2' => 'Unread articles are collected (up to the max articles limit) and sent to the LLM API in one batch.',
		'how_step3' => 'The LLM summarizes each article and translates titles to your destination language.',
		'how_step4' => 'A combined summary article is created and added to the feed.',
		'how_step5' => 'Original articles are marked as read.',
		'how_step6' => 'If any errors occur, articles remain unread and will be retried on the next update.',
	],
];
