<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$matrixJson = scrapegoat_load_asset('data/sbc_matrix.json');
$latestJson = scrapegoat_load_asset('data/latest.json');

if ($matrixJson === null || $latestJson === null) {
    http_response_code(503);
    echo "Data exports not found. Run the scraper pipeline.";
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


try {
    $matrix = json_decode($matrixJson, true, flags: JSON_THROW_ON_ERROR);
    $latest = json_decode($latestJson, true, flags: JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(503);
    echo "Data exports are unreadable. Run the scraper pipeline.";
    exit;
}

if (!is_array($matrix) || !is_array($latest)) {
    http_response_code(503);
    echo "Data exports are unreadable. Run the scraper pipeline.";
    exit;
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
    echo "  <title>Raspberry Pi Prices</title>\n";
    echo "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    echo "  <link rel=\"stylesheet\" href=\"https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css\">\n";
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

$customHeaderUsed = layout_start();
if ($customHeaderUsed) {
    echo '<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css" data-scrapegoat-css>';
}
$wrapperClasses = 'scrapegoat-wrapper' . ($customHeaderUsed ? ' scrapegoat-wrapper--embedded' : ' scrapegoat-wrapper--fallback');
?>
<div class="<?= $wrapperClasses ?>">
  <header class="page-header">
    <h1>Raspberry Pi Price Tracker</h1>
    <p class="lede">Latest public Micro Center pricing for Raspberry Pi boards and kits. Updated when the local scraper runs.</p>
    <nav class="nav-links">
      <a href="tables.php">Markdown tables</a>
    </nav>
  </header>

  <section>
    <h2>Board price grid</h2>
    <div class="table-wrapper">
      <table class="scrapegoat-table">
        <thead>
          <tr>
            <th>Model</th>
            <?php foreach ($matrix['cols'] as $col): ?>
              <th><?= htmlspecialchars((string)$col) ?> GB</th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($matrix['rows'] as $rowIndex => $model): ?>
          <tr>
            <th><?= htmlspecialchars($model) ?></th>
            <?php foreach ($matrix['cols'] as $colIndex => $_mem): ?>
              <?php
                $value = $matrix['values'][$rowIndex][$colIndex] ?? null;
                $text = $value === null ? '—' : '$' . number_format((float)$value, 2);
              ?>
              <td><?= htmlspecialchars($text) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section>
    <h2>Latest Raspberry Pi listings</h2>
    <table id="latest" class="display">
      <thead>
        <tr>
          <th>SKU</th>
          <th>Name</th>
          <th>Price (USD)</th>
          <th>Availability</th>
          <th>Stock</th>
          <th>Link</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($latest as $row): ?>
        <tr>
          <td><?= htmlspecialchars($row['sku'] ?? '') ?></td>
          <td>
            <a href="item.php?sku=<?= urlencode($row['sku'] ?? '') ?>">
              <?= htmlspecialchars($row['name'] ?? '') ?>
            </a>
          </td>
          <td><?= isset($row['price']) && $row['price'] !== null ? '$' . number_format((float)$row['price'], 2) : '' ?></td>
          <td><?= htmlspecialchars($row['availability'] ?? '') ?></td>
          <td><?= htmlspecialchars($row['stock'] ?? '') ?></td>
          <td>
            <?php if (!empty($row['url'])): ?>
              <a href="<?= htmlspecialchars($row['url']) ?>" target="_blank" rel="noopener">Product</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
  $(function () {
    $('#latest').DataTable({
      pageLength: 25,
      order: [[2, 'asc']],
      stateSave: true
    });
  });
</script>
<?php layout_end($customHeaderUsed); ?>
