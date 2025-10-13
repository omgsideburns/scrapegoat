<?php
declare(strict_types=1);

$sku = preg_replace('/[^0-9A-Za-z\-]/', '', $_GET['sku'] ?? '');
if ($sku === '') {
    http_response_code(400);
    echo "Missing SKU.";
    exit;
}

$path = __DIR__ . "/data/history/{$sku}.json";
if (!file_exists($path)) {
    http_response_code(404);
    echo "Unknown SKU.";
    exit;
}

$item = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$series = $item['series'] ?? [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars(($item['name'] ?? $sku) . ' price history') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="site.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
</head>
<body>
<main class="container">
  <header>
    <h1><?= htmlspecialchars($item['name'] ?? $sku) ?></h1>
    <p class="lede">Historical Micro Center pricing for SKU <?= htmlspecialchars($item['sku'] ?? $sku) ?>.</p>
    <p><a href="sbc.php">&larr; Back to Raspberry Pi listings</a></p>
  </header>

  <section class="chart-section">
    <canvas id="priceChart" width="960" height="420"></canvas>
  </section>

  <section>
    <h2>Recent prices</h2>
    <table class="grid">
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
</main>

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
</body>
</html>
