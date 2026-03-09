<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/RateLimiter.php';
require_once dirname(__DIR__) . '/src/EbayApi.php';

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && $_SERVER['HTTP_X_FORWARDED_FOR'] !== '') {
    $clientIp = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
}

$renderRateLimitPage = function (int $statusCode) use ($rateLimitContactEmail, $clientIp): void {
    http_response_code($statusCode);
    if ($statusCode === 429) {
        header('Retry-After: 60');
    }
    header('Content-Type: text/html; charset=utf-8');
    $email = $rateLimitContactEmail !== '' ? $rateLimitContactEmail : null;
    $pageTitle = 'Rate limit – eBay Bargains';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <script>
    (function() {
        var stored = localStorage.getItem('theme');
        var prefersLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
        var theme = stored === 'light' || stored === 'dark' ? stored : (prefersLight ? 'light' : 'dark');
        document.documentElement.setAttribute('data-theme', theme);
    })();
    </script>
    <link href="/css/app.css" rel="stylesheet">
</head>
<body>
    <header class="page-header">
        <h1><?= htmlspecialchars($pageTitle) ?></h1>
    </header>
    <div class="rate-limit-box">
        <p class="rate-limit-title">Too many requests</p>
        <p class="rate-limit-desc">You've hit the limit (10 per minute, 100 per hour). Please slow down or try again in a minute.</p>
        <p class="rate-limit-ip">Your IP: <code><?= htmlspecialchars($clientIp) ?></code></p>
        <?php if ($email !== null): ?>
        <p class="rate-limit-contact">Need higher limits? Contact <a href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?></a> to request an increase.</p>
        <?php endif; ?>
        <p class="rate-limit-back"><a href="/">← Back to search</a></p>
    </div>
</body>
</html>
    <?php
};

// Demo: show rate-limit message without actually enforcing (for testing the 429 page)
if (isset($_GET['demo_limit']) && $_GET['demo_limit'] !== '' && $_GET['demo_limit'] !== '0') {
    $renderRateLimitPage(200);
    exit;
}

$rateLimiter = new RateLimiter($unlimitedIps, dirname(__DIR__) . '/data/rate_limit');
if (!$rateLimiter->allowRequest($clientIp)) {
    $renderRateLimitPage(429);
    exit;
}

// Default category: Laptops & Netbooks
$defaultCategory = '177';
$defaultMaxPrice = '30';
$defaultLocation = 'GB';   // UK Only
$defaultCurrency = 'GBP';
$pageSize = 200;  // items per page (eBay max 200 per request)
$maxOffset = 9800; // eBay Browse API returns up to 10,000 items total; last page starts at 9800 for pageSize 200

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
// When "exclude collection only" is on and location is Any, use this country for deliveryCountry (items that can be shipped there = excludes pickup-only).
$marketplaceDeliveryCountry = [
    'EBAY_GB' => 'GB',
    'EBAY_US' => 'US',
];

$error = '';
$items = [];
$reserveStatusByItemId = []; // itemId => reservePriceMet (from getItems bulk call)
$total = 0;
$queryUsed = '';
$categoryUsed = $defaultCategory;
$categoryIdsSelected = [$defaultCategory];
$maxPriceUsed = $defaultMaxPrice;
$locationUsed = $defaultLocation;
$currencyUsed = $defaultCurrency;
$marketplaceUsed = 'EBAY_GB';
$buyingOptionUsed = 'all';
$excludeCollectionOnly = false;
$offset = 0;

