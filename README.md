# Feed Digest Extension for FreshRSS

Automatically summarize newly retrieved RSS articles using LLM APIs (OpenAI-compatible). This extension processes articles during feed updates, creates combined summary articles in your destination language, and marks the originals as read.

## Features

- 🤖 **Automatic Summarization**: Processes unread articles using LLM APIs during scheduled feed updates
- 🌍 **Multi-language**: Translates article titles and summaries to your chosen language
- ⚡ **Efficient Batch Processing**: Summarizes multiple articles in a single API call to reduce costs
- 🔄 **Auto-retry**: Failed API calls automatically retry on the next feed update
- 📊 **Per-feed Control**: Enable/disable summarization and configure batch size for each feed individually
- 🎯 **Smart Filtering**: Skips image-only and too-short articles, adds explanatory notes
- 🎨 **Clean Output**: Creates formatted summary articles with links to originals

## Requirements

- FreshRSS 1.24.0 or later
- PHP 7.4+ with cURL extension
- An OpenAI-compatible API key (OpenAI, Anthropic Claude, local models, etc.)
- Sufficient PHP `max_execution_time` (recommended: 300+ seconds for large batches)

## Installation

1. Download or clone this repository
2. Copy the `xExtension-FeedDigest` directory to your FreshRSS `extensions` directory:
   ```bash
   cp -r xExtension-FeedDigest /path/to/FreshRSS/extensions/
   ```
3. In FreshRSS, navigate to **Settings → Extensions**
4. Enable the "Feed Digest" extension
5. Click "Configure" to set up your API credentials

## Configuration

### Global Settings

Navigate to **Settings → Extensions → Feed Digest → Configure**

Required settings:
- **API Endpoint**: OpenAI-compatible API endpoint URL
  - OpenAI: `https://api.openai.com/v1`
  - Anthropic Claude (via OpenAI compatibility): Check your provider's documentation
  - Local models: Your local endpoint URL

- **API Secret Key**: Your API authentication key
  - Keep this secure!
  - Never share or commit this key to version control

- **Model Name**: The LLM model to use
  - OpenAI: `gpt-5-nano` (recommended for cost), `gpt-4o-mini`, `gpt-4o`
  - Claude: `claude-3-5-sonnet-20241022`, `claude-3-haiku-20240307`
  - Other: Check your provider's model names

- **Destination Language**: Target language for summaries and translations
  - Examples: `English`, `Spanish`, `Simplified Chinese`, `French`, `Japanese`, `German`
  - The LLM will translate titles and write summaries in this language

- **Max Content Length**: Maximum characters per article (500-16000)
  - Default: 4000
  - Truncates longer articles to avoid LLM context limits
  - Estimate: 1 char ≈ 0.4 tokens

### Per-Feed Settings

To enable summarization for a specific feed:

1. Navigate to **Settings → Feeds**
2. Select the feed you want to summarize
3. Scroll to the **Feed Digest** section
4. Configure the following:
   - **Summarize articles with LLM**: Set to **Yes**
   - **Articles per summary batch**: Number of articles to include in each summary (1-50, default: 10)
     - Articles are processed in batches to avoid timeouts
     - Each batch creates one summary article
     - Example: 35 unread articles with batch size 10 → 3 summary articles (10+10+10), 5 remain unread
5. Click **Submit**

## API Endpoint Examples

### OpenAI

```
Endpoint: https://api.openai.com/v1
Model: gpt-5-nano
Key: sk-...
```

### Anthropic Claude (via OpenAI-compatible wrappers)

Many services provide OpenAI-compatible endpoints for Claude. Check your provider's documentation.

### Local Models (Ollama, LM Studio, etc.)

```
Endpoint: http://localhost:11434/v1  # Ollama
Model: llama3.2
Key: not-needed  # Often not required for local models
```

### OpenRouter

```
Endpoint: https://openrouter.ai/api/v1
Model: anthropic/claude-3.5-sonnet
Key: sk-or-v1-...
```

## How It Works

1. **Scheduled Updates**: During your regular FreshRSS cron/scheduled feed updates, the extension activates
2. **Feed Check**: For each feed with summarization enabled, it fetches unread articles (up to 200)
3. **Article Filtering**:
   - Filters out previously created summary articles
   - Identifies image-only or too-short articles (< 100 characters)
   - Adds explanatory notes to skipped articles (they remain unread for you to review)
