<?php
declare(strict_types=1);

$markdownContent = scrapegoat_load_asset('markdown/raspberry_pi.md');
if ($markdownContent === null) {
    http_response_code(503);
    echo "Markdown summary not found. Run the export pipeline.";
    exit;
}

if (!function_exists('render_fragment')) {
    function render_fragment(string $file): string
    {
        static $cache = [];
        if (isset($cache[$file])) {
            return $cache[$file];
        }
        $path = __DIR__ . '/../chrome/' . ltrim($file, '/');
        if (is_file($path)) {
            $cache[$file] = file_get_contents($path) ?: '';
        } else {
            $cache[$file] = '';
        }
        return $cache[$file];
    }
}

if (!function_exists('fallback_header_html')) {
    function fallback_header_html(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Raspberry Pi Price Tables</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body class="scrapegoat-body">
<main class="scrapegoat-fallback-main">
HTML;
    }
}

if (!function_exists('fallback_footer_html')) {
    function fallback_footer_html(): string
    {
        return "</main></body></html>";
    }
}

[$intro, $sections] = parse_markdown($markdownContent);
$latestJson = scrapegoat_load_asset('data/latest.json', required: false);
$sampleSku = load_sample_sku($latestJson);
$isEmbedded = defined('SCRAPEGOAT_EMBED') && SCRAPEGOAT_EMBED;
$embedOptions = [];
if ($isEmbedded) {
    if (defined('SCRAPEGOAT_EMBED_OPTIONS') && is_array(SCRAPEGOAT_EMBED_OPTIONS)) {
        $embedOptions = SCRAPEGOAT_EMBED_OPTIONS;
    } elseif (isset($GLOBALS['scrapegoatEmbedOptions']) && is_array($GLOBALS['scrapegoatEmbedOptions'])) {
        $embedOptions = $GLOBALS['scrapegoatEmbedOptions'];
    }
}

$navLinks = scrapegoat_build_nav_links($sampleSku, $isEmbedded, $embedOptions);
$showFooter = !$isEmbedded || (($embedOptions['show_footer'] ?? true) !== false);
$footerContent = scrapegoat_build_footer_content($embedOptions);

$headerHtml = '';
$footerHtml = '';
$usingChromeFragments = false;

if (!$isEmbedded) {
    $headerHtml = render_fragment('header.html');
    $footerHtml = render_fragment('footer.html');
    $usingChromeFragments = ($headerHtml !== '' && $footerHtml !== '');

    if (!$usingChromeFragments) {
        $headerHtml = fallback_header_html();
        $footerHtml = fallback_footer_html();
    }

    echo $headerHtml;
}

$wrapperClasses = 'scrapegoat-wrapper';
if ($usingChromeFragments) {
    $wrapperClasses .= ' scrapegoat-wrapper--embedded';
} elseif (!$isEmbedded) {
    $wrapperClasses .= ' scrapegoat-wrapper--fallback';
}
$wrapperClasses = scrapegoat_apply_wrapper_classes($wrapperClasses, $embedOptions);
?>
<div class="<?= htmlspecialchars($wrapperClasses, ENT_QUOTES, 'UTF-8') ?>">
  <header class="page-header">
    <div>
      <?= render_intro($intro); ?>
    </div>
    <?php if ($navLinks): ?>
      <nav class="nav-links">
        <?php foreach ($navLinks as $link): ?>
          <a href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
  </header>

  <?php foreach ($sections as $index => $section): ?>
    <?php
      $tableHtml = build_table_html($section['headers'], $section['rows']);
      $notesHtml = render_notes($section['notes']);
      $copyText = build_copy_text($section);
      $copyPayload = htmlspecialchars(base64_encode($copyText), ENT_QUOTES, 'UTF-8');
    ?>
    <section class="table-card">
      <div class="table-card__header">
        <h2><?= htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8') ?></h2>
        <button type="button" class="copy-btn" data-copy="<?= $copyPayload ?>">Copy markdown</button>
      </div>
      <div class="table-wrapper">
        <?= $tableHtml ?>
      </div>
      <?php if ($notesHtml): ?>
        <div class="table-notes"><?= $notesHtml ?></div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

  <?php if ($showFooter): ?>
    <footer class="page-footer">
      <p><?= $footerContent ?></p>
    </footer>
  <?php endif; ?>
