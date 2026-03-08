<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/EbayApi.php';
require_once dirname(__DIR__) . '/src/CategoryTreeLoader.php';

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
// Core hours = typical prime end times per marketplace (hour in local time, 0–23). Weekend = Sat/Sun gets broader window.
$coreHoursByMarketplace = [
    'EBAY_GB' => [
        'timezone' => 'Europe/London',
        'weekday' => [18, 22],
        'weekend' => [12, 22],
        'label' => 'weekdays 6–10pm, weekends 12–10pm UK',
    ],
    'EBAY_US' => [
        'timezone' => 'America/Los_Angeles',
        'weekday' => [14, 21],
        'weekend' => [12, 21],
        'label' => 'weekdays 2–9pm, weekends 12–9pm Pacific',
    ],
];
$buyingOptionFilters = [
    'all' => 'All',
    'AUCTION' => 'Auction only',
    'FIXED_PRICE' => 'Buy it now only',
];

$error = '';
$categoryList = []; // Flattened categories from API (id, name, path, level)
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

    $api = new EbayApi($clientId, $clientSecret);
    try {
        $loader = new CategoryTreeLoader($api);
        $categoryList = $loader->getFlattenedCategories($marketplaceUsed);
    } catch (Throwable $e) {
        $categoryList = [];
    }

    $validIds = $categoryList !== [] ? array_column($categoryList, 'id') : [$defaultCategory];
    $categoryInput = $_GET['category_ids'] ?? null;
    if (is_array($categoryInput)) {
        $categoryIdsSelected = array_values(array_intersect($validIds, array_map('strval', array_filter($categoryInput))));
    } elseif (is_string($categoryInput) && $categoryInput !== '') {
        $categoryIdsSelected = array_values(array_intersect($validIds, array_map('trim', explode(',', $categoryInput))));
    } else {
        $categoryIdsSelected = [$defaultCategory];
    }
    if ($categoryIdsSelected === []) {
        $categoryIdsSelected = [$defaultCategory];
    }
    $categoryIdsSelected = array_map('strval', $categoryIdsSelected);
    $categoryUsed = implode(',', $categoryIdsSelected);

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

$pageTitle = "eBay - what's ending soon?";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <script src="/vendor/moment.min.js"></script>
    <script>
    (function() {
        var stored = localStorage.getItem('theme');
        var prefersLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
        var theme = stored === 'light' || stored === 'dark' ? stored : (prefersLight ? 'light' : 'dark');
        document.documentElement.setAttribute('data-theme', theme);
    })();
    </script>
    <link href="/vendor/tom-select.css" rel="stylesheet">
    <link href="/css/app.css" rel="stylesheet">
    <script src="/vendor/tom-select.complete.min.js"></script>
</head>
<body>
    <header class="page-header">
        <h1><?= htmlspecialchars($pageTitle) ?></h1>
        <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Toggle theme">Light</button>
    </header>

    <form class="form" method="get" action="">
        <div class="form-row form-row--full">
            <div class="field field-categories">
                <label for="category_select">Categories</label>
                <select id="category_select" name="category_ids[]" multiple placeholder="Search categories…"></select>
                <span class="field-hint">Type to search; select one or more categories. Click × on a selected category to remove it.</span>
            </div>
        </div>
        <script type="application/json" id="category_list_data"><?= json_encode(['list' => $categoryList, 'selected' => $categoryIdsSelected]) ?></script>
        <div class="field">
            <label for="q">Keyword</label>
            <input type="text" id="q" name="q" value="<?= htmlspecialchars($queryUsed) ?>" placeholder="Keyword (optional)">
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
                        $hasZeroBids = $bidCount !== null && (int) $bidCount === 0;
                        $outsideCoreHours = false;
                        $coreHoursLabel = $coreHoursByMarketplace[$marketplaceUsed]['label'] ?? 'core hours';
                        if ($endTs > 0 && $endDate !== '' && isset($coreHoursByMarketplace[$marketplaceUsed])) {
                            $core = $coreHoursByMarketplace[$marketplaceUsed];
                            $dt = new DateTime($endDate);
                            $dt->setTimezone(new DateTimeZone($core['timezone']));
                            $hour = (int) $dt->format('G');
                            $dayOfWeek = (int) $dt->format('N');
                            $range = ($dayOfWeek >= 6) ? $core['weekend'] : $core['weekday'];
                            $outsideCoreHours = $hour < $range[0] || $hour > $range[1];
                        }
                        ?>
                        <tr<?= $hasZeroBids ? ' class="zero-bids' . ($outsideCoreHours ? ' outside-core' : '') . '"' : '' ?>>
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
                                <span class="price-item"><?= $priceFormatted ?></span>
                            </td>
                            <td class="col-total">
                                <?php if ($totalFormatted === 'n/a'): ?>
                                    <span class="total-na">n/a</span>
                                <?php else: ?>
                                    <span class="price-total"><?= $totalFormatted ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="col-buying"><?= htmlspecialchars($typeLabel) ?></td>
                            <td class="col-end <?= $isEndSoon ? 'end-soon' : '' ?>">
                                <?php
                                if ($endTs > 0 && $endDate !== '') {
                                    ?><span class="relative-time" data-end="<?= htmlspecialchars($endDate) ?>" title="<?= htmlspecialchars(date('M j, Y H:i', $endTs)) ?>"><?= htmlspecialchars(date('M j, Y H:i', $endTs)) ?></span><?php
                                    if ($outsideCoreHours) {
                                        echo ' <span class="outside-core" title="Ends outside core eBay hours (' . htmlspecialchars($coreHoursLabel) . ')" aria-label="Ends outside core eBay hours">★</span>';
                                    }
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
    (function() {
        var dataEl = document.getElementById('category_list_data');
        var list = [];
        var selected = [];
        if (dataEl) {
            try {
                var data = JSON.parse(dataEl.textContent);
                list = data.list || [];
                selected = data.selected || [];
            } catch (e) {}
        }
        var options = list.map(function(c) { return { value: c.id, text: c.path }; });
        var el = document.getElementById('category_select');
        if (el && typeof TomSelect !== 'undefined') {
            new TomSelect(el, {
                options: options,
                items: selected,
                valueField: 'value',
                labelField: 'text',
                searchField: ['text'],
                maxItems: null,
                maxOptions: 200,
                placeholder: 'Search categories…',
                plugins: { remove_button: { title: 'Remove category' } }
            });
        }
    })();
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
