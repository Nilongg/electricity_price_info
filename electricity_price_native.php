<?php
// index.php - fetch, parse, display Elering CSV prices (fi) with Chart.js
// -------- CONFIG ----------
$timezoneDisplay = 'Europe/Helsinki';
// Ensure a valid DateTimeZone object for display timezone (fallback to Europe/Helsinki)
if (!isset($timezoneDisplay) || trim($timezoneDisplay) === '') {
    $timezoneDisplay = 'Europe/Helsinki';
}
try {
    $displayTz = new DateTimeZone($timezoneDisplay);
} catch (Exception $e) {
    $timezoneDisplay = 'Europe/Helsinki';
    $displayTz = new DateTimeZone($timezoneDisplay);
}
// Build URL for current day (start) -> now using display timezone converted to UTC
// Start is today's 00:00 in $timezoneDisplay, end is now in $timezoneDisplay, both converted to UTC (Z)
$tzForUrl = $displayTz;
$startLocal = new DateTime('today', $tzForUrl);
$endLocal = new DateTime('now', $tzForUrl);
$startUtc = clone $startLocal;
$startUtc->setTimezone(new DateTimeZone('UTC'));
$endUtc = clone $endLocal;
$endUtc->setTimezone(new DateTimeZone('UTC'));
$url = sprintf(
    'https://dashboard.elering.ee/api/nps/price/csv?start=%s&end=%s&fields=fi',
    $startUtc->format('Y-m-d\TH:i:s\Z'),
    $endUtc->format('Y-m-d\TH:i:s\Z')
);
$cacheFile = __DIR__ . '/cache/elering_prices.csv';
$cacheTtlSeconds = 300; // 5 minutes cache
// --------------------------

if (!is_dir(dirname($cacheFile))) mkdir(dirname($cacheFile), 0755, true);

