<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Query;

/**
 * IQueryable interface - Equivalent to IQueryable<T> in EF Core
 * Provides LINQ-like query operations
 */
interface IQueryable
{
    /**
     * Filter entities (Where)
     */
    public function where(callable $predicate): IQueryable;

    /**
     * Select/Project (Select)
     */
    public function select(callable $selector): IQueryable;

    /**
     * Include navigation property (Eager Loading)
     */
    public function include(string $navigationProperty): IQueryable;

    /**
     * ThenInclude for nested navigation properties
     */
    public function thenInclude(string $navigationProperty): IQueryable;

    /**
     * Order by ascending
     */
    public function orderBy(callable $keySelector): IQueryable;

    /**
     * Order by descending
     */
    public function orderByDescending(callable $keySelector): IQueryable;

    /**
     * Then order by ascending
     */
    public function thenOrderBy(callable $keySelector): IQueryable;

    /**
     * Then order by descending
     */
    public function thenOrderByDescending(callable $keySelector): IQueryable;

    /**
     * Skip entities
     */
    public function skip(int $count): IQueryable;

    /**
     * Take entities
     */
    public function take(int $count): IQueryable;

    /**
     * Group by
     */
    public function groupBy(callable $keySelector): IQueryable;

    /**
     * Join
     */
    public function join(IQueryable $inner, callable $outerKeySelector, callable $innerKeySelector, callable $resultSelector): IQueryable;

    /**
     * Left join
     */
    public function leftJoin(IQueryable $inner, callable $outerKeySelector, callable $innerKeySelector, callable $resultSelector): IQueryable;

    /**
     * AsNoTracking - Disable change tracking
     */
    public function asNoTracking(): IQueryable;

    /**
     * AsTracking - Enable change tracking
     */
    public function asTracking(): IQueryable;

    /**
     * Execute query and get first result
     */
    public function first(): ?object;

    /**
     * Execute query and get first result or default
     */
    public function firstOrDefault(): ?object;

    /**
     * Execute query and get single result
     */
    public function single(): object;

    /**
     * Execute query and get single result or default
     */
    public function singleOrDefault(): ?object;

    /**
     * Execute query and get all results
     */
    public function toList(): array;

    /**
     * Execute query and get count
     */
    public function count(): int;

    /**
     * Execute query and check if any exists
     */
    public function any(): bool;

    /**
     * Execute query and check if all match
     */
    public function all(callable $predicate): bool;

    /**
     * Sum
     */
    public function sum(?callable $selector = null);

    /**
     * Average
     */
    public function average(?callable $selector = null);

    /**
     * Min
     */
    public function min(?callable $selector = null);

    /**
     * Max
     */
    public function max(?callable $selector = null);

    /**
     * Execute raw SQL
     */
    public function fromSqlRaw(string $sql, array $parameters = []): IQueryable;

    /**
     * Get SQL string (for debugging)
     */
    public function toSql(): string;

    /**
     * Analyze query execution plan
     * Returns analysis with recommendations and warnings
     * 
     * @return array Query plan analysis
     */
    public function analyzePlan(): array;

    /**
     * Get query execution statistics
     * 
     * @return array Query statistics (execution time, rows returned, etc.)
     */
    public function getStats(): array;

    /**
     * Add query hints for optimization
     */
    public function withHints(callable $hintsBuilder): IQueryable;

    /**
     * Set query timeout (in seconds)
     */
    public function timeout(int $seconds): IQueryable;

    /**
     * Use specific index
     */
    public function useIndex(string $indexName): IQueryable;

    /**
     * Force specific index
     */
    public function forceIndex(string $indexName): IQueryable;

    /**
     * Set lock hint (SQL Server: NOLOCK, READPAST, etc.)
     */
    public function withLock(string $lockHint): IQueryable;

    /**
     * Disable query cache
     */
    public function noCache(): IQueryable;

    /**
     * Get database-specific query builder
     * Provides database-specific features (full-text search, JSON, window functions, etc.)
     */
    public function databaseSpecific(): \Yakupeyisan\CodeIgniter4\EntityFramework\Query\DatabaseSpecificQueryBuilder;
}