if ($clientId === '' || $clientSecret === '') {
    $error = 'Set EBAY_CLIENT_ID and EBAY_CLIENT_SECRET in .env or environment. See README.';
} else {
    $queryUsed = trim((string) ($_GET['q'] ?? $_GET['search'] ?? ''));
    $maxPriceUsed = trim((string) ($_GET['max_price'] ?? $defaultMaxPrice));
    $locationUsed = $_GET['location'] ?? $defaultLocation;
    $currencyUsed = $_GET['currency'] ?? $defaultCurrency;
    $marketplaceUsed = $_GET['marketplace'] ?? 'EBAY_GB';
    $buyingOptionUsed = $_GET['buying_option'] ?? 'all';
    $excludeCollectionOnly = isset($_GET['exclude_collection_only']) && $_GET['exclude_collection_only'] !== '' && $_GET['exclude_collection_only'] !== '0';
    $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
    $offset = min($offset, $maxOffset);

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

    // Category IDs from request (validated by API; list is loaded from static JSON in frontend).
    // eBay Browse API allows search without category (category_ids is optional); we allow no category when form is submitted without any selected.
    $categoryInput = $_GET['category_ids'] ?? null;
    $isFirstLoad = ($_GET === []);
    if (is_array($categoryInput)) {
        $categoryIdsSelected = array_values(array_map('strval', array_filter($categoryInput)));
    } elseif (is_string($categoryInput) && $categoryInput !== '') {
        $categoryIdsSelected = array_values(array_map('trim', explode(',', $categoryInput)));
        $categoryIdsSelected = array_filter($categoryIdsSelected);
    } elseif ($isFirstLoad) {
        $categoryIdsSelected = [$defaultCategory];
    } else {
        // Form submitted with no category_ids (user cleared all or didn't select any) = search all categories
        $categoryIdsSelected = [];
    }
    $categoryIdsSelected = array_map('strval', $categoryIdsSelected);
    $categoryUsed = $categoryIdsSelected === [] ? '' : implode(',', $categoryIdsSelected);

    $maxPriceInt = (int) $maxPriceUsed;
    if ($maxPriceInt <= 0) {
        $maxPriceInt = 500;
    }

    $buyingOptionFilter = $buyingOptionUsed === 'all'
        ? 'buyingOptions:{AUCTION|FIXED_PRICE}'
        : 'buyingOptions:{' . $buyingOptionUsed . '}';
    $filterParts = [$buyingOptionFilter, 'price:[..' . $maxPriceInt . ']', 'priceCurrency:' . $currencyUsed];
    // deliveryCountry = only items that can be shipped to that country (excludes collection-only / pickup-only).
    if ($locationUsed !== '') {
        $filterParts[] = 'deliveryCountry:' . $locationUsed;
    } elseif ($excludeCollectionOnly && isset($marketplaceDeliveryCountry[$marketplaceUsed])) {
        $filterParts[] = 'deliveryCountry:' . $marketplaceDeliveryCountry[$marketplaceUsed];
    }

    $searchLogFile = dirname(__DIR__) . '/data/search.log';
    $searchLogLine = gmdate('Y-m-d\TH:i:s\Z') . "\t" . $clientIp . "\t" . str_replace(["\r", "\n"], ' ', $_SERVER['QUERY_STRING'] ?? '') . "\n";
    @file_put_contents($searchLogFile, $searchLogLine, FILE_APPEND | LOCK_EX);

    $hasSearchCriteria = $queryUsed !== '' || $categoryUsed !== '';
    if ($hasSearchCriteria) {
        try {
            $params = [
                'q' => $queryUsed,
                'category_ids' => $categoryUsed,
                'sort' => 'endingSoonest',
                'limit' => (string) $pageSize,
                'offset' => (string) $offset,
                'filter' => implode(',', $filterParts),
            ];
            $result = $api->search($params, $marketplaceUsed);
            $items = $result['itemSummaries'] ?? [];
            $total = (int) ($result['total'] ?? 0);

            // API can still return pickup-only items when deliveryCountry is set; exclude them client-side.
            if ($excludeCollectionOnly && $items !== []) {
                $items = array_values(array_filter($items, static function (array $item): bool {
                    $shippingOptions = $item['shippingOptions'] ?? [];
                    return is_array($shippingOptions) && $shippingOptions !== [];
                }));
            }

            // One bulk getItems call for reserve status: top 10 items only, and only 0-bid auctions.
            $reserveStatusByItemId = [];
            $top10 = array_slice($items, 0, 10);
            $idsToFetch = [];
            foreach ($top10 as $it) {
                $opts = $it['buyingOptions'] ?? [];
                $isAuction = in_array('AUCTION', $opts, true);
                $bids = $it['bidCount'] ?? null;
                $zeroBids = $bids !== null && (int) $bids === 0;
                if ($isAuction && $zeroBids) {
                    $id = $it['itemId'] ?? '';
                    if ($id !== '') {
                        $idsToFetch[] = $id;
                    }
                }
            }
            if ($idsToFetch !== []) {
                try {
                    $fullItems = $api->getItems($idsToFetch, $marketplaceUsed);
                    foreach ($fullItems as $id => $full) {
                        if (array_key_exists('reservePriceMet', $full)) {
                            $reserveStatusByItemId[$id] = $full['reservePriceMet'];
                        }
                    }
                } catch (Throwable $e) {
                    // Non-fatal: reserve column stays unknown; don't replace $error
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
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
        <div class="header-actions">
            <a href="https://github.com/mfalkus/ebay-bargains" class="github-link" target="_blank" rel="noopener noreferrer" aria-label="View on GitHub"><svg class="github-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg></a>
            <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Toggle theme">Light</button>
        </div>
    </header>

    <form class="form" method="get" action="">
        <div class="form-row form-row--full">
            <div class="field field-categories" data-marketplace="<?= htmlspecialchars($marketplaceUsed) ?>" data-selected="<?= htmlspecialchars(json_encode($categoryIdsSelected)) ?>">
                <label for="category_select">Categories</label>
                <select id="category_select" name="category_ids[]" multiple placeholder="Search categories…"></select>
                <span class="field-hint">Type to search; select one or more categories, or leave empty to search all. Click × on a selected category to remove it.</span>
            </div>
        </div>
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
        <div class="field field--checkbox">
            <input type="checkbox" id="exclude_collection_only" name="exclude_collection_only" value="1" <?= $excludeCollectionOnly ? 'checked' : '' ?>>
            <label for="exclude_collection_only">Exclude collection only</label>
            <span class="field-hint">Only show items that can be shipped (hide pickup-only listings).</span>
        </div>
        <div class="field">
            <button type="submit">Search (ending soon)</button>
        </div>
    </form>

    <?php if ($error !== ''): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($error === '' && ($queryUsed !== '' || $categoryUsed !== '')): ?>
        <?php
        $itemsCount = count($items);
        $from = $total > 0 ? $offset + 1 : 0;
        $to = $offset + $itemsCount;
        $totalPages = $pageSize > 0 ? (int) ceil(min($total, $maxOffset + $pageSize) / $pageSize) : 1;
        $currentPage = $pageSize > 0 ? (int) floor($offset / $pageSize) + 1 : 1;
        $baseQuery = array_filter([
            'q' => $queryUsed !== '' ? $queryUsed : null,
            'category_ids' => $categoryIdsSelected,
            'max_price' => $maxPriceUsed,
            'location' => $locationUsed,
            'currency' => $currencyUsed,
            'marketplace' => $marketplaceUsed,
            'buying_option' => $buyingOptionUsed,
            'exclude_collection_only' => $excludeCollectionOnly ? '1' : null,
        ], static function ($v) { return $v !== null && $v !== '' && $v !== []; });
        $paginateUrl = static function (int $newOffset) use ($baseQuery): string {
            $q = $baseQuery;
            if ($newOffset > 0) {
                $q['offset'] = (string) $newOffset;
            }
            return '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
        };
        $paginationNav = '';
        if ($total > $pageSize) {
            $prevLink = $offset > 0
                ? '<a href="' . htmlspecialchars($paginateUrl(max(0, $offset - $pageSize))) . '" class="pagination-link pagination-prev">← Previous</a>'
                : '<span class="pagination-link pagination-prev is-disabled" aria-disabled="true">← Previous</span>';
            $nextLink = ($offset + $pageSize < $total && $offset < $maxOffset)
                ? '<a href="' . htmlspecialchars($paginateUrl($offset + $pageSize)) . '" class="pagination-link pagination-next">Next →</a>'
                : '<span class="pagination-link pagination-next is-disabled" aria-disabled="true">Next →</span>';
            $paginationNav = '<nav class="pagination" aria-label="Results pagination">' . $prevLink . '<span class="pagination-info">Page ' . (int) $currentPage . ' of ' . (int) $totalPages . '</span>' . $nextLink . '</nav>';
        }
        ?>
        <div class="meta">
            <?= $itemsCount ?> items shown (<?= $from ?>–<?= $to ?> of <?= $total ?>)<?= $total > $pageSize ? ' · Page ' . $currentPage . ' of ' . $totalPages : '' ?>. Sorted by ending soonest. <?= $categoryUsed !== '' ? (count($categoryIdsSelected) > 1 ? 'Categories ' : 'Category ') . htmlspecialchars($categoryUsed) : 'All categories' ?>, max <?= htmlspecialchars($currencyUsed) ?> <?= htmlspecialchars($maxPriceUsed) ?><?= $locationUsed !== '' ? ', ' . htmlspecialchars($locations[$locationUsed]) : '' ?><?= $excludeCollectionOnly ? ', collection-only excluded' : '' ?>.
        </div>
        <?= $paginationNav ?>
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
                        $reservePriceMet = $reserveStatusByItemId[$itemId] ?? $item['reservePriceMet'] ?? null;
                        $isAuctionReserveNotMet = $isAuction && $reservePriceMet === false;
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
                        $rowClasses = array_filter([
                            $hasZeroBids ? 'zero-bids' . ($outsideCoreHours ? ' outside-core' : '') : null,
                            $isAuctionReserveNotMet ? 'reserve-not-met' : null,
                        ]);
                        ?>
                        <tr<?= $rowClasses !== [] ? ' class="' . implode(' ', $rowClasses) . '"' : '' ?>>
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
        <?= $paginationNav ?>
        <?php unset($items); ?>
        <?php if ($itemsCount === 0 && $error === ''): ?>
            <p class="meta">No items found. Try a different keyword, category, or max price.</p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($error === '' && $queryUsed === '' && $categoryUsed === ''): ?>
        <p class="meta">Enter a keyword and/or select one or more categories, then click Search to load listings sorted by ending soonest.</p>
    <?php endif; ?>

    <?php
    $isUnlimitedIp = in_array(trim($clientIp), $unlimitedIps, true);
    $usage = $rateLimiter->getUsage($clientIp);
    ?>
    <p class="rate-limit-footer">
        Requests: <?= $isUnlimitedIp ? 'unlimited' : $usage['perMinute'] . '/' . RateLimiter::LIMIT_PER_MINUTE . ' (min), ' . $usage['perHour'] . '/' . RateLimiter::LIMIT_PER_HOUR . ' (hr)' ?>
        · Your IP: <code><?= htmlspecialchars($clientIp) ?></code>
        · Limit: <?= $isUnlimitedIp ? 'whitelisted' : 'applied' ?>
    </p>

    <script>
    (function() {
        var wrapper = document.querySelector('.field-categories');
        var el = document.getElementById('category_select');
        if (!wrapper || !el || typeof TomSelect === 'undefined') return;
        var marketplace = wrapper.getAttribute('data-marketplace') || 'EBAY_GB';
        var selected = [];
        try {
            var raw = wrapper.getAttribute('data-selected');
            if (raw) selected = JSON.parse(raw);
        } catch (e) {}
        fetch('/data/categories_' + marketplace + '.json')
            .then(function(r) { return r.ok ? r.json() : []; })
            .catch(function() { return []; })
            .then(function(list) {
                var options = (list || []).map(function(c) { return { value: c.id, text: c.path }; });
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
            });
    })();
    (function() {
        function fromNow(isoDate) {
            var then = new Date(isoDate).getTime();
            var now = Date.now();
            var s = Math.floor((then - now) / 1000);
            var abs = Math.abs(s);
            if (abs < 45) return 'a few seconds ' + (s >= 0 ? 'from now' : 'ago');
            if (abs < 90) return (s >= 0 ? 'a minute from now' : 'a minute ago');
            if (abs < 3600) return Math.round(abs / 60) + ' minutes ' + (s >= 0 ? 'from now' : 'ago');
            if (abs < 5400) return (s >= 0 ? 'an hour from now' : 'an hour ago');
            if (abs < 86400) return Math.round(abs / 3600) + ' hours ' + (s >= 0 ? 'from now' : 'ago');
            if (abs < 129600) return (s >= 0 ? 'a day from now' : 'a day ago');
            if (abs < 2592000) return Math.round(abs / 86400) + ' days ' + (s >= 0 ? 'from now' : 'ago');
            if (abs < 3888000) return (s >= 0 ? 'a month from now' : 'a month ago');
            if (abs < 31536000) return Math.round(abs / 2592000) + ' months ' + (s >= 0 ? 'from now' : 'ago');
            if (abs < 47304000) return (s >= 0 ? 'a year from now' : 'a year ago');
            return Math.round(abs / 31536000) + ' years ' + (s >= 0 ? 'from now' : 'ago');
        }
        document.querySelectorAll('.relative-time').forEach(function(el) {
            var end = el.getAttribute('data-end');
            if (end) el.textContent = fromNow(end);
        });
    })();
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
