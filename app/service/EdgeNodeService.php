<?php

namespace app\service;

use support\Log;

class EdgeNodeService
{
    private static ?bool $datacenterAvailable = null;

    private static int $lastCheckTime = 0;

    private static int $checkInterval = 60;

    private static bool $degradedMode = false;

    private static int $degradedSince = 0;

    private static array $syncStatus = [
        'last_sync' => 0,
        'syncing' => false,
        'sync_count' => 0,
        'error_count' => 0,
        'last_error' => '',
    ];

    public function __construct()
    {
        $this->checkInterval = config('app.edge_sync_interval', 300);
    }

    public static function isEdgeMode(): bool
    {
        return config('app.is_edge_mode', false);
    }

    public static function isDatacenterAvailable(): bool
    {
        if (!self::isEdgeMode()) {
            return true;
        }

        $now = time();
        if (self::$datacenterAvailable !== null && ($now - self::$lastCheckTime) < self::$checkInterval) {
            return self::$datacenterAvailable;
        }

        self::$lastCheckTime = $now;
        $datacenterUrl = config('app.datacenter_url', '');

        if (empty($datacenterUrl)) {
            self::$datacenterAvailable = false;
            Log::warning('[EdgeNode] Datacenter URL not configured');

            return false;
        }

        try {
            $healthUrl = rtrim($datacenterUrl, '/') . '/api/health';
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'method' => 'GET',
                ],
            ]);

            $response = @file_get_contents($healthUrl, false, $context);

            if ($response !== false && str_contains($response, 'ok')) {
                self::$datacenterAvailable = true;
                Log::debug('[EdgeNode] Datacenter is available');

                return true;
            }

            self::$datacenterAvailable = false;
            Log::warning('[EdgeNode] Datacenter health check failed');

            return false;
        } catch (\Throwable $e) {
            self::$datacenterAvailable = false;
            Log::error('[EdgeNode] Datacenter check error: ' . $e->getMessage());

            return false;
        }
    }

    public static function isDegradedMode(): bool
    {
        if (!self::isEdgeMode()) {
            return false;
        }

        $datacenterAvailable = self::isDatacenterAvailable();
        $degradeEnabled = config('app.edge_degrade_enabled', true);

        if (!$degradeEnabled) {
            return false;
        }

        if (!$datacenterAvailable && !self::$degradedMode) {
            self::$degradedMode = true;
            self::$degradedSince = time();
            Log::warning('[EdgeNode] Entering degraded mode');
        } elseif ($datacenterAvailable && self::$degradedMode) {
            self::$degradedMode = false;
            self::$degradedSince = 0;
            Log::info('[EdgeNode] Exiting degraded mode');
        }

        return self::$degradedMode;
    }

    public static function getDegradedDuration(): int
    {
        if (!self::$degradedMode) {
            return 0;
        }

        return time() - self::$degradedSince;
    }

    public static function getCache(string $key, mixed $default = null): mixed
    {
        if (!self::isEdgeMode()) {
            return CacheService::cache($key, null, false);
        }

        $edgeKey = 'edge:' . $key;
        $value = CacheService::cache($edgeKey, null, false);

        if ($value !== $default) {
            Log::debug('[EdgeNode] Cache hit: ' . $key);
        }

        return $value;
    }

    public static function setCache(string $key, mixed $value, int $ttl = 3600): bool
    {
        if (!self::isEdgeMode()) {
            return CacheService::cache($key, $value, true, $ttl);
        }

        $edgeKey = 'edge:' . $key;
        $edgeTtl = $ttl * 2;
        $result = CacheService::cache($edgeKey, $value, true, $edgeTtl);

        if ($result) {
            Log::debug('[EdgeNode] Cache set: ' . $key . ' TTL: ' . $edgeTtl);
        }

        return $result;
    }

    public static function deleteCache(string $key): bool
    {
        if (!self::isEdgeMode()) {
            return CacheService::delete($key);
        }

        $edgeKey = 'edge:' . $key;

        return CacheService::delete($edgeKey);
    }

    public static function getSyncStatus(): array
    {
        return self::$syncStatus;
    }

    public static function updateSyncStatus(array $updates): void
    {
        foreach ($updates as $key => $value) {
            if (array_key_exists($key, self::$syncStatus)) {
                self::$syncStatus[$key] = $value;
            }
        }
    }

    public static function markSyncStart(): void
    {
        self::$syncStatus['syncing'] = true;
    }

    public static function markSyncComplete(int $syncedCount = 0): void
    {
        self::$syncStatus['syncing'] = false;
        self::$syncStatus['last_sync'] = time();
        self::$syncStatus['sync_count'] += $syncedCount;
        self::$syncStatus['error_count'] = 0;
        self::$syncStatus['last_error'] = '';
    }

    public static function markSyncError(string $error): void
    {
        self::$syncStatus['syncing'] = false;
        self::$syncStatus['error_count']++;
        self::$syncStatus['last_error'] = $error;
    }

    public static function getNodeInfo(): array
    {
        return [
            'mode' => self::isEdgeMode() ? 'edge' : 'datacenter',
            'datacenter_available' => self::isDatacenterAvailable(),
            'degraded_mode' => self::isDegradedMode(),
            'degraded_duration' => self::getDegradedDuration(),
            'sync_status' => self::getSyncStatus(),
        ];
    }

    public static function reset(): void
    {
        self::$datacenterAvailable = null;
        self::$lastCheckTime = 0;
        self::$degradedMode = false;
        self::$degradedSince = 0;
        self::$syncStatus = [
            'last_sync' => 0,
            'syncing' => false,
            'sync_count' => 0,
            'error_count' => 0,
            'last_error' => '',
        ];
    }
}
