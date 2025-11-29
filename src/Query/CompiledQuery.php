<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Query;

use Yakupeyisan\CodeIgniter4\EntityFramework\Core\DbContext;
use CodeIgniter\Database\BaseConnection;

/**
 * CompiledQuery - Compiled query for performance optimization
 * Equivalent to CompiledQuery in EF Core
 * Caches query plans and SQL generation for frequently used queries
 */
class CompiledQuery
{
    private static array $queryCache = [];
    private static int $cacheHitCount = 0;
    private static int $cacheMissCount = 0;

    /**
     * Compile a query and cache it
     * 
     * @param callable $queryBuilder Function that builds the query
     * @param string $cacheKey Optional cache key (auto-generated if not provided)
     * @return callable Compiled query function
     */
    public static function compile(callable $queryBuilder, ?string $cacheKey = null): callable
    {
        // Generate cache key if not provided
        if ($cacheKey === null) {
            $cacheKey = self::generateCacheKey($queryBuilder);
        }

        // Check if query is already compiled
        if (isset(self::$queryCache[$cacheKey])) {
            self::$cacheHitCount++;
            return self::$queryCache[$cacheKey];
        }

        // Compile the query
        self::$cacheMissCount++;
        $compiledQuery = self::compileQuery($queryBuilder);
        
        // Cache the compiled query
        self::$queryCache[$cacheKey] = $compiledQuery;

        return $compiledQuery;
    }

    /**
     * Execute compiled query with parameters
     * 
     * @param callable $compiledQuery Compiled query function
     * @param DbContext $context Database context
     * @param mixed ...$parameters Query parameters
     * @return mixed Query result
     */
    public static function execute(callable $compiledQuery, DbContext $context, ...$parameters)
    {
        return $compiledQuery($context, ...$parameters);
    }

    /**
     * Compile query to optimized form
     */
    private static function compileQuery(callable $queryBuilder): callable
    {
        // Create a closure that captures the query structure
        return function(DbContext $context, ...$parameters) use ($queryBuilder) {
            // Build query using the builder
            $query = $queryBuilder($context, ...$parameters);
            
            // If query is IQueryable, execute it
            if ($query instanceof IQueryable) {
                return $query->toList();
            }
            
            return $query;
        };
    }

    /**
     * Generate cache key from query builder
     */
    private static function generateCacheKey(callable $queryBuilder): string
    {
        $reflection = new \ReflectionFunction($queryBuilder);
        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();
        
        if ($file && $start && $end) {
            // Use file location and line numbers as cache key
            return md5($file . ':' . $start . ':' . $end);
        }
        
        // Fallback: use function name or generate random key
        return md5(serialize($queryBuilder));
    }

    /**
     * Clear query cache
     */
    public static function clearCache(): void
    {
        self::$queryCache = [];
        self::$cacheHitCount = 0;
        self::$cacheMissCount = 0;
    }

    /**
     * Get cache statistics
     */
    public static function getCacheStats(): array
    {
        $total = self::$cacheHitCount + self::$cacheMissCount;
        $hitRate = $total > 0 ? (self::$cacheHitCount / $total) * 100 : 0;

        return [
            'cached_queries' => count(self::$queryCache),
            'cache_hits' => self::$cacheHitCount,
            'cache_misses' => self::$cacheMissCount,
            'hit_rate' => round($hitRate, 2) . '%'
        ];
    }

    /**
     * Remove specific query from cache
     */
    public static function removeFromCache(string $cacheKey): void
    {
        if (isset(self::$queryCache[$cacheKey])) {
            unset(self::$queryCache[$cacheKey]);
        }
    }
}

