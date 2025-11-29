<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Query;

use Yakupeyisan\CodeIgniter4\EntityFramework\Core\DbContext;

/**
 * QueryCache - Query plan and SQL cache for performance optimization
 * Caches compiled SQL queries and query plans
 */
class QueryCache
{
    private static array $sqlCache = [];
    private static array $queryPlanCache = [];
    private static int $maxCacheSize = 1000;
    private static int $cacheHitCount = 0;
    private static int $cacheMissCount = 0;

    /**
     * Get cached SQL for query
     */
    public static function getSql(string $cacheKey): ?string
    {
        if (isset(self::$sqlCache[$cacheKey])) {
            self::$cacheHitCount++;
            return self::$sqlCache[$cacheKey];
        }
        
        self::$cacheMissCount++;
        return null;
    }

    /**
     * Cache SQL for query
     */
    public static function setSql(string $cacheKey, string $sql): void
    {
        // Implement LRU cache if size limit reached
        if (count(self::$sqlCache) >= self::$maxCacheSize) {
            // Remove oldest entry (simple FIFO)
            array_shift(self::$sqlCache);
        }
        
        self::$sqlCache[$cacheKey] = $sql;
    }

    /**
     * Get cached query plan
     */
    public static function getQueryPlan(string $cacheKey): ?array
    {
        if (isset(self::$queryPlanCache[$cacheKey])) {
            return self::$queryPlanCache[$cacheKey];
        }
        
        return null;
    }

    /**
     * Cache query plan
     */
    public static function setQueryPlan(string $cacheKey, array $plan): void
    {
        // Implement LRU cache if size limit reached
        if (count(self::$queryPlanCache) >= self::$maxCacheSize) {
            // Remove oldest entry
            array_shift(self::$queryPlanCache);
        }
        
        self::$queryPlanCache[$cacheKey] = $plan;
    }

    /**
     * Generate cache key from query builder state
     */
    public static function generateKey(DbContext $context, string $entityType, array $queryState): string
    {
        $keyData = [
            'entityType' => $entityType,
            'wheres' => self::serializeCallables($queryState['wheres'] ?? []),
            'includes' => $queryState['includes'] ?? [],
            'orderBys' => self::serializeCallables($queryState['orderBys'] ?? []),
            'skipCount' => $queryState['skipCount'] ?? null,
            'takeCount' => $queryState['takeCount'] ?? null,
        ];
        
        return md5(serialize($keyData));
    }

    /**
     * Serialize callables for cache key generation
     */
    private static function serializeCallables(array $callables): array
    {
        $serialized = [];
        
        foreach ($callables as $callable) {
            if (is_callable($callable)) {
                $reflection = new \ReflectionFunction($callable);
                $file = $reflection->getFileName();
                $start = $reflection->getStartLine();
                $end = $reflection->getEndLine();
                
                $serialized[] = [
                    'file' => $file,
                    'start' => $start,
                    'end' => $end
                ];
            }
        }
        
        return $serialized;
    }

    /**
     * Clear all caches
     */
    public static function clear(): void
    {
        self::$sqlCache = [];
        self::$queryPlanCache = [];
        self::$cacheHitCount = 0;
        self::$cacheMissCount = 0;
    }

    /**
     * Get cache statistics
     */
    public static function getStats(): array
    {
        $total = self::$cacheHitCount + self::$cacheMissCount;
        $hitRate = $total > 0 ? (self::$cacheHitCount / $total) * 100 : 0;

        return [
            'sql_cache_size' => count(self::$sqlCache),
            'query_plan_cache_size' => count(self::$queryPlanCache),
            'cache_hits' => self::$cacheHitCount,
            'cache_misses' => self::$cacheMissCount,
            'hit_rate' => round($hitRate, 2) . '%',
            'max_cache_size' => self::$maxCacheSize
        ];
    }

    /**
     * Set maximum cache size
     */
    public static function setMaxCacheSize(int $size): void
    {
        self::$maxCacheSize = $size;
    }
}