</div>

<script>
  document.querySelectorAll('.copy-btn').forEach((button) => {
    button.addEventListener('click', async () => {
      const payload = button.dataset.copy || '';
      const original = button.textContent;
      let text = '';
      try {
        text = payload ? atob(payload) : '';
      } catch (error) {
        text = '';
      }
      try {
        await navigator.clipboard.writeText(text);
        button.textContent = 'Copied!';
      } catch (error) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        button.textContent = 'Copied!';
      }
      setTimeout(() => {
        button.textContent = original;
      }, 2000);
    });
  });
</script>
<?php
if (!$isEmbedded) {
    echo $footerHtml;
}

function parse_markdown(string $content): array
{
    $content = trim($content);
    $chunks = preg_split('/\r?\n(?=### )/', $content);
    if (!$chunks) {
        return ['', []];
    }

    $intro = array_shift($chunks) ?? '';
    $sections = [];

    foreach ($chunks as $chunk) {
        $lines = preg_split('/\r?\n/', trim($chunk)) ?: [];
        if (!$lines) {
            continue;
        }

        $titleLine = array_shift($lines);
        if ($titleLine === null) {
            continue;
        }
        $title = trim(preg_replace('/^###\s+/', '', $titleLine));

        $tableLines = [];
        $notes = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            if (str_starts_with($trim, '|')) {
                $tableLines[] = $trim;
            } else {
                $notes[] = $line;
            }
        }

        if (!$tableLines) {
            continue;
        }

        [$headers, $rows] = markdown_table_to_array($tableLines);
        if (!$headers) {
            continue;
        }

        $sections[] = [
            'title' => $title,
            'headers' => $headers,
            'rows' => $rows,
            'notes' => $notes,
            'table_markdown' => implode("\n", $tableLines),
            'note_markdown' => $notes ? implode("\n", $notes) : '',
        ];
    }

    return [$intro, $sections];
}

function load_sample_sku(?string $latestJson): string
{
    if ($latestJson === null) {
        return '999001';
    }
    try {
        $rows = json_decode($latestJson, true, flags: JSON_THROW_ON_ERROR);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!empty($row['sku'])) {
                    return (string) $row['sku'];
                }
            }
        }
    } catch (Throwable) {
        // ignore and fall back
    }
    return '999001';
}

function markdown_table_to_array(array $lines): array
{
    $headers = [];
    $rows = [];

    foreach ($lines as $index => $line) {
        $cells = array_map('trim', explode('|', trim($line, '|')));
        if ($index === 0) {
            $headers = $cells;
            continue;
        }
        if ($index === 1 && alignment_row($cells)) {
            continue;
        }
        $rows[] = $cells;
    }

    return [$headers, $rows];
}

function alignment_row(array $cells): bool
{
    foreach ($cells as $cell) {
        $clean = str_replace([' ', ':', '-'], '', $cell);
        if ($clean !== '') {
            return false;
        }
    }
    return true;
}

function render_intro(string $intro): string
{
    $lines = preg_split('/\r?\n/', trim($intro)) ?: [];
    $parts = [];
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '') {
            continue;
        }
        if (str_starts_with($trim, '## ')) {
            $parts[] = '<h1>' . htmlspecialchars(substr($trim, 3), ENT_QUOTES, 'UTF-8') . '</h1>';
        } else {
            $parts[] = '<p>' . htmlspecialchars($trim, ENT_QUOTES, 'UTF-8') . '</p>';
        }
    }
    return implode("\n", $parts);
}

