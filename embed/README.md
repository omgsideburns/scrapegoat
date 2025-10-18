# Scrapegoat Embed Package

Drop these PHP files into any project to render the Scrapegoat price tables using the public data that lives in the GitHub repo. No scraper or scheduler required—everything is pulled on-demand from GitHub's raw endpoints.

## What’s Included

- `include.php` – the entry point you `include` from your page.
- `core.php` – shared renderer that powers both the main site and the embed.
- `bootstrap.php` – lightweight fallback loader with remote-fetch helpers; the embed will prefer the project-level `site/bootstrap.php` when it exists.
- `README.md` – you're reading it.

## Quick Start

1. Copy `embed/` (and optionally `site/site.css`) to your project.
2. Add the styles somewhere in your page. Either copy `site.css`, or hotlink the published version:
   ```html
   <link rel="stylesheet" href="https://xtonyx.org/scrapegoat/site.css">
   ```
3. Include the tables wherever PHP runs:
   ```php
   <?php
   // Optional tweaks before loading (see the next section).
   // $GLOBALS['scrapegoatEmbedOptions'] = [
   //     'show_nav' => false,
   // ];
   include __DIR__ . '/scrapegoat/embed/include.php';
   ?>
   ```

The embed automatically fetches `markdown/raspberry_pi.md` and `data/latest.json` from `https://raw.githubusercontent.com/omgsideburns/scrapegoat/main/site`. Override `SCRAPEGOAT_REMOTE_BASE_URL` if you host the assets elsewhere.

## Customising the Output

Set options before the include. You can either define `SCRAPEGOAT_EMBED_OPTIONS` or populate `$GLOBALS['scrapegoatEmbedOptions']`. The array accepts:

| Option | Type | Default | Notes |
| --- | --- | --- | --- |
| `show_nav` | bool | `true` | Hide/show the header navigation. |
| `nav_links` | array | Canonical Scrapegoat URLs | Each item: `['label' => 'Text', 'href' => 'https://…/{SKU}…']`. `{SKU}` is replaced with the latest SKU. |
| `show_footer` | bool | `true` | Toggle the footer panel. |
| `footer_html` | string | `null` | Raw HTML for the footer. Overrides the next two options. |
| `footer_prefix` | string | `"Need raw data or charts?"` | Leading sentence before the repo link. |
| `repo_url` | string | GitHub repo | Link used in the default footer message. |
| `repo_label` | string | `"Browse the repo on GitHub"` | Anchor text used in the default footer message. |
| `wrapper_class` / `wrapper_classes` | string/array | `"scrapegoat-embed"` | Extra classes appended to the outer wrapper. |

Example: hide the nav and point the footer at a custom URL.

```php
<?php
$GLOBALS['scrapegoatEmbedOptions'] = [
    'show_nav' => false,
    'footer_prefix' => 'Want the full dataset?',
    'repo_url' => 'https://example.com/data',
    'repo_label' => 'Download CSV',
];
include __DIR__ . '/scrapegoat/embed/include.php';
```

## Notes & Tips

- The renderer gracefully falls back to simplified markup if the optional `chrome/header.html` / `chrome/footer.html` fragments are missing.
- For best performance on multi-include pages, consider storing the embed output in a fragment cache.
- If you need additional assets (charts, JSON, etc.), call `scrapegoat_load_asset('<path>')`—it works the same in the embed.
