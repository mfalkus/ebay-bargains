<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/EbayApi.php';

// Default category: Laptops & Netbooks
$defaultCategory = '177';
$defaultMaxPrice = '30';
$defaultLocation = 'GB';   // UK Only
$defaultCurrency = 'GBP';
$limit = 200;

$locations = [
    'GB' => 'UK Only',
    'US' => 'US Only',
    ''   => 'Any',
];
$currencies = ['GBP' => 'GBP', 'USD' => 'USD', 'EUR' => 'EUR'];
$currencySymbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
$marketplaces = ['EBAY_GB' => 'eBay UK', 'EBAY_US' => 'eBay US'];
$buyingOptionFilters = [
    'all' => 'All',
    'AUCTION' => 'Auction only',
    'FIXED_PRICE' => 'Buy it now only',
];

// Technical categories (IDs can vary by marketplace; these are common on eBay US/UK)
$techCategories = [
    '177'     => 'PC Laptops & Netbooks',
    '175672'  => 'Laptops & Netbooks',
    '179'     => 'PC Desktops & All-In-One',
    '171485'  => 'Tablets & eBook Readers',
    '9355'    => 'Cell Phones & Smartphones',
    '111422'  => 'Apple Laptops & Notebooks',
    '164'     => 'Computer Components (CPUs, etc.)',
    '56083'   => 'Internal Hard Disk Drives',
    '165'     => 'Computer Drives & Storage',
    '3673'    => 'Networking',
    '182067'  => 'Computer Cables & Connectors',
    '159260'  => 'Computer Memory (RAM)',
];

$error = '';
$items = [];
$total = 0;
$queryUsed = '';
$categoryUsed = $defaultCategory;
$categoryIdsSelected = [$defaultCategory];
$maxPriceUsed = $defaultMaxPrice;
$locationUsed = $defaultLocation;
$currencyUsed = $defaultCurrency;
$marketplaceUsed = 'EBAY_GB';
$buyingOptionUsed = 'all';

