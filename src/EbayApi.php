<?php

declare(strict_types=1);

/**
 * Simple eBay Browse API client using OAuth client credentials and item_summary/search.
 * No SDK dependency; uses curl only.
 */
final class EbayApi
{
    private const TOKEN_URL = 'https://api.ebay.com/identity/v1/oauth2/token';
    private const SEARCH_URL = 'https://api.ebay.com/buy/browse/v1/item_summary/search';
    private const SCOPE = 'https://api.ebay.com/oauth/api_scope';

    private string $clientId;
    private string $clientSecret;
    private ?string $cachedToken = null;
    private ?int $tokenExpiresAt = null;

    public function __construct(string $clientId, string $clientSecret)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    /**
     * @param array $params Search query params (q, category_ids, sort, limit, filter, etc.)
     * @param string $marketplace Marketplace ID, e.g. EBAY_GB or EBAY_US
     */
    public function search(array $params, string $marketplace = 'EBAY_GB'): array
    {
        $token = $this->getAccessToken();
        $query = http_build_query(array_filter($params));
        $url = self::SEARCH_URL . ($query !== '' ? '?' . $query : '');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'X-EBAY-C-MARKETPLACE-ID: ' . $marketplace,
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('eBay API request failed');
        }

        $data = json_decode($response, true);
        if ($status >= 400) {
            $message = $data['errors'][0]['message'] ?? $response;
            throw new RuntimeException('eBay API error: ' . $message);
        }

        return $data ?? [];
    }

    private function getAccessToken(): string
    {
        $buffer = 60; // refresh 1 min before expiry
        if ($this->cachedToken !== null && $this->tokenExpiresAt !== null && time() < $this->tokenExpiresAt - $buffer) {
            return $this->cachedToken;
        }

        $credentials = base64_encode($this->clientId . ':' . $this->clientSecret);

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . $credentials,
            ],
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials&scope=' . rawurlencode(self::SCOPE),
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            $hint = ' Use the App ID and Secret from the Application Keys table at developer.ebay.com/my/keys — not the token from "Get OAuth Application Token".';
            throw new RuntimeException('Failed to get eBay OAuth token.' . $hint);
        }

        $data = json_decode($response, true);
        $this->cachedToken = $data['access_token'] ?? '';
        $expiresIn = (int) ($data['expires_in'] ?? 7200);
        $this->tokenExpiresAt = time() + $expiresIn;

        if ($this->cachedToken === '') {
            throw new RuntimeException('Invalid eBay OAuth response');
        }

        return $this->cachedToken;
    }

    /** Get default category tree ID for a marketplace (Taxonomy API). */
    public function getDefaultCategoryTreeId(string $marketplace): array
    {
        $token = $this->getAccessToken();
        $url = 'https://api.ebay.com/commerce/taxonomy/v1/get_default_category_tree_id?marketplace_id=' . rawurlencode($marketplace);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $status >= 400) {
            throw new RuntimeException('Failed to get category tree ID for ' . $marketplace);
        }
        return json_decode($response, true) ?? [];
    }

    /** Get full category tree (Taxonomy API). Use Accept-Encoding: gzip for large response. */
    public function getCategoryTree(string $categoryTreeId): array
    {
        $token = $this->getAccessToken();
        $url = 'https://api.ebay.com/commerce/taxonomy/v1/category_tree/' . rawurlencode($categoryTreeId);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Accept-Encoding: gzip'],
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $status >= 400) {
            throw new RuntimeException('Failed to get category tree');
        }
        return json_decode($response, true) ?? [];
    }
}
