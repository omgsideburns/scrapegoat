<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$sku = preg_replace('/[^0-9A-Za-z\-]/', '', $_GET['sku'] ?? '');
if ($sku === '') {
    http_response_code(400);
    echo "Missing SKU.";
    exit;
}

$historyJson = scrapegoat_load_asset("data/history/{$sku}.json", required: false);
if ($historyJson === null) {
    http_response_code(404);
    echo "Unknown SKU.";
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
    $item = json_decode($historyJson, true, flags: JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(500);
    echo "Item data is unavailable.";
    exit;
}

if (!is_array($item)) {
    http_response_code(500);
    echo "Item data is unavailable.";
    exit;
}
$series = $item['series'] ?? [];
function layout_start(string $pageTitle): bool
{
    $header = render_fragment('header.html');
    if ($header !== '') {
        echo $header;
        return true;
    }

    echo "<!doctype html>\n";
    echo "<html lang=\"en\">\n<head>\n";
    echo "  <meta charset=\"utf-8\">\n";
    echo "  <title>" . htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . "</title>\n";
    echo "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    echo "  <script src=\"https://cdn.jsdelivr.net/npm/chart.js@4\"></script>\n";
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

<?php
$pageTitle = ($item['name'] ?? $sku) . ' price history';
$customHeaderUsed = layout_start($pageTitle);
if ($customHeaderUsed) {
    echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>';
}
$wrapperClasses = 'scrapegoat-wrapper' . ($customHeaderUsed ? ' scrapegoat-wrapper--embedded' : ' scrapegoat-wrapper--fallback');
?>
<div class="<?= $wrapperClasses ?>">
  <header class="page-header">
    <h1><?= htmlspecialchars($item['name'] ?? $sku) ?></h1>
    <p class="lede">Historical Micro Center pricing for SKU <?= htmlspecialchars($item['sku'] ?? $sku) ?>.</p>
    <p><a href="sbc.php">&larr; Back to Raspberry Pi listings</a></p>
  </header>

  <section class="chart-section">
    <canvas id="priceChart" width="960" height="420"></canvas>
  </section>

  <section>
    <h2>Recent prices</h2>
    <table class="scrapegoat-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Price</th>
          <th>Sale?</th>
          <th>All-time low?</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach (array_reverse($series) as $row): ?>
        <tr>
          <td><?= htmlspecialchars($row['date'] ?? '') ?></td>
          <td><?= isset($row['price']) ? '$' . number_format((float)$row['price'], 2) : '' ?></td>
          <td><?= !empty($row['is_sale']) ? 'Yes' : '' ?></td>
          <td><?= !empty($row['is_low']) ? 'Yes' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>

<script>
const series = <?= json_encode($series, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const labels = series.map(point => point.date);
const prices = series.map(point => point.price);

const salePoints = series
  .map((point, index) => point.is_sale ? {x: point.date, y: point.price} : null)
  .filter(Boolean);
const lowPoints = series
  .map((point, index) => point.is_low ? {x: point.date, y: point.price} : null)
  .filter(Boolean);

const ctx = document.getElementById('priceChart');
const chart = new Chart(ctx, {
  type: 'line',
  data: {
    labels,
    datasets: [{
      label: 'Price (USD)',
      data: prices,
      borderWidth: 2,
      pointRadius: 2,
      tension: 0.1
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    scales: {
      x: { title: { display: true, text: 'Date' } },
      y: { title: { display: true, text: 'USD' } }
    }
  }
});

function addScatter(points, color, label) {
  if (!points.length) return;
  chart.data.datasets.push({
    type: 'scatter',
    data: points,
    backgroundColor: color,
    label,
    pointRadius: 4
  });
  chart.update();
}

addScatter(salePoints, '#e67e22', 'Sale');
addScatter(lowPoints, '#2ecc71', 'All-time low');
</script>
<?php layout_end($customHeaderUsed); ?>
