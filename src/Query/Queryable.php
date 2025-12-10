<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Query;

use Yakupeyisan\CodeIgniter4\EntityFramework\Core\DbContext;
use Yakupeyisan\CodeIgniter4\EntityFramework\Query\AdvancedQueryBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Queryable - Implementation of IQueryable
 * Equivalent to DbSet<T> in EF Core
 */
class Queryable implements IQueryable
{
    private DbContext $context;
    private string $entityType;
    private BaseConnection $connection;
    private AdvancedQueryBuilder $queryBuilder;

    public function __construct(DbContext $context, string $entityType, BaseConnection $connection)
    {
        $this->context = $context;
        $this->entityType = $entityType;
        $this->connection = $connection;
        $this->queryBuilder = new AdvancedQueryBuilder($context, $entityType, $connection);
    }

    public function where(callable $predicate, bool $isOr = false): IQueryable
    {
        $this->queryBuilder->where($predicate, $isOr);
        return $this;
    }

    public function startGroup(): IQueryable
    {
        $this->queryBuilder->startGroup();
        return $this;
    }

    public function endGroup(): IQueryable
    {
        $this->queryBuilder->endGroup();
        return $this;
    }

    public function select(callable $selector): IQueryable
    {
        $this->queryBuilder->select($selector);
        return $this;
    }

    public function include(string $navigationProperty, ?string $whereClause = null, string $joinType = 'LEFT', ?string $joinCondition = null): IQueryable
    {
        $this->queryBuilder->include($navigationProperty, $whereClause, $joinType, $joinCondition);
        return $this;
    }

    public function thenInclude(string $navigationProperty, ?string $whereClause = null, string $joinType = 'LEFT', ?string $joinCondition = null): IQueryable
    {
        $this->queryBuilder->thenInclude($navigationProperty, $whereClause, $joinType, $joinCondition);
        return $this;
    }

    public function orderBy(callable $keySelector): IQueryable
    {
        $this->queryBuilder->orderBy($keySelector, 'ASC');
        return $this;
    }

    public function orderByDescending(callable $keySelector): IQueryable
    {
        $this->queryBuilder->orderBy($keySelector, 'DESC');
        return $this;
    }

    public function thenOrderBy(callable $keySelector): IQueryable
    {
        $this->queryBuilder->thenOrderBy($keySelector, 'ASC');
        return $this;
    }

    public function thenOrderByDescending(callable $keySelector): IQueryable
    {
        $this->queryBuilder->thenOrderBy($keySelector, 'DESC');
        return $this;
    }

    public function skip(int $count): IQueryable
    {
        $this->queryBuilder->skip($count);
        return $this;
    }

    public function take(int $count): IQueryable
    {
        $this->queryBuilder->take($count);
        return $this;
    }

    public function groupBy(callable $keySelector): IQueryable
    {
        $this->queryBuilder->groupBy($keySelector);
        return $this;
    }

    public function join(IQueryable $inner, callable $outerKeySelector, callable $innerKeySelector, callable $resultSelector): IQueryable
    {
        $this->queryBuilder->join($inner, $outerKeySelector, $innerKeySelector, $resultSelector, 'INNER');
        return $this;
    }

    public function leftJoin(IQueryable $inner, callable $outerKeySelector, callable $innerKeySelector, callable $resultSelector): IQueryable
    {
        $this->queryBuilder->join($inner, $outerKeySelector, $innerKeySelector, $resultSelector, 'LEFT');
        return $this;
    }

    public function joinRaw(string $rawSql, string $alias, string $joinCondition, string $joinType = 'LEFT', array $parameters = []): IQueryable
    {
        $this->queryBuilder->joinRaw($rawSql, $alias, $joinCondition, $joinType, $parameters);
        return $this;
    }

    public function asNoTracking(): IQueryable
    {
        $this->queryBuilder->asNoTracking();
        return $this;
    }

    public function asTracking(): IQueryable
    {
        $this->queryBuilder->asTracking();
        return $this;
    }

    public function disableSensitive(): IQueryable
    {
        $this->queryBuilder->disableSensitive();
        return $this;
    }

    public function first(): ?object
    {
        return $this->queryBuilder->first();
    }

    public function firstOrDefault(): ?object
    {
        return $this->queryBuilder->firstOrDefault();
    }

    public function single(): object
    {
        return $this->queryBuilder->single();
    }

    public function singleOrDefault(): ?object
    {
        return $this->queryBuilder->singleOrDefault();
    }

    public function toList(): array
    {
        return $this->queryBuilder->toList();
    }

