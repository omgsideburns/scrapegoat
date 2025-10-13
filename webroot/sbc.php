<?php
declare(strict_types=1);

$dataDir = __DIR__ . '/data';
$matrixPath = $dataDir . '/sbc_matrix.json';
$latestPath = $dataDir . '/latest.json';

if (!file_exists($matrixPath) || !file_exists($latestPath)) {
    http_response_code(503);
    echo "Data exports not found. Run the scraper pipeline.";
    exit;
}

$matrix = json_decode(file_get_contents($matrixPath), true, flags: JSON_THROW_ON_ERROR);
$latest = json_decode(file_get_contents($latestPath), true, flags: JSON_THROW_ON_ERROR);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Raspberry Pi Prices</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="site.css">
</head>
<body>
<main class="container">
  <header>
    <h1>Raspberry Pi Price Tracker</h1>
    <p class="lede">Latest public Micro Center pricing for Raspberry Pi boards and kits. Updated when the local scraper runs.</p>
  </header>

  <section>
    <h2>Board price grid</h2>
    <div class="table-wrapper">
      <table class="grid">
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
</main>

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
</body>
</html>
