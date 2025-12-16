<?php

declare(strict_types=1);

namespace app\service;

use app\model\AiPollingGroup;
use app\model\AiProvider;
use app\service\ai\AiProviderInterface;
use app\service\ai\providers\AzureOpenAiProvider;
use app\service\ai\providers\ClaudeProvider;
use app\service\ai\providers\DeepSeekProvider;
use app\service\ai\providers\GeminiProvider;
use app\service\ai\providers\LocalEchoProvider;
use app\service\ai\providers\OpenAiProvider;
use app\service\ai\providers\ZhipuProvider;
use support\Log;
use support\Redis;
use Throwable;

/**
 * AI 提供者服务：负责管理 AI 提供者实例、选择策略及熔断机制
 */
class AiProviderService
{
    /**
     * 获取所有启用的提供方（从数据库）
     */
    public static function getAllProviders(bool $enabledOnly = false): array
    {
        $query = AiProvider::query();

        if ($enabledOnly) {
            $query->where('enabled', true);
        }

        return $query->orderBy('weight', 'desc')->get()->toArray();
    }

    /**
     * 获取所有轮询组及其详细状态（包含提供方在线/黑名单状态）
     *
     * @return array
     */
    public static function getAllPollingGroupsWithStatus(): array
    {
        try {
            $groups = AiPollingGroup::with(['providers.provider'])->get();
            $result = [];

            foreach ($groups as $group) {
                // 如果只显示启用的组，可以在这里过滤，但通常管理端希望看到所有
                // if (!$group->enabled) continue;

                $providersStatus = [];
                if ($group->providers) {
                    foreach ($group->providers as $rel) {
                        if (!$rel->provider) {
                            continue;
                        }

                        $isBlacklisted = self::isProviderBlacklisted($rel->provider->id);
                        $providersStatus[] = [
                            'id' => $rel->provider->id,
                            'name' => $rel->provider->name,
                            'weight' => $rel->weight,
                            'enabled' => (bool) $rel->enabled && (bool) $rel->provider->enabled,
                            'is_blacklisted' => $isBlacklisted,
                            'status_text' => $isBlacklisted ? 'Blacklisted' : ($rel->enabled && $rel->provider->enabled ? 'Active' : 'Disabled'),
                        ];
                    }
                }

                $result[] = [
                    'id' => $group->id,
                    'name' => $group->name,
                    'strategy' => $group->strategy,
                    'enabled' => (bool) $group->enabled,
                    'providers' => $providersStatus,
                ];
            }

            return $result;
        } catch (Throwable $e) {
            Log::error('AiProviderService::getAllPollingGroupsWithStatus failed: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * 检查提供方是否在黑名单中
     */
    public static function isProviderBlacklisted(string|int $providerId): bool
    {
        try {
            $redis = Redis::connection('default');
            $key = "ai_provider_blacklist:{$providerId}";

            return (bool) $redis->exists($key);
        } catch (Throwable $e) {
            Log::error('Failed to check provider blacklist: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * 获取当前配置的AI提供者（支持轮询组、单一提供者及黑名单过滤）
     *
     * @param array $excludeProviders 额外排除的提供者ID列表
     *
     * @return AiProviderInterface|null
     */
    public static function getCurrentProvider(array $excludeProviders = []): ?AiProviderInterface
    {
        $currentSelection = (string) blog_config('ai_current_selection', '', true);

        if (empty($currentSelection)) {
            Log::warning('No AI provider selected, trying first enabled provider');

            return self::getFirstAvailableProvider($excludeProviders);
        }

        if (str_starts_with($currentSelection, 'provider:')) {
            // 直接选择提供方
            $providerId = substr($currentSelection, 9);

            // 检查黑名单和排除列表
            if (self::isProviderBlacklisted((string) $providerId) || in_array($providerId, $excludeProviders, true)) {
                // 如果直接选择的提供方被屏蔽，尝试降级到其他可用提供方
                Log::warning("Selected provider {$providerId} is blacklisted or excluded, failing over");

                return self::getFirstAvailableProvider(array_merge($excludeProviders, [$providerId]));
            }

            return self::createProviderFromDb($providerId);
        }

        if (str_starts_with($currentSelection, 'group:')) {
            // 选择轮询组
            $groupId = (int) substr($currentSelection, 6);

            return self::getProviderFromGroup($groupId, $excludeProviders);
        }

        Log::error("Invalid selection format: {$currentSelection}");

        return self::getFirstAvailableProvider($excludeProviders);
    }

    /**
     * 获取第一个可用的提供方（作为预留，过滤黑名单）
     */
    private static function getFirstAvailableProvider(array $excludeProviders = []): ?AiProviderInterface
    {
        try {
            $providers = AiProvider::where('enabled', true)
                ->whereNotIn('id', $excludeProviders)
                ->orderBy('weight', 'desc')
                ->get();

            foreach ($providers as $provider) {
                if (self::isProviderBlacklisted((string) $provider->id)) {
                    continue;
                }

                return self::createProviderInstance($provider->type, $provider->getConfigArray());
            }

            // 如果全都不可用，尝试返回黑名单中的（作为最后的兜底，或者直接返回本地echo）
            Log::warning('No available AI provider (all excluded or blacklisted), using local echo');

            return self::createProviderInstance('local', []);

        } catch (Throwable $e) {
            Log::error('Failed to get first available provider: ' . $e->getMessage());

            return self::createProviderInstance('local', []);
        }
    }

    /**
     * 创建AI提供者实例（根据类型和配置）
     */
    public static function createProviderInstance(string $type, array $config = []): ?AiProviderInterface
    {
        $classMap = self::getProviderClassMap();

        if (!isset($classMap[$type])) {
            Log::error("Unknown AI provider type: {$type}");

            return null;
        }

        $class = $classMap[$type];

        return new $class($config);
    }

    /**
     * 获取可用的AI提供者类映射（类型到实现类）
     */
    public static function getProviderClassMap(): array
    {
        return [
            'local' => LocalEchoProvider::class,
            'openai' => OpenAiProvider::class,
            'azure_openai' => AzureOpenAiProvider::class,
            'claude' => ClaudeProvider::class,
            'gemini' => GeminiProvider::class,
            'deepseek' => DeepSeekProvider::class,
            'zhipu' => ZhipuProvider::class,
            'custom' => OpenAiProvider::class, // 自定义使用 OpenAI 兼容实现
        ];
    }

    /**
     * 从数据库加载提供方并创建实例
     */
    public static function createProviderFromDb(string $providerId): ?AiProviderInterface
    {
        try {
            /** @var AiProvider|null $provider */
            $provider = AiProvider::find($providerId);

            if (!$provider || !$provider->enabled) {
                Log::warning("AI provider {$providerId} not found or disabled");

                return null;
            }

            $config = $provider->getConfigArray();
            $config['id'] = $provider->id;
            $config['name'] = $provider->name;

            return self::createProviderInstance($provider->type, $config);
        } catch (Throwable $e) {
            Log::error("Failed to create provider from DB: {$providerId}, error: " . $e->getMessage());

            return null;
        }
    }

    /**
     * 从轮询组获取提供方（支持故障转移和黑名单）
     */
    private static function getProviderFromGroup(int $groupId, array $excludeProviders = []): ?AiProviderInterface
    {
        try {
            $group = AiPollingGroup::with([
                'providers' => function ($query) use ($excludeProviders) {
                    $query->where('enabled', true);
                    if (!empty($excludeProviders)) {
                        $query->whereNotIn('provider_id', $excludeProviders);
                    }
                },
                'providers.provider',
            ])
                ->where('id', $groupId)
                ->where('enabled', true)
                ->first();

            if (!$group || $group->providers->isEmpty()) {
                Log::warning("Polling group {$groupId} not found or has no enabled providers");

                return self::getFirstAvailableProvider($excludeProviders);
            }

            // 获取提供方详情并过滤黑名单
            $validRelations = $group->providers->filter(function ($rel) {
                if (!$rel->provider || !$rel->provider->enabled) {
                    return false;
                }
                if (self::isProviderBlacklisted((string) $rel->provider_id)) {
                    return false;
                }

                return true;
            });

            if ($validRelations->isEmpty()) {
                Log::warning("No valid (non-blacklisted) providers in group {$groupId}");

                return self::getFirstAvailableProvider($excludeProviders);
            }

            // 根据策略选择提供方
            if ($group->strategy === 'polling') {
                return self::selectProviderByPolling($validRelations->toArray());
            } elseif ($group->strategy === 'failover') {
                return self::selectProviderByFailover($validRelations->toArray());
            }

            // 默认返回第一个
            $first = $validRelations->first();

            return self::createProviderInstance($first->provider->type, $first->provider->getConfigArray());

        } catch (Throwable $e) {
            Log::error('Failed to get provider from group: ' . $e->getMessage());

            return self::getFirstAvailableProvider($excludeProviders);
        }
    }

    /**
     * 轮询策略：根据权重加权随机选择
     */
    private static function selectProviderByPolling(array $providerRelations): ?AiProviderInterface
    {
        if (empty($providerRelations)) {
            return null;
        }

        $totalWeight = array_sum(array_column($providerRelations, 'weight'));
        if ($totalWeight <= 0) {
            $totalWeight = count($providerRelations);
        }

        $random = mt_rand(1, $totalWeight);
        $currentWeight = 0;

        foreach ($providerRelations as $relation) {
            $weight = (int) ($relation['weight'] ?? 1);
            $currentWeight += $weight;

            if ($random <= $currentWeight && isset($relation['provider'])) {
                $provider = $relation['provider'];
                $type = is_array($provider) ? ($provider['type'] ?? '') : $provider->type;
                $config = is_array($provider)
                    ? (json_decode($provider['config'] ?? '{}', true) ?: [])
                    : $provider->getConfigArray();

                // 注入ID和名称
                $config['id'] = is_array($provider) ? ($provider['id'] ?? '') : $provider->id;
                $config['name'] = is_array($provider) ? ($provider['name'] ?? '') : $provider->name;

                return self::createProviderInstance($type, $config);
            }
        }

        // 默认返回第一个
        $first = reset($providerRelations);
        if (isset($first['provider'])) {
            $provider = $first['provider'];
            $type = is_array($provider) ? ($provider['type'] ?? '') : $provider->type;
            $config = is_array($provider)
                ? (json_decode($provider['config'] ?? '{}', true) ?: [])
                : $provider->getConfigArray();

            // 注入ID和名称
            $config['id'] = is_array($provider) ? ($provider['id'] ?? '') : $provider->id;
            $config['name'] = is_array($provider) ? ($provider['name'] ?? '') : $provider->name;

            return self::createProviderInstance($type, $config);
        }

        return null;
    }

    /**
     * 主备策略：按权重顺序选择最高优先级
     */
    private static function selectProviderByFailover(array $providerRelations): ?AiProviderInterface
    {
        if (empty($providerRelations)) {
            return null;
        }

        // 按权重降序排列
        usort($providerRelations, function ($a, $b) {
            return ($b['weight'] ?? 1) <=> ($a['weight'] ?? 1);
        });

        // 返回权重最高的提供者
        $first = reset($providerRelations);
        if (isset($first['provider'])) {
            $provider = $first['provider'];
            $type = is_array($provider) ? ($provider['type'] ?? '') : $provider->type;
            $config = is_array($provider)
                ? (json_decode($provider['config'] ?? '{}', true) ?: [])
                : $provider->getConfigArray();

            // 注入ID和名称
            $config['id'] = is_array($provider) ? ($provider['id'] ?? '') : $provider->id;
            $config['name'] = is_array($provider) ? ($provider['name'] ?? '') : $provider->name;

            return self::createProviderInstance($type, $config);
        }

        return null;
    }

    /**
     * 解析指定的提供者ID（可能是单个提供者ID，也可能是 group:ID）
     * 并返回相应的 AiProviderInterface 实例
     */
    public static function resolveProvider(string $identifier, array $excludeProviders = []): ?AiProviderInterface
    {
        if (str_starts_with($identifier, 'group:')) {
            $groupId = (int) substr($identifier, 6);

            return self::getProviderFromGroup($groupId, $excludeProviders);
        } elseif (str_starts_with($identifier, 'provider:')) {
            $providerId = substr($identifier, 9);
            // 检查黑名单
            if (self::isProviderBlacklisted($providerId) || in_array($providerId, $excludeProviders)) {
                return null; // 或者降级？此处假设resolve明确指定，如果不可用则返回null交由调用方处理（比如重试）
            }

            return self::createProviderFromDb($providerId);
        } else {
            // 假设是纯ID，默认为 provider ID
            if (self::isProviderBlacklisted($identifier) || in_array($identifier, $excludeProviders)) {
                return null;
            }

            return self::createProviderFromDb($identifier);
        }
    }

    /**
     * 将提供方加入临时黑名单
     *
     * @param string|int $providerId 提供方ID
     * @param int        $seconds    拉黑时长（秒）
     */
    public static function addProviderToBlacklist(string|int $providerId, int $seconds = 300): void
    {
        try {
            $redis = Redis::connection('default');
            $key = "ai_provider_blacklist:{$providerId}";
            $redis->setex($key, $seconds, '1');
            Log::info("AI Provider {$providerId} added to blacklist for {$seconds} seconds");
        } catch (Throwable $e) {
            Log::error('Failed to add provider to blacklist: ' . $e->getMessage());
        }
    }
}
