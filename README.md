# AI Smart Chat

An AI-powered search chatbot for WordPress. Visitors ask questions in natural language and get answers written **only from your own content**, with links to the pages the answer came from.

It uses retrieval-augmented generation (RAG): posts, pages, products and other content are split into passages and turned into embeddings; for each question the most relevant passages are retrieved and sent to an OpenAI-compatible chat model.

> WordPress.org listing: https://wordpress.org/plugins/ai-smart-chat/ (available once the plugin is approved)

## Features

- **Self-contained** – no external vector database or SaaS account. The index lives in your WordPress database; you only need an OpenAI-compatible API key.
- **Any content** – any public post type, plus fixed texts, parts of other pages (CSS selector) and WordPress options through *Extra sources*.
- **Complete lists and counts** – questions such as "which products are for pastures?" use every item of the matching category or tag instead of only the best few hits.
- **Honest answers** – when the content has no answer, the chat says so and shows no sources.
- **Flexible display** – floating launcher, Gutenberg block, shortcode or PHP, with three templates (`classic`, `modern`, `minimal`) that themes can override.
- **Admin help page** – overview, quick start, shortcode reference and WP-CLI reference inside WordPress.
- **WP-CLI** – index, search, ask and export history from the terminal.
- **Privacy and control** – rate limiting, question history with export, API key can live in `wp-config.php`.
- **Translations** – English, Portuguese (Brazil) and Spanish.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- An API key for OpenAI or any OpenAI-compatible service

## Installation

1. Download the latest release and upload the `ai-smart-chat` folder to `wp-content/plugins/` (or install it from the WordPress.org directory).
2. Activate the plugin.
3. Go to **AI Smart Chat → Settings**, add your API key and choose the content types to index.
4. Open **AI Smart Chat → Index** and build the index.
5. Show the chat: enable the floating button in Settings, add the *AI Smart Chat* block, or use the shortcode.

The **Help** page inside the plugin has the full guide.

## Usage

```text
[ai_smart_chat]
[ai_smart_chat template="modern" height="480px" title="Talk to us" suggestions="Opening hours?|How to buy?"]
[ai_smart_chat mode="floating" position="left"]
```

(`[ai_site_chat]` is accepted as an alias.)

| Attribute | Meaning |
| --- | --- |
| `template` | `classic`, `modern`, `minimal` or your own template |
| `mode` | `inline` (default) or `floating` |
| `position` | `left` or `right` (floating button) |
| `title`, `placeholder`, `welcome`, `launcher_label` | Texts |
| `suggestions` | Suggested questions separated by `|` |
| `height` | e.g. `480px`, `70vh` |
| `color` | Primary colour, e.g. `#0a7d5a` |
| `class` | Extra CSS class |

Custom templates: copy a folder from `templates/` to `your-theme/ai-smart-chat/<name>/` and keep the `data-saic-*` attributes.

## WP-CLI

```bash
wp saic status
wp saic index [--force] [--post_type=product] [--batch=20]
wp saic sources [--force]
wp saic index-post <id> [--force]
wp saic delete <id>
wp saic clear --yes
wp saic search "query" [--limit=20]
wp saic ask "question"
wp saic history [--limit=50] [--format=table|csv|json]
```

## Hooks

PHP: `saic_templates`, `saic_template_data`, `saic_system_prompt`, `saic_post_text`, `saic_is_indexable`, `saic_context_chunks`, `saic_retrieval_query`, `saic_response`, `saic_chat_request_body`, `saic_show_floating`, `saic_client_ip`, `saic_search_hits`, `saic_term_expansion`, `saic_answer_not_found`.

JavaScript: `AISiteChat.open()`, `AISiteChat.ask('text')`, `AISiteChat.filters.renderAnswer`, `AISiteChat.filters.beforeRequest`.

REST: `POST /wp-json/saic/v1/ask`.

## Privacy and external services

Questions and the retrieved passages are sent to the API you configure (OpenAI by default) to generate embeddings and answers. Nothing is sent until an API key is set. See the *External services* section of [`readme.txt`](readme.txt).

## Development

```bash
composer install
vendor/bin/phpcs      # WordPress Coding Standards
vendor/bin/phpcbf     # auto-fix
```

The repository root is the plugin folder. Files listed in `.distignore` are not shipped to WordPress.org.

## Releasing

1. Bump `Version` in `ai-smart-chat.php`, `SAIC_VERSION` and `Stable tag` in `readme.txt`; add a changelog entry.
2. Commit, then publish a GitHub release (tag such as `1.3.6`).
3. The *Deploy to WordPress.org* workflow pushes the plugin to SVN (requires the `SVN_USERNAME` and `SVN_PASSWORD` repository secrets).

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE).
