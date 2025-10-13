<?php
declare(strict_types=1);

$markdownPath = __DIR__ . '/markdown/raspberry_pi.md';
if (!file_exists($markdownPath)) {
    http_response_code(503);
    echo "Markdown summary not found. Run the export pipeline.";
    exit;
}

$fragmentCache = [];
if (!function_exists('render_fragment')) {
    function render_fragment(string $file): string
    {
        static $cache = [];
        if (isset($cache[$file])) {
            return $cache[$file];
        }
        $path = __DIR__ . '/chrome/' . ltrim($file, '/');
        if (is_file($path)) {
            $cache[$file] = file_get_contents($path) ?: '';
        } else {
            $cache[$file] = '';
        }
        return $cache[$file];
    }
}


[$intro, $sections] = parse_markdown(file_get_contents($markdownPath));
$sampleSku = load_sample_sku(__DIR__ . '/data/latest.json');

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

function load_sample_sku(string $latestPath): string
{
    if (!file_exists($latestPath)) {
        return '999001';
    }
    try {
        $rows = json_decode(file_get_contents($latestPath), true, flags: JSON_THROW_ON_ERROR);
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
    $html = '<table class="grid markdown-table"><thead><tr>';
    foreach ($headers as $header) {
        $header = trim($header);
        if ($header === '') {
            $header = '&nbsp;';
        } elseif (str_contains($header, '<')) {
            // allow inline HTML (e.g., <sup>)
        } else {
            $header = htmlspecialchars($header, ENT_QUOTES, 'UTF-8');
        }
        $html .= '<th>' . $header . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>' . format_cell($cell) . '</td>';
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

function layout_start(): bool
{
    $header = render_fragment('header.html');
    if ($header !== '') {
        echo $header;
        return true;
    }

    echo "<!doctype html>\n";
    echo "<html lang=\"en\">\n<head>\n";
    echo "  <meta charset=\"utf-8\">\n";
    echo "  <title>Raspberry Pi Price Tables</title>\n";
    echo "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    echo "  <link rel=\"stylesheet\" href=\"site.css\">\n";
    echo "</head>\n<body class=\"scrapegoat-body\">\n";
    echo "<main class=\"scrapegoat-fallback-main\">\n";
    return false;
}

function layout_end(bool $customHeaderUsed): void
{
    $footer = render_fragment('footer.html');
    if ($footer !== '') {
        echo $footer;
        return;
    }

    if (!$customHeaderUsed) {
        echo "</main></body></html>";
    }
}
?>
<?php
$customHeaderUsed = layout_start();
if ($customHeaderUsed) {
    echo '<link rel="stylesheet" href="site.css" data-scrapegoat-css>';
}
$wrapperClasses = 'scrapegoat-wrapper' . ($customHeaderUsed ? ' scrapegoat-wrapper--embedded' : ' scrapegoat-wrapper--fallback');
?>
<div class="<?= $wrapperClasses ?>">
  <header class="page-header">
    <div>
      <?= render_intro($intro); ?>
    </div>
    <nav class="nav-links">
      <a href="sbc.php">Interactive table</a>
      <a href="item.php?sku=<?= urlencode($sampleSku) ?>">Sample item</a>
    </nav>
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

  <footer class="page-footer">
    <p>Need raw data or charts? <a href="https://github.com/omgsideburns/scrapegoat">Browse the repo on GitHub</a>.</p>
  </footer>
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
<?php layout_end($customHeaderUsed); ?>