    /**
     * Execute query and get all results as arrays
     * Converts entities to clean arrays (excludes internal properties)
     * 
     * @param bool $includeNavigationProperties Whether to include navigation properties (default: true)
     * @return array Array of entity arrays
     */
    public function toArray(bool $includeNavigationProperties = true): array
    {
        $entities = $this->queryBuilder->toList();
        $result = [];
        
        foreach ($entities as $entity) {
            if ($entity instanceof \Yakupeyisan\CodeIgniter4\EntityFramework\Core\Entity) {
                $result[] = $entity->toArray($includeNavigationProperties);
            } else {
                // If not an Entity, convert to array using reflection
                $result[] = $this->objectToArray($entity);
            }
        }
        
        return $result;
    }

    /**
     * Convert any object to array
     * 
     * @param object $object
     * @return array
     */
    private function objectToArray(object $object): array
    {
        $reflection = new \ReflectionClass($object);
        $result = [];
        
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            
            $property->setAccessible(true);
            
            // Check if property is initialized
            if (!$property->isInitialized($object)) {
                continue;
            }
            
            $value = $property->getValue($object);
            
            // Convert DateTime to string
            if ($value instanceof \DateTime || $value instanceof \DateTimeInterface) {
                $result[$property->getName()] = $value->format('Y-m-d H:i:s');
            }
            // Convert arrays recursively
            elseif (is_array($value)) {
                $result[$property->getName()] = array_map(function($item) {
                    if (is_object($item)) {
                        return $this->objectToArray($item);
                    }
                    return $item;
                }, $value);
            }
            // Convert objects recursively
            elseif (is_object($value)) {
                $result[$property->getName()] = $this->objectToArray($value);
            }
            else {
                $result[$property->getName()] = $value;
            }
        }
        
        return $result;
    }

    public function count(): int
    {
        return $this->queryBuilder->count();
    }

    public function any(): bool
    {
        return $this->queryBuilder->any();
    }

    public function all(callable $predicate): bool
    {
        return $this->queryBuilder->all($predicate);
    }

    public function sum(?callable $selector = null)
    {
        return $this->queryBuilder->sum($selector);
    }

    public function average(?callable $selector = null)
    {
        return $this->queryBuilder->average($selector);
    }

    public function min(?callable $selector = null)
    {
        return $this->queryBuilder->min($selector);
    }

    public function max(?callable $selector = null)
    {
        return $this->queryBuilder->max($selector);
    }

    public function fromSqlRaw(string $sql, array $parameters = []): IQueryable
    {
        $this->queryBuilder->fromSqlRaw($sql, $parameters);
        return $this;
    }

    public function toSql(): string
    {
        return $this->queryBuilder->toSql();
    }

    /**
     * Analyze query execution plan
     */
    public function analyzePlan(): array
    {
        return $this->queryBuilder->analyzePlan();
    }

    /**
     * Get query execution statistics
     */
    public function getStats(): array
    {
        return $this->queryBuilder->getStats();
    }

    /**
     * Add query hints for optimization
     */
    public function withHints(callable $hintsBuilder): IQueryable
    {
        $this->queryBuilder->withHints($hintsBuilder);
        return $this;
    }

    /**
     * Set query timeout
     */
    public function timeout(int $seconds): IQueryable
    {
        $this->queryBuilder->timeout($seconds);
        return $this;
    }

    /**
     * Use specific index
     */
    public function useIndex(string $indexName): IQueryable
    {
        $this->queryBuilder->useIndex($indexName);
        return $this;
    }

    /**
     * Force specific index
     */
    public function forceIndex(string $indexName): IQueryable
    {
        $this->queryBuilder->forceIndex($indexName);
        return $this;
    }

    /**
     * Set lock hint
     */
    public function withLock(string $lockHint): IQueryable
    {
        $this->queryBuilder->withLock($lockHint);
        return $this;
    }

    /**
     * Disable query cache
     */
    public function noCache(): IQueryable
    {
        $this->queryBuilder->noCache();
        return $this;
    }

    /**
     * Get database-specific query builder
     */
    public function databaseSpecific(): \Yakupeyisan\CodeIgniter4\EntityFramework\Query\DatabaseSpecificQueryBuilder
    {
        return new \Yakupeyisan\CodeIgniter4\EntityFramework\Query\DatabaseSpecificQueryBuilder(
            $this->queryBuilder->getContext(),
            $this->queryBuilder->getConnection(),
            $this->queryBuilder
        );
    }

    /**
     * Get query builder (for advanced operations)
     */
    public function getQueryBuilder(): AdvancedQueryBuilder
    {
        return $this->queryBuilder;
    }
}