4. **Batch Processing**: Articles are processed in configurable batches (default: 10 per batch)
   - Only processes batches when enough articles are available
   - Each batch is sent to the LLM API in one request for efficiency
   - Each batch succeeds or fails independently
5. **Summary Creation**: For each batch, a new "summary" article is created with:
   - Translated titles (in your destination language)
   - Concise summaries (2-4 sentences each)
   - Links to original articles
   - Clean HTML formatting
6. **Mark as Read**: Only successfully summarized articles are marked as read
7. **Auto-retry**: Failed batches remain unread and will be retried on the next update

## PHP Timeout Configuration

For large batches, you may need to increase PHP execution time:

### In php.ini:
```ini
max_execution_time = 300
```

### In FreshRSS .htaccess (Apache):
```apache
php_value max_execution_time 300
```

### In Nginx config:
```nginx
fastcgi_read_timeout 300;
```

**Estimation**:
- Each batch of 10 articles takes ~5-15 seconds (API call + processing)
- Multiple batches are processed sequentially per feed
- Recommended: 300 seconds (5 minutes) for safety with multiple feeds

## Cost Estimation

API costs vary by provider and model. Using `gpt-5-nano` (recommended):

**Typical usage** (10 articles per batch, 4000 chars each):
- **Cost per batch: ~$0.001**

**Example scenario**: 5 feeds, each with 20 unread articles/day, batch size 10:
- 5 feeds × 2 batches/day = 10 batches/day
- **Cost: ~$0.01/day or ~$0.30/month**

> **Tip**: `gpt-5-nano` offers the best cost-performance ratio for RSS summarization

## Troubleshooting

### API Connection Failed

1. Test your API connection using the "Test API Connection" button
2. Verify your API endpoint URL is correct
3. Check your API key is valid and has sufficient credits
4. Review FreshRSS logs for detailed error messages

### Articles Not Being Summarized

1. Verify the feed has "Summarize articles with LLM" enabled
2. Check that articles are marked as **unread**
3. Ensure your API key is configured and valid
4. Look for errors in FreshRSS logs: `data/users/_/log*.txt`

### PHP Timeout Errors

1. Increase `max_execution_time` in PHP configuration (recommended: 300 seconds)
2. Reduce "Articles per summary batch" setting for individual feeds
3. Disable summarization for some feeds to reduce total processing time

### Summaries in Wrong Language

1. Check "Destination Language" setting is correct
2. Be specific (e.g., "Simplified Chinese" vs just "Chinese")
3. Test with a single article first

### High API Costs

1. Use `gpt-5-nano` for the best cost-performance ratio
2. Reduce "Articles per summary batch" for feeds (processes fewer articles at once)
3. Lower "Max Content Length" to send less data per article
4. Enable summarization only for high-value feeds
5. Monitor API usage on your provider's dashboard

## Privacy & Data Usage

- **API Calls**: Article content is sent to your configured LLM API
- **Data Storage**: Only summaries are stored locally; API has its own data retention policies
- **Security**: API keys are stored in FreshRSS configuration (keep backups secure)
- **Logging**: Errors and processing info logged to FreshRSS logs

## Limitations

- **Cron-based**: Summarization happens during scheduled updates, not immediately on manual refresh
- **Batch Processing**: Articles must accumulate to the configured batch size before processing
- **Sequential Batches**: Each feed's batches are processed sequentially to avoid timeouts
- **No Retry Tracking**: Failed batches retry every update (no exponential backoff)
- **Context Limits**: Very long articles are truncated based on max content length setting
- **Image-only Articles**: Articles with minimal text are skipped and left unread with an explanatory note

## Development

### Testing

To test the extension:

1. Enable for a single test feed with few articles
2. Manually trigger feed update
3. Check logs for processing messages
4. Verify summary article appears in feed
5. Confirm original articles marked as read

### Debugging

Enable detailed logging in FreshRSS and monitor:
- `data/users/_/log.txt` or `data/users/_/log_*.txt`
- Look for "Feed Digest:" prefixed messages

## Support

For issues, questions, or contributions:
- GitHub Issues: https://github.com/fengchang/xExtension-FeedDigest
- FreshRSS Community: https://github.com/FreshRSS/FreshRSS/discussions

## License

GNU Affero General Public License v3.0 (AGPL-3.0)

See [LICENSE](LICENSE) file for details.

## Credits

Developed for the FreshRSS community.

---

**Note**: This extension uses third-party AI services. Review their terms of service and privacy policies before use.