function build_table_html(array $headers, array $rows): string
{
    $tableAttrs = 'class="scrapegoat-table markdown-table" style="width:100%;border-collapse:collapse;border-spacing:0;"';
    $headerCellStyle = 'style="padding:0.55rem 0.8rem;text-align:center;border:1px solid var(--line, #dcdcdc);background:var(--panel, #f0f0f0);font-weight:600;white-space:nowrap;"';
    $bodyCellStyle = 'style="padding:0.55rem 0.8rem;text-align:center;border:1px solid var(--line, #dcdcdc);"';

    $html = '<table ' . $tableAttrs . '><thead><tr>';
    foreach ($headers as $header) {
        $header = trim($header);
        if ($header === '') {
            $header = '&nbsp;';
        } elseif (str_contains($header, '<')) {
            // allow inline HTML (e.g., <sup>)
        } else {
            $header = htmlspecialchars($header, ENT_QUOTES, 'UTF-8');
        }
        $html .= '<th ' . $headerCellStyle . '>' . $header . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td ' . $bodyCellStyle . '>' . format_cell($cell) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    return $html;
}

function format_cell(string $cell): string
{
    $trim = trim($cell);
    if ($trim === '' || strtolower($trim) === 'x') {
        return '&mdash;';
    }

    $text = str_replace(["\r\n", "\r"], "\n", $trim);
    $text = str_replace(['<br />', '<br/>', '<br>'], "\n", $text);
    $text = preg_replace('/\s*;\s*/', "\n", $text) ?? $text;
    $parts = array_filter(preg_split('/\n+/', $text), static fn($part) => trim($part) !== '');
    if (!$parts) {
        $parts = [$trim];
    }
    $formatted = array_map(static function (string $part): string {
        $escaped = convert_links($part);
        return apply_inline_formatting($escaped);
    }, $parts);
    return implode('<br>', $formatted);
}

function convert_links(string $text): string
{
    $result = '';
    $offset = 0;
    while (preg_match('/\[([^\]]+)\]\(([^)]+)\)/', $text, $match, PREG_OFFSET_CAPTURE, $offset)) {
        $start = $match[0][1];
        $length = strlen($match[0][0]);

        $result .= htmlspecialchars(substr($text, $offset, $start - $offset), ENT_QUOTES, 'UTF-8');

        $label = htmlspecialchars($match[1][0], ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars($match[2][0], ENT_QUOTES, 'UTF-8');
        $result .= '<a href="' . $url . '" target="_blank" rel="noopener">' . $label . '</a>';

        $offset = $start + $length;
    }

    $result .= htmlspecialchars(substr($text, $offset), ENT_QUOTES, 'UTF-8');
    return $result;
}

function apply_inline_formatting(string $text): string
{
    $parts = preg_split('/(<[^>]+>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $output = '';
    foreach ($parts as $part) {
        if ($part === '' || $part === null) {
            continue;
        }
        if (str_starts_with($part, '<')) {
            $output .= $part;
            continue;
        }
        $styled = preg_replace('/~~([^~]+)~~/', '<del>$1</del>', $part);
        $styled = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $styled);
        $output .= $styled;
    }
    return $output;
}

function render_notes(array $notes): string
{
    if (!$notes) {
        return '';
    }
    $parts = [];
    foreach ($notes as $note) {
        $trim = trim($note);
        if ($trim === '') {
            continue;
        }
        if (str_starts_with($trim, '<')) {
            $parts[] = '<p class="note-text">' . $trim . '</p>';
        } else {
            $parts[] = '<p class="note-text">' . htmlspecialchars($trim, ENT_QUOTES, 'UTF-8') . '</p>';
        }
    }
    return implode("\n", $parts);
}

function build_copy_text(array $section): string
{
    $lines = ["### " . $section['title'], '', $section['table_markdown']];
    if (!empty($section['note_markdown'])) {
        $lines[] = '';
        $lines[] = $section['note_markdown'];
    }
    $lines[] = '';
    $lines[] = 'Source: https://xtonyx.org/scrapegoat/';
    return implode("\n", $lines);
}

function scrapegoat_build_nav_links(string $sampleSku, bool $isEmbedded, array $options): array
{
    if (!$isEmbedded) {
        $links = [
            ['label' => 'Interactive table', 'href' => 'sbc.php'],
            ['label' => 'Sample item', 'href' => 'item.php?sku={SKU}'],
        ];
    } else {
        if (($options['show_nav'] ?? true) === false) {
            return [];
        }
        $links = $options['nav_links'] ?? [];
        if (!is_array($links) || !$links) {
            $links = [
                ['label' => 'Interactive table', 'href' => 'https://xtonyx.org/scrapegoat/sbc.php'],
                ['label' => 'Sample item', 'href' => 'https://xtonyx.org/scrapegoat/item.php?sku={SKU}'],
            ];
        }
    }

    $encodedSku = urlencode($sampleSku);
    $resolved = [];
    foreach ($links as $link) {
        if (!is_array($link)) {
            continue;
        }
        $label = isset($link['label']) ? trim((string) $link['label']) : '';
        $href = isset($link['href']) ? trim((string) $link['href']) : '';
        if ($label === '' || $href === '') {
            continue;
        }
        if (str_contains($href, '{SKU}')) {
            $href = str_replace('{SKU}', $encodedSku, $href);
        } elseif (!$isEmbedded && str_contains($href, 'item.php') && !str_contains($href, 'sku=')) {
            $href = rtrim($href, '?&');
            $href .= (str_contains($href, '?') ? '&' : '?') . 'sku=' . $encodedSku;
        }
        $resolved[] = [
            'label' => $label,
            'href' => $href,
        ];
    }
    return $resolved;
}

function scrapegoat_build_footer_content(array $options): string
{
    if (isset($options['footer_html']) && is_string($options['footer_html'])) {
        return $options['footer_html'];
    }

    $repoUrl = isset($options['repo_url']) && is_string($options['repo_url']) && trim($options['repo_url']) !== ''
        ? trim($options['repo_url'])
        : 'https://github.com/omgsideburns/scrapegoat';
    $repoLabel = isset($options['repo_label']) && is_string($options['repo_label']) && trim($options['repo_label']) !== ''
        ? trim($options['repo_label'])
        : 'Browse the repo on GitHub';
    $prefix = isset($options['footer_prefix']) && is_string($options['footer_prefix']) && trim($options['footer_prefix']) !== ''
        ? trim($options['footer_prefix'])
        : 'Need raw data or charts?';

    return htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8') .
        ' <a href="' . htmlspecialchars($repoUrl, ENT_QUOTES, 'UTF-8') . '">' .
        htmlspecialchars($repoLabel, ENT_QUOTES, 'UTF-8') .
        '</a>.';
}

function scrapegoat_apply_wrapper_classes(string $baseClasses, array $options): string
{
    $extra = [];

    if (isset($options['wrapper_class']) && is_string($options['wrapper_class'])) {
        $extra[] = $options['wrapper_class'];
    }
    if (isset($options['wrapper_classes'])) {
        $classes = $options['wrapper_classes'];
        if (is_string($classes)) {
            $extra[] = $classes;
        } elseif (is_array($classes)) {
            foreach ($classes as $class) {
                if (is_string($class) && trim($class) !== '') {
                    $extra[] = $class;
                }
            }
        }
    }

    if (!$extra) {
        return $baseClasses;
    }

    $pieces = array_filter(array_map(
        static fn(string $value): string => trim($value),
        explode(' ', $baseClasses . ' ' . implode(' ', $extra))
    ), static fn(string $value): bool => $value !== '');

    return implode(' ', array_unique($pieces));
}
