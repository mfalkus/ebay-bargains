<?php

declare(strict_types=1);

/**
 * Per-IP rate limiter: 10 requests per minute, 100 per hour.
 * Uses file-based storage; unlimited IPs bypass the limit.
 */
final class RateLimiter
{
    private const LIMIT_PER_MINUTE = 10;
    private const LIMIT_PER_HOUR = 100;
    private const WINDOW_MINUTE = 60;
    private const WINDOW_HOUR = 3600;

    /** @var list<string> */
    private array $unlimitedIps;

    private string $storageDir;

    /**
     * @param list<string> $unlimitedIps IPs that are not rate-limited
     * @param string $storageDir Directory for storing per-IP request logs (must be writable)
     */
    public function __construct(array $unlimitedIps, string $storageDir)
    {
        $this->unlimitedIps = $unlimitedIps;
        $this->storageDir = rtrim($storageDir, '/');
    }

    /**
     * Check if the client IP is allowed to make a request. If allowed, records the request.
     * Returns true if allowed, false if rate limit exceeded.
     */
    public function allowRequest(string $clientIp): bool
    {
        if ($this->isUnlimited($clientIp)) {
            return true;
        }

        $now = time();
        $path = $this->pathForIp($clientIp);

        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }

        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return true; // If we can't open, allow (fail open to avoid blocking)
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return true;
        }

        $timestamps = $this->readTimestampsFromHandle($fp);
        $timestamps[] = $now;
        // Keep only last hour to bound file size
        $cutoff = $now - self::WINDOW_HOUR;
        $timestamps = array_values(array_filter($timestamps, static fn(int $t): bool => $t >= $cutoff));

        $minuteAgo = $now - self::WINDOW_MINUTE;
        $countMinute = count(array_filter($timestamps, static fn(int $t): bool => $t >= $minuteAgo));
        $countHour = count($timestamps);

        if ($countMinute > self::LIMIT_PER_MINUTE || $countHour > self::LIMIT_PER_HOUR) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return false;
        }

        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode(['ts' => $timestamps], JSON_THROW_ON_ERROR));
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }

    private function isUnlimited(string $ip): bool
    {
        $ip = trim($ip);
        return in_array($ip, $this->unlimitedIps, true);
    }

    private function pathForIp(string $ip): string
    {
        $safe = preg_replace('/[^a-fA-F0-9.:]/', '_', $ip) ?: 'unknown';
        return $this->storageDir . '/' . $safe . '.json';
    }

    /**
     * @param resource $fp File handle (locked), positioned at start
     * @return list<int>
     */
    private function readTimestampsFromHandle($fp): array
    {
        $raw = stream_get_contents($fp);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['ts']) || !is_array($data['ts'])) {
            return [];
        }
        $ts = array_map('intval', $data['ts']);
        return array_values(array_filter($ts, static fn($t): bool => $t > 0));
    }
}