// Fetch CSV with caching using native PHP (no cURL)
function fetchRemoteCsv(string $url, string $cacheFile, int $ttl): string|false {
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
        return file_get_contents($cacheFile);
    }

    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: MyPHPApp/1.0\r\nAccept: text/csv, */*\r\n",
            'ignore_errors' => true, // fetch response even on 4xx/5xx so we can inspect status
            'timeout' => 10,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ];

    $context = stream_context_create($opts);

    // Use @ to suppress warnings; we'll handle failure explicitly
    $resp = @file_get_contents($url, false, $context);

    if ($resp === false) {
        return false;
    }

    // Determine HTTP status code from $http_response_header (native global)
    $httpCode = null;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#HTTP/\d+\.\d+\s+(\d+)#i', $h, $m)) {
                $httpCode = (int)$m[1];
                break;
            }
        }
    }

    if ($httpCode !== null && $httpCode >= 400) {
        return false;
    }

    // Save to cache
    file_put_contents($cacheFile, $resp);
    return $resp;
}

// Parse CSV with semicolon delimiter
function parseCsvString(string $csv): array {
    $rows = [];
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $csv);
    rewind($fp);
    while (($data = fgetcsv($fp, 0, ';')) !== false) {
        if (count($data) === 1 && trim($data[0]) === '') continue;
        $rows[] = $data;
    }
    fclose($fp);
    return $rows;
}

// Fetch & parse
$csv = fetchRemoteCsv($url, $cacheFile, $cacheTtlSeconds);
$error = null;
$rows = [];
if ($csv === false) {
    $error = "Failed to fetch CSV from the source.";
} else {
    $rows = parseCsvString($csv);
}

// Prepare data
$headers = $rows[0] ?? [];
$dataRows = array_slice($rows, 1);

// Last updated time (cache file modification) in display timezone
$lastUpdated = null;
if (file_exists($cacheFile)) {
    $dt = new DateTime('@' . filemtime($cacheFile));
    $dt->setTimezone($displayTz);
    // Format like "15:05 pm 15/11/2025" or "01:00 am 01/01/2025" (H:i a d/m/Y)
    $lastUpdated = $dt->format('H:i a d/m/Y');
}

// Page generation time in display timezone (formatted same way)
$pageGenerated = (new DateTime('now', new DateTimeZone($timezoneDisplay)))->format('H:i a d/m/Y');

// Next update timestamp (when cache becomes stale) - use cache mtime if present, otherwise now
$nextUpdateUnix = (file_exists($cacheFile) ? filemtime($cacheFile) : time()) + intval($cacheTtlSeconds);
$nextUpdateJsMs = $nextUpdateUnix * 1000; // milliseconds for JS

$chartLabels = [];
$chartData = [];
$cleanRows = [];

foreach ($dataRows as $r) {
    // Expecting at least 3 columns: index, timestamp, value
    if (count($r) < 3) continue;

    // Timestamp: use Estonian time string column
    try {
        # Convert to desired timezone
        $dt = DateTime::createFromFormat('d.m.Y H:i', $r[1], new DateTimeZone('Europe/Helsinki'));
        $label = $dt ? $dt->format('Y-m-d H:i') : $r[1];
    } catch (Exception $e) {
        $label = $r[1];
    }

    // Numeric value: replace comma with dot
    // Value is the third column (price in fi)
    $value = str_replace(',', '.', $r[2]);
    $num = is_numeric(trim($value)) ? (float) trim($value) : null;

    // Convert EUR/MWh -> cents/kWh:
    // EUR/MWh divided by 1000 => EUR/kWh. Multiply by 100 => cents/kWh.
    // Combined factor: (value / 1000) * 100 = value / 10
    $priceCPerKwh = $num !== null ? ($num / 10.0) : null;

    $chartLabels[] = $label;
    $chartData[] = $priceCPerKwh;

    $cleanRows[] = [
        'timestamp' => $label,
        'price' => $priceCPerKwh,
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Elering Prices (fi)</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body { font-family: Arial,sans-serif; max-width:1100px; margin:20px auto; padding:0 16px; }
table { border-collapse: collapse; width:100%; margin-top:16px; }
th,td { border:1px solid #ccc; padding:6px 8px; text-align:left; }
th { background:#f4f4f4; }
.meta { color:#555; font-size:0.9rem; }
.error { color:darkred; font-weight:bold; }
.chart-container { width:100%; max-width:900px; margin-top:20px; }
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<h1>Elering Prices (fi) c/kWh</h1>
<p class="meta">
    Source: <a href="<?php echo htmlspecialchars($url) ?>" target="_blank" rel="noopener">Elering CSV</a> — cached <?php echo intval($cacheTtlSeconds) ?>s.
    <?php if ($lastUpdated) echo ' Updated at: <strong>'.htmlspecialchars($lastUpdated).'</strong>.'; ?>
    Page generated: <strong><?php echo htmlspecialchars($pageGenerated); ?></strong>.
    Next update in: <strong id="nextUpdateTimer">--:--</strong>.
    <?php if ($error) echo '<span class="error">('.htmlspecialchars($error).')</span>'; ?>
</p>

<?php if (!empty($cleanRows)): ?>

<p class="meta">Showing <?php echo count($cleanRows) ?> rows. Timestamps converted to <?php echo htmlspecialchars($timezoneDisplay); ?>.</p>

<div class="chart-container">
    <h2>Price Line Chart</h2>  
    <canvas id="priceChart" height="120"></canvas> 
</div>

<script>
const labels = <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE); ?>;
const data = <?php echo json_encode($chartData, JSON_UNESCAPED_UNICODE); ?>;

const ctx = document.getElementById('priceChart').getContext('2d');
new Chart(ctx, {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: 'Fi Price (c/kWh)',
            data: data,
            fill: false,
            tension: 0.3,
            spanGaps: true,
            borderColor: 'blue',
            backgroundColor: 'lightblue'
        }]
    },
    options: {
        responsive: true,
        scales: {
            x: { title: { display:true, text:'Time (<?php echo $timezoneDisplay ?>)' } },
                y: { title: { display:true, text:'Price (c/kWh)' } }
        }
    }
});
</script>

<!-- Second chart (bar chart) -->
<div class="chart-container">
    <h2>Price Bar Chart</h2>  
    <canvas id="priceBarChart" height="120"></canvas>
</div>

<script>
const ctxBar = document.getElementById('priceBarChart').getContext('2d');
new Chart(ctxBar, {
    type: 'bar', // <-- this makes it a bar chart
    data: {
        labels: labels, // reuse the same labels as line chart
        datasets: [{
            label: 'Fi Price (c/kWh)',
            data: data, // same data as line chart
            backgroundColor: 'rgba(100,149,237,0.6)', // cornflowerblue, semi-transparent
            borderColor: 'rgba(100,149,237,1)',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        scales: {
            x: { title: { display:true, text:'Time (<?php echo $timezoneDisplay ?>)' } },
                y: { title: { display:true, text:'Price (c/kWh)' } }
        }
    }
});
</script>

<h2>Raw Data</h2>

<table>
  <thead>
    <tr>
        <th>Timestamp</th>
        <th>Price (c/kWh)</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($cleanRows as $r): ?>
    <tr>
      <td><?php echo htmlspecialchars($r['timestamp']); ?></td>
      <td><?php echo $r['price'] !== null ? number_format($r['price'],2) : '-'; ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php else: ?>
<p>No data available. <?php if ($error) echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<!-- Auto-refresh every 5 minutes -->
<script>
setInterval(() => {
    fetch(window.location.href)
        .then(resp => resp.text())
        .then(html => {
            document.body.innerHTML = html;
        });
}, 300000); // 300000ms = 5 min
</script>

<script>
// Countdown to next update (cache expiry)
(() => {
    const nextUpdateMs = <?php echo json_encode($nextUpdateJsMs, JSON_NUMERIC_CHECK); ?>;
    const el = document.getElementById('nextUpdateTimer');
    if (!el) return;

    function fmt(ms) {
        if (ms <= 0) return 'due';
        const s = Math.floor(ms / 1000);
        const hh = Math.floor(s / 3600);
        const mm = Math.floor((s % 3600) / 60);
        const ss = s % 60;
        if (hh > 0) return hh + 'h ' + String(mm).padStart(2,'0') + 'm';
        if (mm > 0) return mm + 'm ' + String(ss).padStart(2,'0') + 's';
        return ss + 's';
    }

    let reloaded = false;
    let timerId = null;
    function update() {
        const diff = nextUpdateMs - Date.now();
        el.textContent = fmt(diff);

        // If we've passed more than 5 seconds after scheduled next update, reload once
        if (!reloaded && diff <= -5000) {
            reloaded = true;
            if (timerId) clearInterval(timerId);
            // reload the page to fetch fresh data
            try {
                location.reload();
            } catch (e) {
                // fallback: set location to self
                location.href = window.location.href;
            }
        }
    }

    update();
    timerId = setInterval(update, 1000);
})();
</script>

</body>
</html>