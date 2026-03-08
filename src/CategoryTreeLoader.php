<?php

declare(strict_types=1);

/**
 * Loads eBay category tree for a marketplace, with file cache.
 * Flattens to list of [ id, name, path, level ] for UI.
 */
final class CategoryTreeLoader
{
    private const CACHE_TTL = 86400; // 24 hours
    private const CACHE_DIR = __DIR__ . '/../cache';

    public function __construct(
        private EbayApi $api,
    ) {}

    /**
     * @return list<array{id: string, name: string, path: string, level: int}>
     */
    public function getFlattenedCategories(string $marketplace): array
    {
        $cacheKey = 'ebay_categories_' . preg_replace('/[^A-Z0-9_]/', '', $marketplace);
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $cacheFile = self::CACHE_DIR . '/categories_' . preg_replace('/[^A-Z0-9_]/', '', $marketplace) . '.json';
        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0755, true);
        }
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < self::CACHE_TTL) {
            $data = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($data['items'] ?? null)) {
                $items = $this->stripRootFromPaths($data['items']);
                if (function_exists('apcu_store')) {
                    apcu_store($cacheKey, $items, self::CACHE_TTL);
                }
                return $items;
            }
        }
        $treeIdResp = $this->api->getDefaultCategoryTreeId($marketplace);
        $treeId = $treeIdResp['categoryTreeId'] ?? null;
        if ($treeId === null) {
            throw new RuntimeException('No category tree ID for ' . $marketplace);
        }
        $tree = $this->api->getCategoryTree($treeId);
        $root = $tree['rootCategoryNode'] ?? null;
        if ($root === null) {
            throw new RuntimeException('Invalid category tree response');
        }
        $items = $this->flattenNode($root, '');
        $cacheData = [
            'cachedAt' => time(),
            'categoryTreeVersion' => $tree['categoryTreeVersion'] ?? '',
            'items' => $items,
        ];
        file_put_contents($cacheFile, json_encode($cacheData, JSON_UNESCAPED_UNICODE));
        $flattened = $this->stripRootFromPaths($items);
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $flattened, self::CACHE_TTL);
        }
        return $flattened;
    }

    /**
     * Remove leading "Root › " or "Root > " from all category paths (handles cached data and API variation).
     * @param list<array{id: string, name: string, path: string, level: int}> $items
     * @return list<array{id: string, name: string, path: string, level: int}>
     */
    private function stripRootFromPaths(array $items): array
    {
        foreach ($items as &$item) {
            $item['path'] = preg_replace('#^Root\s*[›>]\s*#u', '', $item['path'] ?? '');
        }
        return $items;
    }

    /**
     * @param array<string, mixed> $node
     * @return list<array{id: string, name: string, path: string, level: int}>
     */
    private function flattenNode(array $node, string $parentPath): array
    {
        $category = $node['category'] ?? [];
        $id = (string) ($category['categoryId'] ?? '');
        $name = (string) ($category['categoryName'] ?? '');
        $level = (int) ($node['categoryTreeNodeLevel'] ?? 0);
        $path = $parentPath === '' ? $name : $parentPath . ' › ' . $name;
        $out = [];
        if ($id !== '' && $id !== '0') {
            $out[] = ['id' => $id, 'name' => $name, 'path' => $path, 'level' => $level];
        }
        $children = $node['childCategoryTreeNodes'] ?? [];
        $pathForChildren = ($id === '0' || $name === 'Root') ? $parentPath : $path;
        foreach ($children as $child) {
            foreach ($this->flattenNode($child, $pathForChildren) as $item) {
                $out[] = $item;
            }
        }
        return $out;
    }
}