if ($clientId === '' || $clientSecret === '') {
    $error = 'Set EBAY_CLIENT_ID and EBAY_CLIENT_SECRET in .env or environment. See README.';
} else {
    $queryUsed = trim((string) ($_GET['q'] ?? $_GET['search'] ?? ''));
    $categoryInput = $_GET['category_ids'] ?? null;
    if (is_array($categoryInput)) {
        $categoryIdsSelected = array_values(array_intersect(array_keys($techCategories), array_filter($categoryInput)));
    } elseif (is_string($categoryInput) && $categoryInput !== '') {
        $categoryIdsSelected = array_intersect(explode(',', $categoryInput), array_keys($techCategories));
        $categoryIdsSelected = array_values(array_filter($categoryIdsSelected));
    } else {
        $categoryIdsSelected = [$defaultCategory];
    }
    if ($categoryIdsSelected === []) {
        $categoryIdsSelected = [$defaultCategory];
    }
    $categoryIdsSelected = array_map('strval', $categoryIdsSelected);
    $categoryUsed = implode(',', $categoryIdsSelected);
    $maxPriceUsed = trim((string) ($_GET['max_price'] ?? $defaultMaxPrice));
    $locationUsed = $_GET['location'] ?? $defaultLocation;
    $currencyUsed = $_GET['currency'] ?? $defaultCurrency;
    $marketplaceUsed = $_GET['marketplace'] ?? 'EBAY_GB';
    $buyingOptionUsed = $_GET['buying_option'] ?? 'all';

    if (!array_key_exists($locationUsed, $locations)) {
        $locationUsed = $defaultLocation;
    }
    if (!array_key_exists($currencyUsed, $currencies)) {
        $currencyUsed = $defaultCurrency;
    }
    if (!array_key_exists($marketplaceUsed, $marketplaces)) {
        $marketplaceUsed = 'EBAY_GB';
    }
    if (!array_key_exists($buyingOptionUsed, $buyingOptionFilters)) {
        $buyingOptionUsed = 'all';
    }

    $maxPriceInt = (int) $maxPriceUsed;
    if ($maxPriceInt <= 0) {
        $maxPriceInt = 500;
    }

    $buyingOptionFilter = $buyingOptionUsed === 'all'
        ? 'buyingOptions:{AUCTION|FIXED_PRICE}'
        : 'buyingOptions:{' . $buyingOptionUsed . '}';
    $filterParts = [$buyingOptionFilter, 'price:[..' . $maxPriceInt . ']', 'priceCurrency:' . $currencyUsed];
    if ($locationUsed !== '') {
        $filterParts[] = 'deliveryCountry:' . $locationUsed;
    }

    try {
        $api = new EbayApi($clientId, $clientSecret);
        $params = [
            'q' => $queryUsed,
            'category_ids' => $categoryUsed,
            'sort' => 'endingSoonest',
            'limit' => (string) min($limit, 200),
            'filter' => implode(',', $filterParts),
        ];
        $result = $api->search($params, $marketplaceUsed);
        $items = $result['itemSummaries'] ?? [];
        $total = (int) ($result['total'] ?? 0);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Cheap tech ending soon';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.30.1/moment.min.js" crossorigin="anonymous"></script>
    <script>
    (function() {
        var stored = localStorage.getItem('theme');
        var prefersLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
        var theme = stored === 'light' || stored === 'dark' ? stored : (prefersLight ? 'light' : 'dark');
        document.documentElement.setAttribute('data-theme', theme);
    })();
    </script>
    <style>
        :root {
            --bg: #0f0f12;
            --surface: #18181c;
            --border: #2a2a30;
            --text: #e4e4e7;
            --muted: #71717a;
            --accent: #22c55e;
            --link: #38bdf8;
            --danger: #f87171;
        }
        [data-theme="light"] {
            --bg: #f4f4f5;
            --surface: #ffffff;
            --border: #e4e4e7;
            --text: #18181b;
            --muted: #71717a;
            --accent: #16a34a;
            --link: #0284c7;
            --danger: #dc2626;
        }
        [data-theme="light"] .col-thumb img { background: #e4e4e7; }
        [data-theme="light"] tr:hover td { background: rgba(22,163,74,0.08); }
        * { box-sizing: border-box; }
        body {
            font-family: 'JetBrains Mono', 'SF Mono', 'Consolas', monospace;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 1rem;
            font-size: 13px;
            line-height: 1.4;
        }
        h1 {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0 0 1rem;
            color: var(--accent);
        }
        .form {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: flex-end;
            margin-bottom: 1rem;
            padding: 1rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
        }
        .field { display: flex; flex-direction: column; gap: 0.25rem; }
        .field label { color: var(--muted); font-size: 11px; text-transform: uppercase; }
        .field-hint { color: var(--muted); font-size: 11px; display: block; margin-top: 0.25rem; }
        .field-categories select[multiple] { min-width: 220px; }
        .form input, .form select {
            background: var(--bg);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 0.5rem 0.6rem;
            border-radius: 4px;
            font-family: inherit;
            font-size: 13px;
        }
        .form input:focus, .form select:focus {
            outline: none;
            border-color: var(--accent);
        }
        button {
            background: var(--accent);
            color: var(--bg);
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
        button:hover { filter: brightness(1.1); }
        .error {
            background: rgba(248,113,113,0.15);
            border: 1px solid var(--danger);
            color: var(--danger);
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
        }
        .meta {
            color: var(--muted);
            margin-bottom: 0.75rem;
        }
        .table-wrap {
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--surface);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        th, td {
            padding: 0.5rem 0.6rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }
        th {
            color: var(--muted);
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 600;
        }
        tr:hover td { background: rgba(34,197,94,0.06); }
        .col-thumb { width: 70px; }
        .col-thumb img {
            width: 56px;
            height: 56px;
            object-fit: contain;
            background: var(--bg);
            border-radius: 4px;
        }
        .col-title { width: 28%; }
        .col-title a {
            color: var(--link);
            text-decoration: none;
        }
        .col-title a:hover { text-decoration: underline; }
        .col-price { width: 90px; text-align: right; }
        .col-total { width: 90px; text-align: right; }
        .col-buying { width: 85px; }
        .col-end { width: 140px; }
        .col-bids { width: 60px; text-align: center; }
        .col-condition { width: 80px; }
        .col-link { width: 50px; text-align: center; }
        .price { font-weight: 600; color: var(--accent); }
        .total-na { color: var(--muted); }
        .end-soon { color: var(--danger); }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        .page-header h1 { margin: 0; }
        .theme-toggle {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 0.4rem 0.75rem;
            border-radius: 6px;
            font-family: inherit;
            font-size: 12px;
            cursor: pointer;
        }
        .theme-toggle:hover { border-color: var(--accent); }
    </style>
</head>
<body>
    <header class="page-header">
        <h1><?= htmlspecialchars($pageTitle) ?></h1>
        <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Toggle theme">Light</button>
    </header>

    <form class="form" method="get" action="">
        <div class="field">
            <label for="q">Keyword</label>
            <input type="text" id="q" name="q" value="<?= htmlspecialchars($queryUsed) ?>" placeholder="Keyword (optional)">
        </div>
        <div class="field field-categories">
            <label for="category_ids">Categories</label>
            <select id="category_ids" name="category_ids[]" multiple size="6">
                <?php foreach ($techCategories as $id => $label): ?>
                    <?php $idStr = (string) $id; ?>
                    <option value="<?= htmlspecialchars($idStr) ?>" <?= in_array($idStr, $categoryIdsSelected, true) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?> (<?= htmlspecialchars($idStr) ?>)</option>
                <?php endforeach; ?>
            </select>
            <span class="field-hint">Ctrl/Cmd+click to select multiple</span>
        </div>
        <div class="field">
            <label for="max_price">Max price</label>
            <input type="number" id="max_price" name="max_price" value="<?= htmlspecialchars($maxPriceUsed) ?>" min="0.01" step="any" placeholder="e.g. 30">
        </div>
        <div class="field">
            <label for="location">Location</label>
            <select id="location" name="location">
                <?php foreach ($locations as $code => $label): ?>
                    <option value="<?= htmlspecialchars($code) ?>" <?= $locationUsed === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="currency">Currency</label>
            <select id="currency" name="currency">
                <?php foreach ($currencies as $code => $label): ?>
                    <option value="<?= htmlspecialchars($code) ?>" <?= $currencyUsed === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="marketplace">Marketplace</label>
            <select id="marketplace" name="marketplace">
                <?php foreach ($marketplaces as $code => $label): ?>
                    <option value="<?= htmlspecialchars($code) ?>" <?= $marketplaceUsed === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="buying_option">Auction / Buy it now</label>
            <select id="buying_option" name="buying_option">
                <?php foreach ($buyingOptionFilters as $code => $label): ?>
                    <option value="<?= htmlspecialchars($code) ?>" <?= $buyingOptionUsed === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <button type="submit">Search (ending soon)</button>
        </div>
    </form>

    <?php if ($error !== ''): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($error === '' && ($queryUsed !== '' || $categoryUsed !== '')): ?>
        <div class="meta">
            <?= count($items) ?> items shown (total <?= $total ?>). Sorted by ending soonest. <?= count($categoryIdsSelected) > 1 ? 'Categories ' : 'Category ' ?><?= htmlspecialchars($categoryUsed) ?>, max <?= htmlspecialchars($currencyUsed) ?> <?= htmlspecialchars($maxPriceUsed) ?><?= $locationUsed !== '' ? ', ' . htmlspecialchars($locations[$locationUsed]) : '' ?>.
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th class="col-thumb">Image</th>
                        <th class="col-title">Title</th>
                        <th class="col-price">Price / Bid</th>
                        <th class="col-total">Total price</th>
                        <th class="col-buying">Type</th>
                        <th class="col-end">Ends</th>
                        <th class="col-bids">Bids</th>
                        <th class="col-condition">Condition</th>
                        <th class="col-link">Open</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $itemId = $item['itemId'] ?? '';
                        $title = $item['title'] ?? '';
                        $url = $item['itemWebUrl'] ?? ('https://www.ebay.com/itm/' . $itemId);
                        $img = $item['image']['imageUrl'] ?? '';
                        $price = $item['price'] ?? null;
                        $bidPrice = $item['currentBidPrice'] ?? null;
                        $displayPrice = $bidPrice ?? $price;
                        $priceVal = $displayPrice['value'] ?? '';
                        $priceCur = $displayPrice['currency'] ?? 'USD';
                        $endDate = $item['itemEndDate'] ?? '';
                        $endTs = $endDate !== '' ? strtotime($endDate) : 0;
                        $isEndSoon = $endTs > 0 && $endTs < time() + 3600;
                        $bidCount = $item['bidCount'] ?? null;
                        $buyingOptions = $item['buyingOptions'] ?? [];
                        $isAuction = in_array('AUCTION', $buyingOptions, true);
                        $isFixed = in_array('FIXED_PRICE', $buyingOptions, true);
                        $typeLabel = $isAuction && $isFixed ? 'Auction / BIN' : ($isAuction ? 'Auction' : 'Buy it now');
                        $currencySym = $currencySymbols[$priceCur] ?? $priceCur . ' ';
                        $priceFormatted = $priceVal !== '' ? $currencySym . number_format((float) $priceVal, 2) : '—';
                        $shippingCostVal = null;
                        $shippingOptions = $item['shippingOptions'] ?? [];
                        foreach ($shippingOptions as $opt) {
                            $cost = $opt['shippingCost'] ?? null;
                            if ($cost !== null && isset($cost['value']) && $cost['value'] !== '') {
                                $shippingCostVal = (float) $cost['value'];
                                break;
                            }
                        }
                        if ($shippingCostVal !== null && $priceVal !== '') {
                            $totalVal = (float) $priceVal + $shippingCostVal;
                            $totalFormatted = $currencySym . number_format($totalVal, 2);
                        } else {
                            $totalFormatted = 'n/a';
                        }
                        $condition = $item['condition'] ?? $item['conditionId'] ?? '—';
                        ?>
                        <tr>
                            <td class="col-thumb">
                                <?php if ($img !== ''): ?>
                                    <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener"><img src="<?= htmlspecialchars($img) ?>" alt="" loading="lazy"></a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="col-title">
                                <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($title) ?></a>
                            </td>
                            <td class="col-price">
                                <span class="price"><?= $priceFormatted ?></span>
                            </td>
                            <td class="col-total">
                                <?php if ($totalFormatted === 'n/a'): ?>
                                    <span class="total-na">n/a</span>
                                <?php else: ?>
                                    <span class="price"><?= $totalFormatted ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="col-buying"><?= htmlspecialchars($typeLabel) ?></td>
                            <td class="col-end <?= $isEndSoon ? 'end-soon' : '' ?>">
                                <?php
                                if ($endTs > 0 && $endDate !== '') {
                                    ?><span class="relative-time" data-end="<?= htmlspecialchars($endDate) ?>" title="<?= htmlspecialchars(date('M j, Y H:i', $endTs)) ?>"><?= htmlspecialchars(date('M j, Y H:i', $endTs)) ?></span><?php
                                    if ($isEndSoon) {
                                        echo ' <span class="end-soon">(soon)</span>';
                                    }
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td class="col-bids"><?= $bidCount !== null ? (int) $bidCount : '—' ?></td>
                            <td class="col-condition"><?= htmlspecialchars((string) $condition) ?></td>
                            <td class="col-link">
                                <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener">→</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($items) === 0 && $error === ''): ?>
            <p class="meta">No items found. Try a different keyword, category, or max price.</p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($error === '' && $queryUsed === '' && !isset($_GET['category_ids']) && !isset($_GET['q'])): ?>
        <p class="meta">Pick categories and click Search to load listings sorted by ending soonest. Add a keyword to narrow results.</p>
    <?php endif; ?>

    <script>
    document.querySelectorAll('.relative-time').forEach(function(el) {
        var end = el.getAttribute('data-end');
        if (end && typeof moment !== 'undefined') {
            el.textContent = moment.utc(end).fromNow();
        }
    });
    (function() {
        var btn = document.getElementById('theme-toggle');
        function applyTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);
            btn.textContent = theme === 'dark' ? 'Light' : 'Dark';
            btn.setAttribute('aria-label', theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
        }
        function toggleTheme() {
            var current = document.documentElement.getAttribute('data-theme');
            applyTheme(current === 'dark' ? 'light' : 'dark');
        }
        if (btn) {
            var current = document.documentElement.getAttribute('data-theme');
            btn.textContent = current === 'dark' ? 'Light' : 'Dark';
            btn.setAttribute('aria-label', current === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
            btn.addEventListener('click', toggleTheme);
        }
    })();
    </script>
</body>
</html>
