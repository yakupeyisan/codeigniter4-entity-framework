<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Query;

use Yakupeyisan\CodeIgniter4\EntityFramework\Core\DbContext;
use Yakupeyisan\CodeIgniter4\EntityFramework\Core\Entity;
use CodeIgniter\Database\BaseConnection;
use ReflectionClass;
use ReflectionProperty;

/**
 * AdvancedQueryBuilder - Main query builder class
 * Equivalent to IQueryable implementation in EF Core
 * Provides comprehensive LINQ-like query operations
 */
class AdvancedQueryBuilder
{
    private DbContext $context;
    private string $entityType;
    private BaseConnection $connection;
    
    // Query building state
    private array $wheres = []; // Array of ['predicate' => callable, 'isOr' => bool]
    private array $whereGroups = []; // Groups of where clauses with OR logic
    private int $currentWhereIndex = 0; // Track current where clause index for OR logic
    private $select = null; // callable|null
    private array $includes = [];
    private array $orderBys = [];
    private ?int $skipCount = null;
    private ?int $takeCount = null;
    private $groupBy = null; // callable|null
    private array $joins = [];
    private array $rawJoins = []; // Raw SQL joins
    private array $requiredJoins = []; // Navigation property joins for WHERE clauses
    private bool $isNoTracking = false;
    private bool $isTracking = true;
    private bool $isSensitive = false; // Disable sensitive value masking
    private ?string $rawSql = null;
    private array $rawSqlParameters = [];
    private bool $useRawSql = false;
    private ?QueryHints $queryHints = null;
    private array $selectRaw = [];
    private array $whereRaw = [];
    private array $referenceNavIndexes = []; // Store index for each navigation path (used in SQL building and parsing)

    public function __construct(DbContext $context, string $entityType, BaseConnection $connection)
    {
        $this->context = $context;
        $this->entityType = $entityType;
        $this->connection = $connection;
    }

    /**
     * Get table name for this entity
     */
    public function getTableName(): string
    {
        return $this->context->getTableName($this->entityType);
    }

    /**
     * Add raw SELECT clause
     */
    public function selectRaw(string $sql): self
    {
        $this->selectRaw[] = $sql;
        return $this;
    }

    /**
     * Add raw WHERE clause
     */
    public function whereRaw(string $sql): self
    {
        $this->whereRaw[] = $sql;
        return $this;
    }

    /**
     * Add WHERE clause
     */
    public function where(callable $predicate, bool $isOr = false): self
    {
        log_message('debug', "AdvancedQueryBuilder::where() called with isOr=" . ($isOr ? 'true' : 'false'));
        $this->wheres[] = ['predicate' => $predicate, 'isOr' => $isOr];
        log_message('debug', "wheres array count: " . count($this->wheres) . ", last isOr: " . ($isOr ? 'true' : 'false'));
        return $this;
    }

    /**
     * Add SELECT projection
     */
    public function select(callable $selector): self
    {
        $this->select = $selector;
        return $this;
    }

    /**
     * Add INCLUDE for eager loading
     */
    public function include(string $navigationProperty): self
    {
        $this->includes[] = ['path' => $navigationProperty, 'level' => 0];
        return $this;
    }

    /**
     * Add THEN INCLUDE for nested navigation properties
     */
    public function thenInclude(string $navigationProperty): self
    {
        if (empty($this->includes)) {
            throw new \RuntimeException('ThenInclude must be called after Include');
        }
        
        $lastInclude = &$this->includes[count($this->includes) - 1];
        $lastInclude['thenIncludes'][] = $navigationProperty;
        return $this;
    }

    /**
     * Add ORDER BY
     */
    public function orderBy(callable $keySelector, string $direction = 'ASC'): self
    {
        $this->orderBys[] = ['selector' => $keySelector, 'direction' => $direction];
        return $this;
    }

    /**
     * Add THEN ORDER BY
     */
    public function thenOrderBy(callable $keySelector, string $direction = 'ASC'): self
    {
        $this->orderBys[] = ['selector' => $keySelector, 'direction' => $direction];
        return $this;
    }

    /**
     * Add SKIP
     */
    public function skip(int $count): self
    {
        $this->skipCount = $count;
        return $this;
    }

    /**
     * Add TAKE
     */
    public function take(int $count): self
    {
        $this->takeCount = $count;
        return $this;
    }

    /**
     * Add GROUP BY
     */
    public function groupBy(callable $keySelector): self
    {
        $this->groupBy = $keySelector;
        return $this;
    }

    /**
     * Add JOIN
     */
    public function join(IQueryable $inner, callable $outerKeySelector, callable $innerKeySelector, callable $resultSelector, string $joinType = 'INNER'): self
    {
        $this->joins[] = [
            'inner' => $inner,
            'outerKeySelector' => $outerKeySelector,
            'innerKeySelector' => $innerKeySelector,
            'resultSelector' => $resultSelector,
            'type' => $joinType
        ];
        return $this;
    }

    /**
     * Join with raw SQL (derived table/CTE)
     * 
     * @param string $rawSql Raw SQL query to join (e.g., subquery or CTE)
     * @param string $alias Alias for the raw SQL table
     * @param string $joinCondition SQL join condition (e.g., "dates.Date = mainTable.CreatedDate")
     * @param string $joinType Join type: 'INNER', 'LEFT', 'RIGHT', 'FULL' (default: 'LEFT')
     * @param array $parameters Parameters for the raw SQL query
     * @return self
     */
    public function joinRaw(string $rawSql, string $alias, string $joinCondition, string $joinType = 'LEFT', array $parameters = []): self
    {
        $this->rawJoins[] = [
            'rawSql' => $rawSql,
            'alias' => $alias,
            'joinCondition' => $joinCondition,
            'joinType' => strtoupper($joinType),
            'parameters' => $parameters
        ];
        return $this;
    }

    /**
     * Apply raw join to CodeIgniter query builder
     * 
     * @param mixed $builder CodeIgniter query builder instance
     * @param array $rawJoin Raw join configuration
     * @return void
     */
    private function applyRawJoin($builder, array $rawJoin): void
    {
        $rawSql = $rawJoin['rawSql'];
        $alias = $rawJoin['alias'];
        $joinCondition = $rawJoin['joinCondition'];
        $joinType = $rawJoin['joinType'];
        
        // Escape the alias
        $quotedAlias = $this->connection->escapeIdentifiers($alias);
        
        // Build the raw join clause
        // Format: {JOIN_TYPE} JOIN ({rawSql}) AS {alias} ON {joinCondition}
        $joinClause = "{$joinType} JOIN ({$rawSql}) AS {$quotedAlias} ON {$joinCondition}";
        
        // CodeIgniter's query builder doesn't directly support raw subquery joins
        // We'll need to use a raw join by building the SQL manually
        // For now, we'll store it and apply it when building the final query
        // Use join with escape = false to indicate it's raw SQL
        // Note: This might require custom handling in query execution
        $builder->join($joinClause, null, '', false);
        
        log_message('debug', "Added RAW JOIN ({$joinType}): ({$rawSql}) AS {$alias} ON {$joinCondition}");
    }

    /**
     * Set AsNoTracking
     */
    public function asNoTracking(): self
    {
        $this->isNoTracking = true;
        $this->isTracking = false;
        return $this;
    }

    /**
     * Set AsTracking
     */
    public function asTracking(): self
    {
        $this->isNoTracking = false;
        $this->isTracking = true;
        return $this;
    }

    /**
     * DisableSensitive - Disable sensitive value masking
     * Returns unmasked sensitive values (bypasses SensitiveValue attribute)
     */
    public function disableSensitive(): self
    {
        $this->isSensitive = true;
        return $this;
    }

    /**
     * Set raw SQL
     */
    public function fromSqlRaw(string $sql, array $parameters = []): self
    {
        $this->useRawSql = true;
        $this->rawSql = $sql;
        $this->rawSqlParameters = $parameters;
        return $this;
    }

    /**
     * Execute and get first result
     */
    public function first(): ?object
    {
        $results = $this->executeQuery();
        return !empty($results) ? $results[0] : null;
    }

    /**
     * Execute and get first result or default
     */
    public function firstOrDefault(): ?object
    {
        return $this->first();
    }

    /**
     * Execute and get single result
     */
    public function single(): object
    {
        $results = $this->executeQuery();
        if (count($results) === 0) {
            throw new \RuntimeException('Sequence contains no elements');
        }
        if (count($results) > 1) {
            throw new \RuntimeException('Sequence contains more than one element');
        }
        return $results[0];
    }

    /**
     * Execute and get single result or default
     */
    public function singleOrDefault(): ?object
    {
        $results = $this->executeQuery();
        if (count($results) === 0) {
            return null;
        }
        if (count($results) > 1) {
            throw new \RuntimeException('Sequence contains more than one element');
        }
        return $results[0];
    }

    /**
     * Execute and get all results
     */
    public function toList(): array
    {
        return $this->executeQuery();
    }

    /**
     * Execute and get count
     */
    public function count(): int
    {
        if ($this->useRawSql) {
            $sql = "SELECT COUNT(*) as count FROM ({$this->rawSql}) as subquery";
            try {
                $result = $this->connection->query($sql, $this->rawSqlParameters);
                $row = $result->getRowArray();
                return (int)($row['count'] ?? 0);
            } catch (\Exception $e) {
                log_message('error', 'SQL Query Error: ' . $e->getMessage());
                log_message('error', 'Failed SQL Query: ' . $sql);
                log_message('error', 'SQL Parameters: ' . json_encode($this->rawSqlParameters));
                throw $e;
            }
        }

        $tableName = $this->context->getTableName($this->entityType);
        $builder = $this->connection->table($tableName);
        
        // First pass: Detect all navigation property paths
        $allNavigationPaths = [];
        foreach ($this->wheres as $whereItem) {
            $where = is_array($whereItem) ? $whereItem['predicate'] : $whereItem;
            $paths = $this->detectNavigationPaths($where);
            foreach ($paths as $path) {
                if (!in_array($path, $allNavigationPaths)) {
                    $allNavigationPaths[] = $path;
                }
            }
        }
        
        // Add all JOINs first (before WHERE clauses)
        foreach ($allNavigationPaths as $path) {
            $this->addJoinForNavigationPath($builder, $path);
        }
        
        // Second pass: Apply WHERE clauses (now JOINs are already added)
        foreach ($this->wheres as $index => $whereItem) {
            $where = is_array($whereItem) ? $whereItem['predicate'] : $whereItem;
            $isOr = is_array($whereItem) && isset($whereItem['isOr']) ? $whereItem['isOr'] : false;
            log_message('debug', "count(): Processing where item #{$index}, isOr=" . ($isOr ? 'true' : 'false'));
            $paths = $this->detectNavigationPaths($where);
            if (!empty($paths)) {
                // Navigation property filter - convert to SQL
                $this->applyNavigationWhereToSql($builder, $where, $paths);
            } else {
                // Simple property filter
                $this->applyWhere($builder, $where, $isOr);
            }
        }
        
        log_message('debug', 'COUNT Query: ' . $builder->getCompiledSelect(false));
        return $builder->countAllResults();
    }

    /**
     * Check if any exists
     */
    public function any(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Check if all match predicate
     */
    public function all(callable $predicate): bool
    {
        $results = $this->executeQuery();
        foreach ($results as $item) {
            if (!$predicate($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Sum
     */
    public function sum(?callable $selector = null)
    {
        $results = $this->executeQuery();
        if (empty($results)) {
            return 0;
        }
        
        if ($selector === null) {
            // Sum all numeric properties
            $sum = 0;
            foreach ($results as $item) {
                if (is_numeric($item)) {
                    $sum += $item;
                }
            }
            return $sum;
        }
        
        $sum = 0;
        foreach ($results as $item) {
            $value = $selector($item);
            if (is_numeric($value)) {
                $sum += $value;
            }
        }
        return $sum;
    }

    /**
     * Average
     */
    public function average(?callable $selector = null)
    {
        $results = $this->executeQuery();
        if (empty($results)) {
            return 0;
        }
        
        $sum = $this->sum($selector);
        return $sum / count($results);
    }

    /**
     * Min
     */
    public function min(?callable $selector = null)
    {
        $results = $this->executeQuery();
        if (empty($results)) {
            return null;
        }
        
        $values = [];
        foreach ($results as $item) {
            $value = $selector ? $selector($item) : $item;
            $values[] = $value;
        }
        
        return min($values);
    }

    /**
     * Max
     */
    public function max(?callable $selector = null)
    {
        $results = $this->executeQuery();
        if (empty($results)) {
            return null;
        }
        
        $values = [];
        foreach ($results as $item) {
            $value = $selector ? $selector($item) : $item;
            $values[] = $value;
        }
        
        return max($values);
    }

    /**
     * Get SQL string
     * Uses query cache for performance optimization
     */
    public function toSql(): string
    {
        if ($this->useRawSql) {
            return $this->rawSql;
        }

        // Check query cache
        $queryState = $this->getQueryState();
        $cacheKey = QueryCache::generateKey($this->context, $this->entityType, $queryState);
        
        $cachedSql = QueryCache::getSql($cacheKey);
        if ($cachedSql !== null) {
            return $cachedSql;
        }

        // Generate SQL
        $tableName = $this->context->getTableName($this->entityType);
        $builder = $this->connection->table($tableName);
        
        // Reset where index for OR logic tracking
        $this->currentWhereIndex = 0;
        
        // Apply where clauses
        foreach ($this->wheres as $whereItem) {
            $predicate = is_array($whereItem) ? $whereItem['predicate'] : $whereItem;
            $isOr = is_array($whereItem) && isset($whereItem['isOr']) ? $whereItem['isOr'] : false;
            $this->applyWhere($builder, $predicate, $isOr);
        }
        
        // Apply order by
        // Use table name as alias for simple queries (CodeIgniter doesn't use aliases by default)
        $mainAlias = $tableName;
        foreach ($this->orderBys as $orderBy) {
            $orderBySql = $this->convertOrderByToSql($orderBy['selector'], $orderBy['direction'], $mainAlias);
            if ($orderBySql) {
                // Extract column name from ORDER BY SQL (e.g., "[alias].[Column] ASC" -> "Column ASC")
                // CodeIgniter's orderBy expects just column name and direction
                if (preg_match('/\[?[^\]]+\]?\.\[?([^\]]+)\]?\s+(ASC|DESC)/i', $orderBySql, $matches)) {
                    $columnName = $matches[1];
                    $direction = strtoupper($matches[2]);
                    $builder->orderBy($columnName, $direction);
                } else {
                    // Fallback: try to extract column name directly
                    $orderBySql = preg_replace('/\[.*?\]\./', '', $orderBySql);
                    $orderBySql = preg_replace('/\[|\]/', '', $orderBySql);
                    if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s+(ASC|DESC)$/i', trim($orderBySql), $matches)) {
                        $builder->orderBy($matches[1], strtoupper($matches[2]));
                    }
                }
            }
        }
        
        // Apply skip/take
        if ($this->skipCount !== null) {
            $builder->offset($this->skipCount);
        }
        // Only apply limit if takeCount is set and > 0 (negative values mean no limit)
        if ($this->takeCount !== null && $this->takeCount > 0) {
            $builder->limit($this->takeCount);
        }
        
        $sql = $builder->getCompiledSelect(false);
        
        // Apply query hints
        if ($this->queryHints !== null) {
            $driver = strtolower($this->connection->getPlatform() ?? '');
            $sql = $this->queryHints->applyToSql($sql, $driver, $tableName);
        }
        
        // Cache the SQL (unless noCache is set)
        if ($this->queryHints === null || !$this->queryHints->isNoCache()) {
            QueryCache::setSql($cacheKey, $sql);
        }
        
        return $sql;
    }

    /**
     * Add query hints for optimization
     */
    public function withHints(callable $hintsBuilder): self
    {
        if ($this->queryHints === null) {
            $this->queryHints = new QueryHints();
        }
        $hintsBuilder($this->queryHints);
        return $this;
    }

    /**
     * Set query timeout
     */
    public function timeout(int $seconds): self
    {
        if ($this->queryHints === null) {
            $this->queryHints = new QueryHints();
        }
        $this->queryHints->timeout($seconds);
        return $this;
    }

    /**
     * Use specific index
     */
    public function useIndex(string $indexName): self
    {
        if ($this->queryHints === null) {
            $this->queryHints = new QueryHints();
        }
        $this->queryHints->useIndex($indexName);
        return $this;
    }

    /**
     * Force specific index
     */
    public function forceIndex(string $indexName): self
    {
        if ($this->queryHints === null) {
            $this->queryHints = new QueryHints();
        }
        $this->queryHints->forceIndex($indexName);
        return $this;
    }

    /**
     * Ignore specific index
     */
    public function ignoreIndex(string $indexName): self
    {
        if ($this->queryHints === null) {
            $this->queryHints = new QueryHints();
        }
        $this->queryHints->ignoreIndex($indexName);
        return $this;
    }

    /**
     * Set lock hint (SQL Server: NOLOCK, READPAST, etc.)
     */
    public function withLock(string $lockHint): self
    {
        if ($this->queryHints === null) {
            $this->queryHints = new QueryHints();
        }
        $this->queryHints->withLock($lockHint);
        return $this;
    }

    /**
     * Disable query cache
     */
    public function noCache(): self
    {
        if ($this->queryHints === null) {
            $this->queryHints = new QueryHints();
        }
        $this->queryHints->noCache();
        return $this;
    }

    /**
     * Add optimizer hint
     */
    public function optimizerHint(string $hint): self
    {
        if ($this->queryHints === null) {
            $this->queryHints = new QueryHints();
        }
        $this->queryHints->optimizerHint($hint);
        return $this;
    }

    /**
     * Analyze query execution plan
     * Returns analysis with recommendations and warnings
     * 
     * @return array Query plan analysis
     */
    public function analyzePlan(): array
    {
        $sql = $this->toSql();
        $analyzer = new QueryPlanAnalyzer($this->connection);
        return $analyzer->analyzePlan($sql);
    }

    /**
     * Get query execution statistics
     * 
     * @return array Query statistics (execution time, rows returned, etc.)
     */
    public function getStats(): array
    {
        $sql = $this->toSql();
        $analyzer = new QueryPlanAnalyzer($this->connection);
        return $analyzer->getQueryStats($sql);
    }

    /**
     * Get current query state for cache key generation
     */
    private function getQueryState(): array
    {
        return [
            'wheres' => $this->wheres,
            'includes' => $this->includes,
            'orderBys' => $this->orderBys,
            'skipCount' => $this->skipCount,
            'takeCount' => $this->takeCount,
            'groupBy' => $this->groupBy,
            'joins' => $this->joins,
            'isNoTracking' => $this->isNoTracking,
        ];
    }

    /**
     * Execute query and return results
     */
    private function executeQuery(): array
    {
        if ($this->useRawSql) {
            return $this->executeRawSql();
        }

        // Use EF Core style query builder if we have includes or navigation filters
        if (!empty($this->includes) || $this->hasNavigationFilters()) {
            return $this->executeEfCoreStyleQuery();
        }

        // Check if we have raw joins - if so, build SQL manually
        if (!empty($this->rawJoins)) {
            return $this->executeQueryWithRawJoins();
        }
        
        // Fallback to simple query builder for basic queries
        $tableName = $this->context->getTableName($this->entityType);
        
        // Check if we need masking (has sensitive columns and disableSensitive not called)
        $entityReflection = new ReflectionClass($this->entityType);
        $columnsWithProperties = $this->getEntityColumnsWithProperties($entityReflection);
        $hasSensitiveColumns = false;
        
        if (!$this->isSensitive) {
            foreach ($columnsWithProperties as $colInfo) {
                $property = $entityReflection->getProperty($colInfo['property']);
                $sensitiveAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\SensitiveValue::class);
                if (!empty($sensitiveAttributes)) {
                    $hasSensitiveColumns = true;
                    log_message('debug', "Found sensitive column: {$colInfo['property']} -> {$colInfo['column']}");
                    break;
                }
            }
        }
        
        log_message('debug', "hasSensitiveColumns: " . ($hasSensitiveColumns ? 'true' : 'false') . ", isSensitive: " . ($this->isSensitive ? 'true' : 'false'));
        
        // If has sensitive columns, build SQL manually with masking
        if ($hasSensitiveColumns) {
            return $this->executeQueryWithMasking($columnsWithProperties, $entityReflection);
        }
        
        // Otherwise use standard query builder
        $builder = $this->connection->table($tableName);
        
        // Apply WHERE clauses
        foreach ($this->wheres as $index => $whereItem) {
            $where = is_array($whereItem) ? $whereItem['predicate'] : $whereItem;
            $isOr = is_array($whereItem) && isset($whereItem['isOr']) ? $whereItem['isOr'] : false;
            log_message('debug', "executeQuery: Processing where item #{$index}, isOr=" . ($isOr ? 'true' : 'false'));
            $this->applyWhere($builder, $where, $isOr);
        }
        
        // Apply order by
        foreach ($this->orderBys as $orderBy) {
            $this->applyOrderBy($builder, $orderBy);
        }
        
        // Apply skip/take
        if ($this->skipCount !== null) {
            $builder->offset($this->skipCount);
        }
        // Only apply limit if takeCount is set and > 0 (negative values mean no limit)
        if ($this->takeCount !== null && $this->takeCount > 0) {
            $builder->limit($this->takeCount);
        }
        
        // Apply query hints (timeout, max rows, etc.)
        if ($this->queryHints !== null) {
            if ($this->queryHints->getTimeout() !== null) {
                $this->setQueryTimeout($this->queryHints->getTimeout());
            }
            if ($this->queryHints->getMaxRows() !== null) {
                $builder->limit($this->queryHints->getMaxRows());
            }
        }
        
        try {
            $query = $builder->get();
            $results = $query->getResultArray();
        } catch (\Exception $e) {
            $sql = $builder->getCompiledSelect(false);
            log_message('error', 'SQL Query Error: ' . $e->getMessage());
            log_message('error', 'Failed SQL Query: ' . $sql);
            throw $e;
        }
        $entities = $this->mapToEntities($results);
        
        // Apply change tracking
        if ($this->isTracking && !$this->isNoTracking) {
            foreach ($entities as $entity) {
                if ($entity instanceof Entity) {
                    $entity->enableTracking();
                    $entity->markAsUnchanged();
                }
            }
        }
        
        return $entities;
    }

    /**
     * Execute query with sensitive value masking
     * 
     * @param array $columnsWithProperties Array of ['column' => 'ColumnName', 'property' => 'PropertyName']
     * @param ReflectionClass $entityReflection Entity reflection
     * @return array
     */
    private function executeQueryWithMasking(array $columnsWithProperties, ReflectionClass $entityReflection): array
    {
        $tableName = $this->context->getTableName($this->entityType);
        $mainAlias = 'main';
        $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
        
        // Build SELECT columns with masking
        $selectColumns = [];
        foreach ($columnsWithProperties as $colInfo) {
            $property = $entityReflection->getProperty($colInfo['property']);
            $sensitiveAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\SensitiveValue::class);
            
            // Use provider's escapeIdentifier for database-specific formatting
            $quotedCol = $provider->escapeIdentifier($colInfo['column']);
            $quotedAlias = $provider->escapeIdentifier($mainAlias);
            
            if (!empty($sensitiveAttributes) && !$this->isSensitive) {
                // Apply masking
                $sensitiveAttr = $sensitiveAttributes[0]->newInstance();
                // Build column reference with proper escaping
                $columnRef = "{$quotedAlias}.{$quotedCol}";
                $maskedExpression = $provider->getMaskingSql(
                    $columnRef,
                    $sensitiveAttr->maskChar,
                    $sensitiveAttr->visibleStart,
                    $sensitiveAttr->visibleEnd,
                    $sensitiveAttr->customMask
                );
                $selectColumns[] = "({$maskedExpression}) AS {$quotedCol}";
            } else {
                // No masking
                $selectColumns[] = "{$quotedAlias}.{$quotedCol}";
            }
        }
        
        // Build SQL
        $sql = "SELECT " . implode(', ', $selectColumns) . "\n";
        // Use provider's escapeIdentifier for table and alias to ensure correct format
        $quotedTableName = $provider->escapeIdentifier($tableName);
        $quotedAliasForFrom = $provider->escapeIdentifier($mainAlias);
        $sql .= "FROM {$quotedTableName} AS {$quotedAliasForFrom}";
        
        // Debug log
        log_message('debug', 'SensitiveValue masking SQL: ' . substr($sql, 0, 500));
        
        // Build WHERE clauses
        $whereConditions = [];
        $whereParams = [];
        foreach ($this->wheres as $whereItem) {
            $where = is_array($whereItem) ? $whereItem['predicate'] : $whereItem;
            try {
                $parser = new ExpressionParser($this->entityType, $mainAlias, $this->context);
                
                // Try to extract variable values from closure
                $reflection = new \ReflectionFunction($where);
                $staticVariables = $reflection->getStaticVariables();
                $variableValues = [];
                foreach ($staticVariables as $varName => $varValue) {
                    if (!is_object($varValue) || !($varValue instanceof \Yakupeyisan\CodeIgniter4\EntityFramework\Core\Entity)) {
                        $variableValues[$varName] = $varValue;
                    }
                }
                $parser->setVariableValues($variableValues);
                
                $sqlCondition = $parser->parse($where);
                if (!empty($sqlCondition)) {
                    $whereConditions[] = $sqlCondition;
                    // Collect parameter values
                    $paramValues = $parser->getParameterValues();
                    $whereParams = array_merge($whereParams, $paramValues);
                }
            } catch (\Exception $e) {
                log_message('debug', 'Error parsing WHERE clause: ' . $e->getMessage());
            }
        }
        
        if (!empty($whereConditions)) {
            $sql .= "\nWHERE " . implode(' AND ', $whereConditions);
        }
        
        // Apply ORDER BY
        foreach ($this->orderBys as $orderBy) {
            // Simple order by - can be enhanced
        }
        
        // Apply LIMIT/OFFSET
        // Only apply if takeCount is set and > 0 (negative values mean no limit)
        if ($this->takeCount !== null && $this->takeCount > 0) {
            $limitClause = $provider->getLimitClause($this->takeCount, $this->skipCount);
            $sql .= "\n" . $limitClause;
        } elseif ($this->skipCount !== null && $this->skipCount > 0) {
            // If only skip is set (no take), use a large number for fetch
            $limitClause = $provider->getLimitClause(999999, $this->skipCount);
            $sql .= "\n" . $limitClause;
        }
        
        // Execute query
        try {
        try {
            $query = $this->connection->query($sql);
            $results = $query->getResultArray();
        } catch (\Exception $e) {
            log_message('error', 'SQL Query Error: ' . $e->getMessage());
            log_message('error', 'Failed SQL Query: ' . $sql);
            throw $e;
        }
        } catch (\Exception $e) {
            log_message('error', 'SQL Query Error: ' . $e->getMessage());
            log_message('error', 'Failed SQL Query: ' . $sql);
            throw $e;
        }
        
        // Map to entities
        $entities = $this->mapToEntities($results);
        
        // Apply change tracking
        if ($this->isTracking && !$this->isNoTracking) {
            foreach ($entities as $entity) {
                if ($entity instanceof Entity) {
                    $entity->enableTracking();
                    $entity->markAsUnchanged();
                }
            }
        }
        
        return $entities;
    }

    /**
     * Execute query with raw joins (builds SQL manually)
     * 
     * @return array
     */
    private function executeQueryWithRawJoins(): array
    {
        $tableName = $this->context->getTableName($this->entityType);
        $mainAlias = 'main';
        $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
        
        // Get entity columns
        $entityReflection = new ReflectionClass($this->entityType);
        $entityColumns = $this->getEntityColumns($entityReflection);
        
        // Build SELECT columns
        $selectColumns = [];
        $quotedMainAlias = $provider->escapeIdentifier($mainAlias);
        foreach ($entityColumns as $col) {
            $quotedCol = $provider->escapeIdentifier($col);
            $selectColumns[] = "{$quotedMainAlias}.{$quotedCol}";
        }
        
        // Add all columns from raw joins (prefixed with alias)
        // Users can access them in the result set
        foreach ($this->rawJoins as $rawJoin) {
            $alias = $rawJoin['alias'];
            // Use provider's escapeIdentifier for database-specific formatting
            $quotedRawAlias = $provider->escapeIdentifier($alias);
            $selectColumns[] = "{$quotedRawAlias}.*";
        }
        
        // Build FROM clause
        $quotedTableName = $provider->escapeIdentifier($tableName);
        $sql = "SELECT " . implode(', ', $selectColumns) . "\n";
        $sql .= "FROM {$quotedTableName} AS {$quotedMainAlias}";
        
        // Add raw joins
        foreach ($this->rawJoins as $rawJoin) {
            $rawSql = $rawJoin['rawSql'];
            $alias = $rawJoin['alias'];
            $joinCondition = $rawJoin['joinCondition'];
            $joinType = $rawJoin['joinType'];
            // Use provider's escapeIdentifier for database-specific formatting
            $quotedRawAlias = $provider->escapeIdentifier($alias);
            
            $sql .= "\n{$joinType} JOIN ({$rawSql}) AS {$quotedRawAlias} ON {$joinCondition}";
        }
        
        // Build WHERE clauses (simple ones)
        $whereConditions = [];
        foreach ($this->wheres as $whereItem) {
            $where = is_array($whereItem) ? $whereItem['predicate'] : $whereItem;
            try {
                $parser = new ExpressionParser($this->entityType, $mainAlias, $this->context);
                $sqlCondition = $parser->parse($where);
                if (!empty($sqlCondition)) {
                    $whereConditions[] = $sqlCondition;
                }
            } catch (\Exception $e) {
                log_message('debug', 'Error parsing WHERE clause: ' . $e->getMessage());
            }
        }
        
        if (!empty($whereConditions)) {
            $sql .= "\nWHERE " . implode(' AND ', $whereConditions);
        }
        
        // Apply ORDER BY
        foreach ($this->orderBys as $orderBy) {
            // Simple order by parsing (can be enhanced)
            // For now, skip complex order by with raw joins
        }
        
        // Apply LIMIT/OFFSET
        // Only apply if takeCount is set and > 0 (negative values mean no limit)
        if ($this->takeCount !== null && $this->takeCount > 0) {
            $sql .= "\nLIMIT " . (int)$this->takeCount;
        }
        if ($this->skipCount !== null && $this->skipCount > 0) {
            $sql .= "\nOFFSET " . (int)$this->skipCount;
        }
        
        // Execute query
        try {
        try {
            $query = $this->connection->query($sql);
            $results = $query->getResultArray();
        } catch (\Exception $e) {
            log_message('error', 'SQL Query Error: ' . $e->getMessage());
            log_message('error', 'Failed SQL Query: ' . $sql);
            throw $e;
        }
        } catch (\Exception $e) {
            log_message('error', 'SQL Query Error: ' . $e->getMessage());
            log_message('error', 'Failed SQL Query: ' . $sql);
            throw $e;
        }
        
        // Map to entities (simplified - may need adjustment)
        $entities = $this->mapToEntities($results);
        
        // Apply change tracking
        if ($this->isTracking && !$this->isNoTracking) {
            foreach ($entities as $entity) {
                if ($entity instanceof Entity) {
                    $entity->enableTracking();
                    $entity->markAsUnchanged();
                }
            }
        }
        
        return $entities;
    }

    /**
     * Check if any WHERE clause contains navigation property filters
     */
    private function hasNavigationFilters(): bool
    {
        foreach ($this->wheres as $whereItem) {
            $where = is_array($whereItem) ? $whereItem['predicate'] : $whereItem;
            $paths = $this->detectNavigationPaths($where);
            if (!empty($paths)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Execute EF Core style query with subqueries and JOINs
     * Similar to C# EF Core's single-query approach
     */
    private function executeEfCoreStyleQuery(): array
    {
        // Build EF Core style SQL query
        $sql = $this->buildEfCoreStyleQuery();
        
        // Apply query hints
        if ($this->queryHints !== null) {
            $driver = strtolower($this->connection->getPlatform() ?? '');
            $tableName = $this->context->getTableName($this->entityType);
            $sql = $this->queryHints->applyToSql($sql, $driver, $tableName);
            
            // Apply timeout
            if ($this->queryHints->getTimeout() !== null) {
                $this->setQueryTimeout($this->queryHints->getTimeout());
            }
        }
        
        // Execute raw SQL
        try {
        try {
            $query = $this->connection->query($sql);
            $results = $query->getResultArray();
        } catch (\Exception $e) {
            log_message('error', 'SQL Query Error: ' . $e->getMessage());
            log_message('error', 'Failed SQL Query: ' . $sql);
            throw $e;
        }
        } catch (\Exception $e) {
            log_message('error', 'SQL Query Error: ' . $e->getMessage());
            log_message('error', 'Failed SQL Query: ' . $sql);
            throw $e;
        }
        
        // Log actual SQL executed
        log_message('debug', 'EF Core Style SQL executed: ' . substr($sql, 0, 500) . '...');
        
        // Log first result row structure for debugging
        if (!empty($results)) {
            $firstRow = $results[0];
            log_message('debug', 'First result row keys: ' . implode(', ', array_keys($firstRow)));
            log_message('debug', 'First result row sample: ' . json_encode(array_slice($firstRow, 0, 10)));
        }
        
        // Parse flat result set into hierarchical entities
        $entities = $this->parseEfCoreStyleResults($results);
        
        log_message('debug', 'Parsed entities count: ' . count($entities));
        
        // Apply change tracking and lazy loading proxies
        if ($this->isTracking && !$this->isNoTracking) {
            foreach ($entities as $entity) {
                if ($entity instanceof Entity) {
                    $entity->enableTracking();
                    $entity->markAsUnchanged();
                    
                    // Enable lazy loading for navigation properties (if not already loaded via Include)
                    if ($this->context->isLazyLoadingEnabled()) {
                        $this->enableLazyLoading($entity);
                    }
                }
            }
        }
        
        return $entities;
    }

    /**
     * Execute raw SQL
     */
    private function executeRawSql(): array
    {
        try {
            $query = $this->connection->query($this->rawSql, $this->rawSqlParameters);
            $results = $query->getResultArray();
        } catch (\Exception $e) {
            log_message('error', 'SQL Query Error: ' . $e->getMessage());
            log_message('error', 'Failed SQL Query: ' . $this->rawSql);
            log_message('error', 'SQL Parameters: ' . json_encode($this->rawSqlParameters));
            throw $e;
        }
        $entities = $this->mapToEntities($results);
        
        // Enable lazy loading for navigation properties
        if ($this->context->isLazyLoadingEnabled()) {
            foreach ($entities as $entity) {
                if ($entity instanceof Entity) {
                    $this->enableLazyLoading($entity);
                }
            }
        }
        
        return $entities;
    }

    /**
     * Enable lazy loading for entity navigation properties
     */
    private function enableLazyLoading(Entity $entity): void
    {
        $proxyFactory = new \Yakupeyisan\CodeIgniter4\EntityFramework\Core\ProxyFactory($this->context);
        $proxyFactory->enableLazyLoading($entity);
    }

    /**
     * Map database results to entities
     */
    private function mapToEntities(array $results, ?string $entityType = null): array
    {
        $entities = [];
        $entityType = $entityType ?? $this->entityType;
        $reflection = new ReflectionClass($entityType);
        
        foreach ($results as $row) {
            $entity = $reflection->newInstance();
            
            foreach ($row as $column => $value) {
                // Convert column name to property name (camelCase)
                $propertyName = $this->columnToProperty($column);
                
                if ($reflection->hasProperty($propertyName)) {
                    $property = $reflection->getProperty($propertyName);
                    $property->setAccessible(true);
                    
                    // Type conversion
                    $type = $this->getPropertyType($property);
                    $value = $this->convertValue($value, $type);
                    
                    $property->setValue($entity, $value);
                }
            }
            
            $entities[] = $entity;
        }
        
        return $entities;
    }

    /**
     * Convert column name to property name
     */
    private function columnToProperty(string $column): string
    {
        // Handle snake_case to camelCase
        $parts = explode('_', $column);
        $property = $parts[0];
        for ($i = 1; $i < count($parts); $i++) {
            $property .= ucfirst($parts[$i]);
        }
        return $property;
    }

    /**
     * Get property type
     */
    private function getPropertyType(ReflectionProperty $property): ?string
    {
        $type = $property->getType();
        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }
        return null;
    }

    /**
     * Convert value to appropriate type
     */
    private function convertValue($value, ?string $type)
    {
        if ($value === null) {
            return null;
        }
        
        // Handle DateTime types
        if ($type === 'DateTime' || $type === '\DateTime' || $type === 'DateTimeInterface' || $type === '\DateTimeInterface') {
            if ($value instanceof \DateTime) {
                return $value;
            }
            if (is_string($value) && !empty($value)) {
                try {
                    return new \DateTime($value);
                } catch (\Exception $e) {
                    log_message('error', "Failed to convert value '{$value}' to DateTime: " . $e->getMessage());
                    return null;
                }
            }
            return null;
        }
        
        switch ($type) {
            case 'int':
                return (int)$value;
            case 'float':
            case 'double':
                return (float)$value;
            case 'bool':
                return (bool)$value;
            case 'string':
                return (string)$value;
            case 'array':
                return is_string($value) ? json_decode($value, true) : $value;
            default:
                return $value;
        }
    }

    /**
     * Apply WHERE clause with navigation property support
     * Detects navigation property access and adds necessary JOINs
     * Uses ExpressionParser for advanced expression parsing
     */
    private function applyWhere($builder, callable $predicate, bool $isOr = false): void
    {
        // Try to detect navigation property paths in predicate
        $navigationPaths = $this->detectNavigationPaths($predicate);
        
        if (!empty($navigationPaths)) {
            // Add JOINs for navigation properties
            foreach ($navigationPaths as $path) {
                $this->addJoinForNavigationPath($builder, $path);
            }
            
            // Apply WHERE conditions on joined tables using ExpressionParser
            $this->applyNavigationWhereToSql($builder, $predicate, $navigationPaths);
        } else {
            // Simple property filter - use ExpressionParser for advanced parsing
            $this->applySimpleWhereWithParser($builder, $predicate, $isOr);
        }
    }

    /**
     * Apply simple WHERE clause using ExpressionParser
     */
    private function applySimpleWhereWithParser($builder, callable $predicate, bool $isOr = false): void
    {
        log_message('debug', "applySimpleWhereWithParser called with isOr=" . ($isOr ? 'true' : 'false'));
        try {
            $parser = new ExpressionParser($this->entityType, $this->getTableAliasForParser(), $this->context);
            
            // Try to extract variable values from closure
            $reflection = new \ReflectionFunction($predicate);
            $staticVariables = $reflection->getStaticVariables();
            
            // Parse closure code to extract use() clause variables
            $closureFile = $reflection->getFileName();
            $closureStartLine = $reflection->getStartLine();
            $closureEndLine = $reflection->getEndLine();
            
            $variableValues = [];
            
            // Try to get variables from static variables first
            foreach ($staticVariables as $varName => $varValue) {
                // Skip entity objects (they're the lambda parameter)
                if (!is_object($varValue) || !($varValue instanceof \Yakupeyisan\CodeIgniter4\EntityFramework\Core\Entity)) {
                    $variableValues[$varName] = $varValue;
                }
            }
            
            // Try to parse use() clause from closure code and extract variable names
            $useVarNames = [];
            if ($closureFile && file_exists($closureFile) && $closureStartLine && $closureEndLine) {
                $lines = file($closureFile);
                // Get a few lines before the closure to catch use() clause
                $startLine = max(1, $closureStartLine - 5);
                $endLine = min(count($lines), $closureEndLine);
                $closureCode = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
                
                // Extract use() clause: pattern: use ($var1, $var2, ...) or fn($e) => $e->Id === $id (no use clause)
                if (preg_match('/use\s*\(\s*([^)]+)\s*\)/', $closureCode, $useMatches)) {
                    $useVars = preg_split('/\s*,\s*/', trim($useMatches[1]));
                    foreach ($useVars as $useVar) {
                        $useVar = trim($useVar);
                        $varName = ltrim($useVar, '$');
                        $useVarNames[] = $varName;
                    }
                } else {
                    // No use() clause found - try to extract variable names from the expression itself
                    // Pattern: fn($e) => $e->Id === $id (where $id is a variable in parent scope)
                    if (preg_match('/=>\s*.+?\$([a-zA-Z_][a-zA-Z0-9_]*)/', $closureCode, $varMatches)) {
                        // Check if it's not the lambda parameter ($e, $u, etc.)
                        $possibleVarName = $varMatches[1];
                        // Lambda parameters are usually single letters like $e, $u, $x
                        if (strlen($possibleVarName) > 1 || !in_array($possibleVarName, ['e', 'u', 'x', 'a', 'i', 'o'])) {
                            $useVarNames[] = $possibleVarName;
                        }
                    }
                }
            }
            
            // Try to get variable values from calling scope using eval (dangerous but necessary)
            // Actually, we can't safely do this. Instead, we'll let the user pass variables explicitly
            // For now, log what we found
            log_message('debug', 'Use variable names found: ' . json_encode($useVarNames));
            log_message('debug', 'Variable values extracted: ' . json_encode($variableValues));
            
            $parser->setVariableValues($variableValues);
            
            // Check if this is a method call like startsWith, contains, endsWith
            // These need special handling as ExpressionParser can't parse them correctly
            $sqlCondition = null;
            $closureCodeForMethodCheck = '';
            if ($closureFile && file_exists($closureFile) && $closureStartLine && $closureEndLine) {
                $lines = file($closureFile);
                $startLine = max(1, $closureStartLine - 5);
                $endLine = min(count($lines), $closureEndLine);
                $closureCodeForMethodCheck = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
            }
            
            // Check for method calls: startsWith, contains, endsWith
            if (preg_match('/\$[a-zA-Z_][a-zA-Z0-9_]*\s*->\s*\$([a-zA-Z_][a-zA-Z0-9_]*)\s*->\s*(startsWith|contains|endsWith)\s*\(\s*\$([a-zA-Z_][a-zA-Z0-9_]*)\s*\)/', $closureCodeForMethodCheck, $methodMatches)) {
                // This is a method call like $e->$field->startsWith($formattedValue)
                $fieldVarName = $methodMatches[1]; // e.g., "field"
                $methodName = $methodMatches[2]; // e.g., "startsWith"
                $valueVarName = $methodMatches[3]; // e.g., "formattedValue"
                
                // Get field name and value from variable values
                $fieldName = $variableValues[$fieldVarName] ?? null;
                $value = $variableValues[$valueVarName] ?? null;
                
                if ($fieldName !== null && $value !== null) {
                    // Get column name from property name
                    $entityReflection = new \ReflectionClass($this->entityType);
                    $columnName = $this->getColumnNameFromProperty($entityReflection, $fieldName);
                    
                    // Get table alias
                    $tableAlias = $this->getTableAliasForParser();
                    $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
                    $quotedAlias = $provider->escapeIdentifier($tableAlias);
                    $quotedColumn = $provider->escapeIdentifier($columnName);
                    
                    // Build SQL condition based on method
                    $escapedValue = str_replace("'", "''", (string)$value);
                    switch ($methodName) {
                        case 'startsWith':
                            $sqlCondition = "{$quotedAlias}.{$quotedColumn} LIKE '{$escapedValue}%'";
                            break;
                        case 'contains':
                            $sqlCondition = "{$quotedAlias}.{$quotedColumn} LIKE '%{$escapedValue}%'";
                            break;
                        case 'endsWith':
                            $sqlCondition = "{$quotedAlias}.{$quotedColumn} LIKE '%{$escapedValue}'";
                            break;
                    }
                    
                    if ($sqlCondition !== null) {
                        log_message('debug', 'Method call detected: ' . $methodName . ' -> SQL: ' . $sqlCondition);
                    }
                }
            }
            
            // If we didn't build SQL from method call, use ExpressionParser
            if ($sqlCondition === null) {
                $sqlCondition = $parser->parse($predicate);
            }
            
            log_message('debug', 'Parsed SQL condition (before cleanup): ' . $sqlCondition);
            
            if (!empty($sqlCondition)) {
                // Get parameter map from parser
                $parameterMap = $parser->getParameterMap();
                
                // Clean up any remaining $variable references that weren't parsed
                // This handles cases where variables weren't properly replaced
                $sqlCondition = preg_replace('/\$[a-zA-Z_][a-zA-Z0-9_]*/', '', $sqlCondition);
                // Remove any -> operators that might have leaked through (not valid in SQL Server)
                $sqlCondition = preg_replace('/\s*->\s*/', ' ', $sqlCondition);
                $sqlCondition = preg_replace('/^->+|->+$/', '', $sqlCondition);
                // Clean up any double spaces or operators
                $sqlCondition = preg_replace('/\s*=\s*=\s*/', ' = ', $sqlCondition);
                $sqlCondition = preg_replace('/\s+/', ' ', $sqlCondition);
                $sqlCondition = trim($sqlCondition);
                
                log_message('debug', 'Parsed SQL condition (after cleanup): ' . $sqlCondition);
                log_message('debug', 'Parameter map: ' . json_encode($parameterMap));
                log_message('debug', 'Variable values: ' . json_encode($variableValues));
                
                // Only apply if we have a valid condition (not empty after cleanup)
                // Check if condition is not just whitespace or -> operators
                $isValid = !empty($sqlCondition) && trim($sqlCondition) !== '' && strpos($sqlCondition, '->') === false;
                if ($isValid) {
                    // Get parameter values for binding
                    $paramValues = $parser->getParameterValues();
                    
                    // If we have ? placeholders, replace them with actual values
                    if (!empty($paramValues) && strpos($sqlCondition, '?') !== false) {
                        $paramIndex = 0;
                        $sqlCondition = preg_replace_callback('/\?/', function() use (&$paramIndex, &$paramValues, &$variableValues, &$parameterMap) {
                            if ($paramIndex >= count($paramValues)) {
                                return 'NULL';
                            }
                            
                            $value = $paramValues[$paramIndex];
                            $paramIndex++;
                            
                            // If value is null, try to get from variableValues using parameter map
                            if ($value === null && !empty($parameterMap)) {
                                $paramKeys = array_keys($parameterMap);
                                if (isset($paramKeys[$paramIndex - 1])) {
                                    $varName = ltrim($parameterMap[$paramKeys[$paramIndex - 1]], '$');
                                    if (isset($variableValues[$varName])) {
                                        $value = $variableValues[$varName];
                                    }
                                }
                            }
                            
                            // Format value for SQL
                            if (is_string($value)) {
                                $value = "'" . str_replace("'", "''", $value) . "'";
                            } elseif (is_numeric($value)) {
                                $value = (string)$value;
                            } elseif (is_bool($value)) {
                                $value = $value ? '1' : '0';
                            } elseif (is_null($value)) {
                                $value = 'NULL';
                            } else {
                                $value = "'" . str_replace("'", "''", (string)$value) . "'";
                            }
                            return $value;
                        }, $sqlCondition);
                        log_message('debug', 'SQL condition after parameter replacement: ' . $sqlCondition);
                    }
                    
                    // Apply the parsed SQL condition
                    // For OR logic, use orWhere() for OR conditions, where() for AND conditions
                    if ($isOr) {
                        // Use orWhere for OR conditions (after the first where clause)
                        $builder->orWhere($sqlCondition, null, false);
                        log_message('debug', 'OR WHERE clause applied: ' . $sqlCondition);
                    } else {
                        // Use where for AND conditions or first OR condition
                        $builder->where($sqlCondition, null, false);
                        log_message('debug', 'WHERE clause applied: ' . $sqlCondition);
                    }
                } else {
                    log_message('debug', 'SQL condition empty or invalid after cleanup, using fallback');
                    // Fallback if cleanup resulted in empty condition
                    $this->applySimpleWhereFallback($builder, $predicate);
                }
            } else {
                log_message('debug', 'SQL condition empty from parser, using fallback');
                // Fallback to old method if parsing fails
                $this->applySimpleWhereFallback($builder, $predicate);
            }
        } catch (\Exception $e) {
            // If parsing fails, fall back to old method
            log_message('debug', 'ExpressionParser failed: ' . $e->getMessage());
            log_message('debug', 'Exception trace: ' . $e->getTraceAsString());
            $this->applySimpleWhereFallback($builder, $predicate);
        }
    }

    /**
     * Fallback method for simple WHERE clauses
     */
    private function applySimpleWhereFallback($builder, callable $predicate): void
    {
        // Old implementation - try to extract simple conditions
        $reflection = new \ReflectionFunction($predicate);
        $code = $this->getFunctionCode($reflection);
        
        // Try to match simple patterns like $x->Property === value
        if (preg_match('/\$[a-zA-Z_][a-zA-Z0-9_]*->([a-zA-Z_][a-zA-Z0-9_]*)\s*(===|==|!==|!=|<=|>=|<|>)\s*(.+?)(?:;|$)/', $code, $matches)) {
            $property = $matches[1];
            $operator = $matches[2] === '===' || $matches[2] === '==' ? '=' : ($matches[2] === '!==' || $matches[2] === '!=' ? '!=' : $matches[2]);
            $value = trim($matches[3]);
            
            // Parse value
            if (preg_match('/^["\'](.+?)["\']$/', $value, $valueMatch)) {
                $value = $valueMatch[1];
            } elseif (is_numeric($value)) {
                // Keep as is
            } else {
                $value = null; // Can't determine value
            }
            
            if ($value !== null) {
                $tableName = $this->context->getTableName($this->entityType);
                $columnName = $this->getColumnNameFromProperty($this->entityType, $property);
                $builder->where("{$tableName}.{$columnName} {$operator}", $value);
            }
        }
    }

    /**
     * Get table alias for current entity (for ExpressionParser)
     */
    private function getTableAliasForParser(): string
    {
        // When using CodeIgniter's base builder (no explicit alias), use the table name.
        // For advanced scenarios (raw joins, includes, masking) aliases are handled separately.
        if ($this->useRawSql || !empty($this->rawJoins) || !empty($this->includes) || !empty($this->requiredJoins)) {
            return 't0';
        }

        return $this->context->getTableName($this->entityType);
    }

    /**
     * Get function source code
     */
    private function getFunctionCode(\ReflectionFunction $reflection): string
    {
        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();
        
        if (!$file || !$start || !$end || !file_exists($file)) {
            return '';
        }
        
        $lines = file($file);
        $code = implode('', array_slice($lines, $start - 1, $end - $start + 1));
        
        return $code;
    }
    
    /**
     * Detect navigation property paths in predicate
     * Returns array of paths like ['Company', 'CustomField']
     */
    private function detectNavigationPaths(callable $predicate): array
    {
        $paths = [];
        
        // Use reflection to get closure source code (if available)
        // This is a simplified approach - in production, you'd want proper expression tree parsing
        $reflection = new \ReflectionFunction($predicate);
        
        // Try to extract navigation property names from closure
        // This is a heuristic approach - not perfect but works for common cases
        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();
        
        if ($file && $start && $end) {
            $lines = file($file);
            $code = implode('', array_slice($lines, $start - 1, $end - $start + 1));
            
            log_message('debug', "Parsing predicate code: " . substr($code, 0, 200));
            
            // Extract patterns like $u->Company->Name or $u->CustomField->CustomField01
            // Pattern: $var->NavProp->Property (NavProp is the navigation property)
            // We want to match: $u->Company->Name (Company is nav prop) or $u->CustomField->CustomField01 (CustomField is nav prop)
            if (preg_match_all('/\$[a-zA-Z_][a-zA-Z0-9_]*->([A-Z][a-zA-Z0-9_]*)->[A-Z][a-zA-Z0-9_]*/', $code, $matches)) {
                foreach ($matches[1] as $navProp) {
                    if (!in_array($navProp, $paths)) {
                        $paths[] = $navProp;
                    }
                }
                log_message('debug', "Detected navigation paths: " . implode(', ', $paths));
            } else {
                log_message('debug', "No navigation paths detected in predicate");
            }
        }
        
        return $paths;
    }
    
    /**
     * Add JOIN for navigation property path
     */
    private function addJoinForNavigationPath($builder, string $navigationProperty): void
    {
        // Check if join already added
        $joinKey = $navigationProperty;
        if (isset($this->requiredJoins[$joinKey])) {
            return;
        }
        
        $entityReflection = new ReflectionClass($this->entityType);
        
        if (!$entityReflection->hasProperty($navigationProperty)) {
            return;
        }
        
        $navProperty = $entityReflection->getProperty($navigationProperty);
        $navProperty->setAccessible(true);
        
        // Get related entity type
        $docComment = $navProperty->getDocComment();
        $relatedEntityType = null;
        $isCollection = false;
        
        if (preg_match('/@var\s+([A-Za-z_][A-Za-z0-9_\\\\]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)(\[\])?/', $docComment, $matches)) {
            $relatedEntityType = $matches[1];
            $isCollection = !empty($matches[2]);
            
            // Resolve namespace using use statements
            if ($relatedEntityType && !str_starts_with($relatedEntityType, '\\')) {
                $resolved = $this->resolveEntityType($relatedEntityType, $entityReflection);
                if ($resolved !== null) {
                    $relatedEntityType = $resolved;
                }
            }
        }
        
        if ($relatedEntityType === null) {
            return;
        }
        
        // Get foreign key
        $foreignKey = $this->getForeignKeyForNavigation($entityReflection, $navigationProperty, $isCollection, $this->entityType);
        $relatedTableName = $this->context->getTableName($relatedEntityType);
        $mainTableName = $this->context->getTableName($this->entityType);
        
        // Get column names
        $fkColumnName = $this->getColumnNameFromProperty($entityReflection, $foreignKey);
        $relatedReflection = new ReflectionClass($relatedEntityType);
        $relatedIdColumn = $this->getColumnNameFromProperty($relatedReflection, 'Id');
        
        // For SQL Server, table names might need to be quoted
        // CodeIgniter should handle this automatically, but let's ensure proper quoting
        $quotedRelatedTable = $this->connection->escapeIdentifiers($relatedTableName);
        $quotedMainTable = $this->connection->escapeIdentifiers($mainTableName);
        $quotedFkColumn = $this->connection->escapeIdentifiers($fkColumnName);
        $quotedRelatedIdColumn = $this->connection->escapeIdentifiers($relatedIdColumn);
        $quotedMainIdColumn = $this->connection->escapeIdentifiers('Id');
        
        if ($isCollection) {
            // One-to-many: Join on related table's foreign key
            $joinCondition = "{$quotedRelatedTable}.{$quotedFkColumn} = {$quotedMainTable}.{$quotedMainIdColumn}";
            $builder->join($relatedTableName, $joinCondition, 'LEFT');
            log_message('debug', "Added JOIN (collection): {$relatedTableName} ON {$joinCondition}");
        } else {
            // Check if FK is in main entity or related entity
            if ($entityReflection->hasProperty($foreignKey)) {
                // Many-to-one: FK in main entity
                $joinCondition = "{$quotedMainTable}.{$quotedFkColumn} = {$quotedRelatedTable}.{$quotedRelatedIdColumn}";
                $builder->join($relatedTableName, $joinCondition, 'LEFT');
                log_message('debug', "Added JOIN (many-to-one): {$relatedTableName} ON {$joinCondition}");
            } else {
                // One-to-one: FK in related entity
                $joinCondition = "{$quotedRelatedTable}.{$quotedFkColumn} = {$quotedMainTable}.{$quotedMainIdColumn}";
                $builder->join($relatedTableName, $joinCondition, 'LEFT');
                log_message('debug', "Added JOIN (one-to-one): {$relatedTableName} ON {$joinCondition}");
            }
        }
        
        $this->requiredJoins[$joinKey] = [
            'table' => $relatedTableName,
            'alias' => $navigationProperty,
            'entityType' => $relatedEntityType
        ];
    }
    
    /**
     * Apply navigation WHERE clause to SQL
     * Converts predicate to SQL WHERE conditions
     */
    private function applyNavigationWhereToSql($builder, callable $predicate, array $navigationPaths): void
    {
        // Get closure source code to parse
        $reflection = new \ReflectionFunction($predicate);
        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();
        
        if (!$file || !$start || !$end) {
            return;
        }
        
        $lines = file($file);
        $code = implode('', array_slice($lines, $start - 1, $end - $start + 1));
        
        // Extract conditions like $u->Company->Name == "Firma 1"
        // Pattern: $var->NavProp->Property == "value" or $var->NavProp->Property === "value"
        $mainTableName = $this->context->getTableName($this->entityType);
        
        foreach ($navigationPaths as $navProp) {
            // Find conditions involving this navigation property
            $pattern = '/\$[a-zA-Z_][a-zA-Z0-9_]*->' . preg_quote($navProp, '/') . '->([A-Za-z0-9_]+)\s*(===|==|!=|<>)\s*["\']([^"\']+)["\']/';
            
            log_message('debug', "Looking for pattern in code for navProp: {$navProp}, pattern: {$pattern}");
            log_message('debug', "Code snippet: " . substr($code, 0, 200));
            
            if (preg_match_all($pattern, $code, $matches, PREG_SET_ORDER)) {
                log_message('debug', "Found " . count($matches) . " matches for navProp: {$navProp}");
                foreach ($matches as $match) {
                    $property = $match[1];
                    $operator = $match[2] === '===' || $match[2] === '==' ? '=' : ($match[2] === '!=' || $match[2] === '<>' ? '!=' : $match[2]);
                    $value = $match[3];
                    
                    log_message('debug', "Match found: property={$property}, operator={$operator}, value={$value}");
                    
                    // Get related table name
                    $joinInfo = $this->requiredJoins[$navProp] ?? null;
                    log_message('debug', "joinInfo for {$navProp}: " . ($joinInfo ? json_encode($joinInfo) : 'NULL'));
                    
                    if ($joinInfo) {
                        $relatedTableName = $joinInfo['table'];
                        $propertyColumn = $this->getColumnNameFromProperty(
                            new ReflectionClass($joinInfo['entityType']),
                            $property
                        );
                        
                        // Escape identifiers for SQL Server
                        $quotedTableName = $this->connection->escapeIdentifiers($relatedTableName);
                        $quotedColumnName = $this->connection->escapeIdentifiers($propertyColumn);
                        
                        // Apply WHERE condition on joined table
                        // CodeIgniter's where() method automatically adds '=' when second parameter is provided
                        // So we only include the column name, not the operator
                        $whereCondition = "{$quotedTableName}.{$quotedColumnName}";
                        
                        if ($operator === '=') {
                            log_message('debug', "Applying WHERE condition: {$whereCondition} = {$value}");
                            $builder->where($whereCondition, $value);
                        } else {
                            // For non-equality operators, we need to use where() with the operator in the condition
                            $whereConditionWithOp = "{$whereCondition} {$operator}";
                            log_message('debug', "Applying WHERE condition: {$whereConditionWithOp} {$value}");
                            $builder->where($whereConditionWithOp, $value, false);
                        }
                    } else {
                        log_message('debug', "WARNING: No join info found for navigation property: {$navProp}");
                    }
                }
            } else {
                log_message('debug', "No matches found for navProp: {$navProp}");
            }
        }
    }

    /**
     * Apply ORDER BY
     */
    private function applyOrderBy($builder, array $orderBy): void
    {
        // Detect navigation property paths in orderBy selector
        $navigationPaths = $this->detectNavigationPaths($orderBy['selector']);
        
        // Also check static variables for navigation property path (from createNavigationKeySelector)
        if (empty($navigationPaths)) {
            $reflection = new \ReflectionFunction($orderBy['selector']);
            $staticVariables = $reflection->getStaticVariables();
            
            // Check for 'field' variable that might contain navigation property path (e.g., "Kadro.Name")
            if (isset($staticVariables['field']) && is_string($staticVariables['field']) && strpos($staticVariables['field'], '.') !== false) {
                $parts = explode('.', $staticVariables['field'], 2);
                $navigationProperty = $parts[0];
                if (!in_array($navigationProperty, $navigationPaths)) {
                    $navigationPaths[] = $navigationProperty;
                    log_message('debug', "applyOrderBy: Detected navigation property from static variable: {$navigationProperty}");
                }
            }
        }
        
        // Add JOINs for navigation properties if needed
        if (!empty($navigationPaths)) {
            foreach ($navigationPaths as $path) {
                $this->addJoinForNavigationPath($builder, $path);
            }
        }
        
        // Use table name as alias for simple queries (CodeIgniter doesn't use aliases by default)
        $tableName = $this->context->getTableName($this->entityType);
        $mainAlias = $tableName;
        $orderBySql = $this->convertOrderByToSql($orderBy['selector'], $orderBy['direction'], $mainAlias);
        if ($orderBySql) {
            // Parse ORDER BY SQL to extract column expression and direction
            // Format: "[alias].[Column] ASC" or "[alias].[Column] DESC"
            if (preg_match('/^(.+?)\s+(ASC|DESC)$/i', trim($orderBySql), $matches)) {
                $columnExpression = trim($matches[1]); // e.g., "[Kadro].[Name]" or "[Employees].[Id]"
                $direction = strtoupper(trim($matches[2]));
                
                // For navigation properties, keep the full expression with alias
                if (!empty($navigationPaths)) {
                    // Remove brackets for CodeIgniter but keep table.column format
                    $columnExpression = preg_replace('/\[|\]/', '', $columnExpression);
                    // Use escape=false to allow table.column format
                    $builder->orderBy($columnExpression, $direction, false);
                } else {
                    // Simple property - extract just column name
                    if (preg_match('/\[?[^\]]+\]?\.\[?([^\]]+)\]?/i', $columnExpression, $colMatches)) {
                        $columnName = $colMatches[1];
                        $builder->orderBy($columnName, $direction);
                    } else {
                        // Fallback: use expression as is
                        $columnExpression = preg_replace('/\[|\]/', '', $columnExpression);
                        $builder->orderBy($columnExpression, $direction, false);
                    }
                }
            } else {
                // Fallback: try to extract column name directly
                $orderBySql = preg_replace('/\[.*?\]\./', '', $orderBySql);
                $orderBySql = preg_replace('/\[|\]/', '', $orderBySql);
                if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s+(ASC|DESC)$/i', trim($orderBySql), $matches)) {
                    $builder->orderBy($matches[1], strtoupper($matches[2]));
                }
            }
        }
    }

    /**
     * Load includes (eager loading)
     */
    private function loadIncludes(array $entities): void
    {
        if (empty($entities)) {
            return;
        }

        $entityReflection = new ReflectionClass($this->entityType);

        foreach ($this->includes as $include) {
            $navigationProperty = $include['path'];
            
            if (!$entityReflection->hasProperty($navigationProperty)) {
                continue;
            }

            $navProperty = $entityReflection->getProperty($navigationProperty);
            $navProperty->setAccessible(true);
            
            // Get property type from docblock or type hint
            $docComment = $navProperty->getDocComment();
            $relatedEntityType = null;
            $isCollection = false;

            // Check if it's a collection (array) or single entity
            // Pattern: @var TypeName[] or @var TypeName
            if (preg_match('/@var\s+([A-Za-z_][A-Za-z0-9_\\\\]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)(\[\])?/', $docComment, $matches)) {
                $relatedEntityType = $matches[1];
                $isCollection = !empty($matches[2]);
                
                // Resolve namespace using use statements
                if ($relatedEntityType && !str_starts_with($relatedEntityType, '\\')) {
                    $resolved = $this->resolveEntityType($relatedEntityType, $entityReflection);
                    if ($resolved !== null) {
                        $relatedEntityType = $resolved;
                    } elseif (!class_exists($relatedEntityType)) {
                        // If not resolved and not a valid class name, set to null
                        $relatedEntityType = null;
                    }
                }
            }

            // Try to get type from property type hint
            if ($relatedEntityType === null && $navProperty->hasType()) {
                $type = $navProperty->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $relatedEntityType = $type->getName();
                    $isCollection = false;
                }
            }

            if ($relatedEntityType === null) {
                log_message('debug', "Could not resolve entity type for navigation property: {$navigationProperty}");
                continue;
            }

            // Get foreign key from attributes or property name
            $foreignKey = $this->getForeignKeyForNavigation($entityReflection, $navigationProperty, $isCollection, $this->entityType);
            
            log_message('debug', "Loading navigation: {$navigationProperty} (type: " . ($isCollection ? 'collection' : 'reference') . ", entity: {$relatedEntityType}, FK: {$foreignKey})");

            if ($isCollection) {
                // Load collection navigation (one-to-many)
                $this->loadCollectionNavigation($entities, $navigationProperty, $foreignKey, $relatedEntityType);
            } else {
                // Load reference navigation (many-to-one or one-to-one)
                $this->loadReferenceNavigation($entities, $navigationProperty, $foreignKey, $relatedEntityType, $this->entityType);
            }
            
            // Load then includes
            if (isset($include['thenIncludes'])) {
                foreach ($include['thenIncludes'] as $thenInclude) {
                    $this->loadThenInclude($entities, $navigationProperty, $thenInclude);
                }
            }
        }
    }

    /**
     * Get foreign key property name for navigation property
     */
    private function getForeignKeyForNavigation(ReflectionClass $entityReflection, string $navigationProperty, bool $isCollection, ?string $parentEntityType = null): ?string
    {
        if ($isCollection) {
            // For collection navigation, foreign key is in the related entity
            // Convention: ParentEntityName + "Id" (e.g., UserId for User entity)
            if ($parentEntityType !== null) {
                $parentClassName = (new ReflectionClass($parentEntityType))->getShortName();
                return $parentClassName . 'Id';
            }
            // Fallback: use current entity type
            $parentClassName = $entityReflection->getShortName();
            return $parentClassName . 'Id';
        } else {
            // For reference navigation, foreign key can be in current entity (many-to-one) 
            // or in related entity (one-to-one)
            // Convention: NavigationPropertyName + "Id" or check ForeignKey attribute
            $fkPropertyName = $navigationProperty . 'Id';
            
            // Check if FK property exists in current entity (many-to-one)
            if ($entityReflection->hasProperty($fkPropertyName)) {
                return $fkPropertyName;
            }

            // Check ForeignKey attribute on properties in current entity
            foreach ($entityReflection->getProperties() as $property) {
                $attributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\ForeignKey::class);
                if (!empty($attributes)) {
                    $fkAttr = $attributes[0]->newInstance();
                    if ($fkAttr->navigationProperty === $navigationProperty) {
                        return $property->getName();
                    }
                }
            }

            // If not found in current entity, it's likely one-to-one where FK is in related entity
            // Convention: ParentEntityName + "Id" (e.g., UserId for User entity)
            if ($parentEntityType !== null) {
                $parentClassName = (new ReflectionClass($parentEntityType))->getShortName();
                return $parentClassName . 'Id';
            }
            
            // Fallback: use current entity type
            $parentClassName = $entityReflection->getShortName();
            return $parentClassName . 'Id';
        }
    }

    /**
     * Load reference navigation (many-to-one or one-to-one)
     */
    private function loadReferenceNavigation(array $entities, string $navigationProperty, string $foreignKey, string $relatedEntityType, ?string $entityType = null): void
    {
        // Use provided entity type or default to $this->entityType
        $entityType = $entityType ?? $this->entityType;
        $entityReflection = new ReflectionClass($entityType);
        
        // Check if foreign key exists in current entity (many-to-one)
        $fkInCurrentEntity = $entityReflection->hasProperty($foreignKey);
        
        if ($fkInCurrentEntity) {
            // Many-to-one: Foreign key is in current entity (e.g., CompanyId in User)
        $foreignKeyValues = [];
        foreach ($entities as $entity) {
            $reflection = new ReflectionClass($entity);
                $property = $reflection->getProperty($foreignKey);
                $property->setAccessible(true);
                $value = $property->getValue($entity);
                if ($value !== null) {
                    $foreignKeyValues[] = $value;
            }
        }
        
        if (empty($foreignKeyValues)) {
            return;
        }
        
            // Load related entities by their Id
        $relatedTableName = $this->context->getTableName($relatedEntityType);
        $builder = $this->connection->table($relatedTableName);
        $builder->whereIn('Id', array_unique($foreignKeyValues));
        try {
            $query = $builder->get();
            $relatedResults = $query->getResultArray();
        } catch (\Exception $e) {
            $sql = $builder->getCompiledSelect(false);
            log_message('error', 'SQL Query Error (Navigation): ' . $e->getMessage());
            log_message('error', 'Failed SQL Query: ' . $sql);
            throw $e;
        }
        
        // Map to entities
            $relatedEntities = $this->mapToEntities($relatedResults, $relatedEntityType);
        
            // Group by Id
        $grouped = [];
        foreach ($relatedEntities as $relatedEntity) {
            $reflection = new ReflectionClass($relatedEntity);
            $idProperty = $reflection->getProperty('Id');
            $idProperty->setAccessible(true);
            $id = $idProperty->getValue($relatedEntity);
            $grouped[$id] = $relatedEntity;
        }
        
        // Assign to navigation properties
        foreach ($entities as $entity) {
                $entityRef = new ReflectionClass($entity);
                $fkProperty = $entityRef->getProperty($foreignKey);
            $fkProperty->setAccessible(true);
            $fkValue = $fkProperty->getValue($entity);
            
            if (isset($grouped[$fkValue])) {
                    $navProperty = $entityRef->getProperty($navigationProperty);
                $navProperty->setAccessible(true);
                $navProperty->setValue($entity, $grouped[$fkValue]);
                }
            }
        } else {
            // Foreign key is NOT in current entity, so it must be in related entity
            // This is one-to-one where FK is in related entity (e.g., UserCustomField.UserId -> User.Id)
            // Get all current entity IDs
            $entityIds = [];
            foreach ($entities as $entity) {
                $entityRef = new ReflectionClass($entity);
                $idProperty = $entityRef->getProperty('Id');
                $idProperty->setAccessible(true);
                $id = $idProperty->getValue($entity);
                if ($id !== null) {
                    $entityIds[] = $id;
                }
            }
            
            if (empty($entityIds)) {
                return;
            }
            
            // Load related entities where foreign key (in related entity) matches current entity IDs
            $relatedTableName = $this->context->getTableName($relatedEntityType);
            $relatedReflection = new ReflectionClass($relatedEntityType);
            
            // Check if foreign key exists in related entity
            if (!$relatedReflection->hasProperty($foreignKey)) {
                log_message('error', "Foreign key '{$foreignKey}' not found in related entity '{$relatedEntityType}'");
                return;
            }
            
            // Get column name from Column attribute or use property name
            $fkColumnName = $this->getColumnNameFromProperty($relatedReflection, $foreignKey);
            
            $builder = $this->connection->table($relatedTableName);
            $builder->whereIn($fkColumnName, array_unique($entityIds));
            try {
                $query = $builder->get();
                $relatedResults = $query->getResultArray();
            } catch (\Exception $e) {
                $sql = $builder->getCompiledSelect(false);
                log_message('error', 'SQL Query Error (Navigation): ' . $e->getMessage());
                log_message('error', 'Failed SQL Query: ' . $sql);
                throw $e;
            }
            
            log_message('debug', "Loading one-to-one navigation (FK in related): {$navigationProperty} from {$relatedTableName} where {$fkColumnName} IN (" . implode(',', $entityIds) . ") - Found " . count($relatedResults) . " results");
            
            // Map to entities
            $relatedEntities = $this->mapToEntities($relatedResults, $relatedEntityType);
            
            // Group by foreign key value
            $grouped = [];
            foreach ($relatedEntities as $relatedEntity) {
                $reflection = new ReflectionClass($relatedEntity);
                $fkProperty = $reflection->getProperty($foreignKey);
                $fkProperty->setAccessible(true);
                $fkValue = $fkProperty->getValue($relatedEntity);
                
                if ($fkValue !== null) {
                    $grouped[$fkValue] = $relatedEntity;
                }
            }
            
            // Assign to navigation properties
            foreach ($entities as $entity) {
                $entityRef = new ReflectionClass($entity);
                $idProperty = $entityRef->getProperty('Id');
                $idProperty->setAccessible(true);
                $id = $idProperty->getValue($entity);
                
                if (isset($grouped[$id])) {
                    $navProperty = $entityRef->getProperty($navigationProperty);
                    $navProperty->setAccessible(true);
                    $navProperty->setValue($entity, $grouped[$id]);
                } else {
                    // Set to null if no related entity found
                    $navProperty = $entityRef->getProperty($navigationProperty);
                    $navProperty->setAccessible(true);
                    $navProperty->setValue($entity, null);
                }
            }
        }
    }

    /**
     * Load collection navigation (one-to-many)
     */
    private function loadCollectionNavigation(array $entities, string $navigationProperty, string $foreignKey, string $relatedEntityType): void
    {
        // Get all entity IDs
        $entityIds = [];

        foreach ($entities as $entity) {
            $entityRef = new ReflectionClass($entity);
            $idProperty = $entityRef->getProperty('Id');
            $idProperty->setAccessible(true);
            $id = $idProperty->getValue($entity);
            if ($id !== null) {
                $entityIds[] = $id;
            }
        }

        if (empty($entityIds)) {
            return;
        }

        // Load related entities where foreign key matches entity IDs
        $relatedTableName = $this->context->getTableName($relatedEntityType);
        $relatedReflection = new ReflectionClass($relatedEntityType);
        $fkColumnName = $this->getColumnNameFromProperty($relatedReflection, $foreignKey);
        
        $builder = $this->connection->table($relatedTableName);
        $builder->whereIn($fkColumnName, array_unique($entityIds));
        try {
            $query = $builder->get();
            $relatedResults = $query->getResultArray();
        } catch (\Exception $e) {
            $sql = $builder->getCompiledSelect(false);
            log_message('error', 'SQL Query Error (Navigation): ' . $e->getMessage());
            log_message('error', 'Failed SQL Query: ' . $sql);
            throw $e;
        }
        
        // Debug log
        log_message('debug', "Loading collection navigation: {$navigationProperty} from {$relatedTableName} where {$foreignKey} IN (" . implode(',', $entityIds) . ") - Found " . count($relatedResults) . " results");

        // Map to entities
        $relatedEntities = $this->mapToEntities($relatedResults, $relatedEntityType);

        // Group by foreign key
        $grouped = [];
        foreach ($relatedEntities as $relatedEntity) {
            $reflection = new ReflectionClass($relatedEntity);
            $fkProperty = $reflection->getProperty($foreignKey);
            $fkProperty->setAccessible(true);
            $fkValue = $fkProperty->getValue($relatedEntity);
            
            if ($fkValue !== null) {
                if (!isset($grouped[$fkValue])) {
                    $grouped[$fkValue] = [];
                }
                $grouped[$fkValue][] = $relatedEntity;
            }
        }

        // Assign to navigation properties
        foreach ($entities as $entity) {
            $entityRef = new ReflectionClass($entity);
            $idProperty = $entityRef->getProperty('Id');
            $idProperty->setAccessible(true);
            $id = $idProperty->getValue($entity);
            
            if (isset($grouped[$id])) {
                $navProperty = $entityRef->getProperty($navigationProperty);
                $navProperty->setAccessible(true);
                $navProperty->setValue($entity, $grouped[$id]);
            } else {
                // Initialize empty array if no related entities
                $navProperty = $entityRef->getProperty($navigationProperty);
                $navProperty->setAccessible(true);
                $navProperty->setValue($entity, []);
            }
        }
    }

    /**
     * Load then include (nested navigation properties)
     */
    private function loadThenInclude(array $entities, string $parentNavigation, string $navigationProperty): void
    {
        // Get all parent navigation property values
        $parentEntities = [];
        $entityReflection = new ReflectionClass($this->entityType);
        $parentNavProperty = $entityReflection->getProperty($parentNavigation);
        $parentNavProperty->setAccessible(true);

        foreach ($entities as $entity) {
            $parentValue = $parentNavProperty->getValue($entity);
            
            if (is_array($parentValue)) {
                // Collection navigation
                foreach ($parentValue as $parentEntity) {
                    if ($parentEntity !== null) {
                        $parentEntities[] = $parentEntity;
                    }
                }
            } elseif ($parentValue !== null) {
                // Reference navigation
                $parentEntities[] = $parentValue;
            }
        }

        if (empty($parentEntities)) {
            return;
        }

        // Get the type of parent entities
        $parentEntityType = get_class($parentEntities[0]);
        $parentReflection = new ReflectionClass($parentEntityType);

        if (!$parentReflection->hasProperty($navigationProperty)) {
            return;
        }

        $navProperty = $parentReflection->getProperty($navigationProperty);
        $navProperty->setAccessible(true);
        
        // Get docblock to determine if collection or reference
        $docComment = $navProperty->getDocComment();
        $relatedEntityType = null;
        $isCollection = false;

        if (preg_match('/@var\s+([A-Za-z_][A-Za-z0-9_\\\\]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)(\[\])?/', $docComment, $matches)) {
            $relatedEntityType = $matches[1];
            $isCollection = !empty($matches[2]);
            
            // Resolve namespace using use statements
            if ($relatedEntityType && !str_starts_with($relatedEntityType, '\\')) {
                $resolved = $this->resolveEntityType($relatedEntityType, $parentReflection);
                if ($resolved !== null) {
                    $relatedEntityType = $resolved;
                } elseif (!class_exists($relatedEntityType)) {
                    // If not resolved and not a valid class name, set to null
                    $relatedEntityType = null;
                }
            }
        }

        if ($relatedEntityType === null) {
            return;
        }

        // Get foreign key
        $foreignKey = $this->getForeignKeyForNavigation($parentReflection, $navigationProperty, $isCollection, $parentEntityType);

        if ($isCollection) {
            $this->loadCollectionNavigation($parentEntities, $navigationProperty, $foreignKey, $relatedEntityType);
        } else {
            $this->loadReferenceNavigation($parentEntities, $navigationProperty, $foreignKey, $relatedEntityType, $parentEntityType);
        }
    }

    /**
     * Batch insert entities
     */
    public function batchInsert(array $entities): int
    {
        if (empty($entities)) {
            return 0;
        }
        
        $tableName = $this->context->getTableName($this->entityType);
        $data = [];
        
        foreach ($entities as $entity) {
            $row = $this->entityToArray($entity);
            $data[] = $row;
        }
        
        return $this->connection->table($tableName)->insertBatch($data) ? count($data) : 0;
    }

    /**
     * Batch update entities
     */
    public function batchUpdate(array $entities): int
    {
        if (empty($entities)) {
            return 0;
        }
        
        $tableName = $this->context->getTableName($this->entityType);
        $updated = 0;
        
        foreach ($entities as $entity) {
            $row = $this->entityToArray($entity);
            $reflection = new ReflectionClass($entity);
            $idProperty = $reflection->getProperty('Id');
            $idProperty->setAccessible(true);
            $id = $idProperty->getValue($entity);
            
            // Remove Id from update data
            unset($row['Id']);
            
            if ($this->connection->table($tableName)->where('Id', $id)->update($row)) {
                $updated++;
            }
        }
        
        return $updated;
    }

    /**
     * Batch delete entities by IDs
     */
    public function batchDelete(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }
        
        $tableName = $this->context->getTableName($this->entityType);
        $result = $this->connection->table($tableName)->whereIn('Id', $ids)->delete();
        return $result ? count($ids) : 0;
    }

    /**
     * Convert entity to array
     */
    private function entityToArray(object $entity): array
    {
        $reflection = new ReflectionClass($entity);
        $data = [];
        
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            
            $property->setAccessible(true);
            $value = $property->getValue($entity);
            
            // Skip navigation properties
            if (is_object($value) && !($value instanceof \DateTime)) {
                continue;
            }
            
            $columnName = $this->propertyToColumn($property->getName());
            $data[$columnName] = $value;
        }
        
        return $data;
    }

    /**
     * Convert property name to column name
     */
    private function propertyToColumn(string $property): string
    {
        // Convert camelCase to snake_case
        return strtolower(preg_replace('/([A-Z])/', '_$1', lcfirst($property)));
    }

    /**
     * Get column name from Column attribute or property name
     */
    /**
     * Cache for column names (entity class name + property name => column name)
     */
    private static array $columnNameCache = [];
    
    private function getColumnNameFromProperty(ReflectionClass $entityReflection, string $propertyName): string
    {
        $entityClassName = $entityReflection->getName();
        $cacheKey = $entityClassName . '::' . $propertyName;
        
        // Check cache first
        if (isset(self::$columnNameCache[$cacheKey])) {
            return self::$columnNameCache[$cacheKey];
        }
        
        if ($entityReflection->hasProperty($propertyName)) {
            $property = $entityReflection->getProperty($propertyName);
            $attributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Column::class);
            
            if (!empty($attributes)) {
                $columnAttr = $attributes[0]->newInstance();
                if ($columnAttr->name !== null) {
                    self::$columnNameCache[$cacheKey] = $columnAttr->name;
                    return $columnAttr->name;
                }
            }
        }
        
        // Fallback: use property name as-is (SQL Server typically uses PascalCase)
        self::$columnNameCache[$cacheKey] = $propertyName;
        return $propertyName;
    }

    /**
     * Get primary key column name from entity
     * 
     * @param ReflectionClass $entityReflection Entity reflection
     * @return string Primary key column name
     */
    /**
     * Cache for primary key column names (entity class name => column name)
     */
    private static array $primaryKeyColumnCache = [];
    
    private function getPrimaryKeyColumnName(ReflectionClass $entityReflection): string
    {
        $entityClassName = $entityReflection->getName();
        
        // Check cache first
        if (isset(self::$primaryKeyColumnCache[$entityClassName])) {
            return self::$primaryKeyColumnCache[$entityClassName];
        }
        
        // First, try to find property with [Key] attribute
        foreach ($entityReflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            
            $keyAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Key::class);
            if (!empty($keyAttributes)) {
                $propertyName = $property->getName();
                $columnName = $this->getColumnNameFromProperty($entityReflection, $propertyName);
                self::$primaryKeyColumnCache[$entityClassName] = $columnName;
                return $columnName;
            }
        }
        
        // Fallback: try common primary key names (Id, {EntityName}Id)
        $commonNames = ['Id', $entityReflection->getShortName() . 'Id'];
        foreach ($commonNames as $name) {
            if ($entityReflection->hasProperty($name)) {
                $columnName = $this->getColumnNameFromProperty($entityReflection, $name);
                self::$primaryKeyColumnCache[$entityClassName] = $columnName;
                return $columnName;
            }
        }
        
        // Last resort: return 'Id'
        self::$primaryKeyColumnCache[$entityClassName] = 'Id';
        return 'Id';
    }

    /**
     * Build EF Core style SQL query with subqueries and JOINs
     * Returns complete SQL string similar to C# EF Core
     */
    private function buildEfCoreStyleQuery(): string
    {
        log_message('debug', 'buildEfCoreStyleQuery: Starting query build for entity ' . $this->entityType);
        
        $tableName = $this->context->getTableName($this->entityType);
        $mainAlias = 'u'; // Main entity alias
        $subqueryAlias = 's'; // Subquery alias
        
        log_message('debug', "buildEfCoreStyleQuery: Table name: {$tableName}, Main alias: {$mainAlias}, Subquery alias: {$subqueryAlias}");
        
        // Detect navigation paths for WHERE clauses
        $navigationFilters = [];
        $allNavigationPaths = [];
        $navigationPathsInWhere = []; // Track which paths are used in WHERE clauses
        foreach ($this->wheres as $index => $whereItem) {
            $where = is_array($whereItem) ? $whereItem['predicate'] : $whereItem;
            $paths = $this->detectNavigationPaths($where);
            if (!empty($paths)) {
                $navigationFilters[$index] = $where;
                foreach ($paths as $path) {
                    if (!in_array($path, $allNavigationPaths)) {
                        $allNavigationPaths[] = $path;
                    }
                    if (!in_array($path, $navigationPathsInWhere)) {
                        $navigationPathsInWhere[] = $path;
                    }
                }
            }
        }
        
        log_message('debug', 'buildEfCoreStyleQuery: Navigation paths from WHERE clauses: ' . implode(', ', $allNavigationPaths));
        
        // Track which navigation paths are from thenIncludes
        $thenIncludePaths = [];
        foreach ($this->includes as $include) {
            $navPath = $include['path'] ?? $include['navigation'] ?? null;
            if ($navPath !== null) {
                // Check for thenIncludes
                $thenIncludes = $include['thenIncludes'] ?? [];
                foreach ($thenIncludes as $thenInclude) {
                    // Build full path: parentNav.thenInclude
                    $fullPath = $navPath . '.' . $thenInclude;
                    $thenIncludePaths[] = $fullPath;
                    // Also add just the thenInclude name for nested collection subqueries
                    $thenIncludePaths[] = $thenInclude;
                }
            }
        }
        
        // Also add navigation paths from includes to allNavigationPaths
        foreach ($this->includes as $include) {
            $navPath = $include['path'] ?? $include['navigation'] ?? null;
            if ($navPath !== null && !in_array($navPath, $allNavigationPaths)) {
                $allNavigationPaths[] = $navPath;
            }
        }
        
        log_message('debug', 'buildEfCoreStyleQuery: All navigation paths (includes + WHERE): ' . implode(', ', $allNavigationPaths));
        
        // Get all entity columns
        $entityReflection = new ReflectionClass($this->entityType);
        $entityColumns = $this->getEntityColumns($entityReflection);
        $columnsWithProperties = $this->getEntityColumnsWithProperties($entityReflection);
        
        log_message('debug', 'buildEfCoreStyleQuery: Found ' . count($entityColumns) . ' entity columns: ' . implode(', ', $entityColumns));
        
        // Build main subquery SELECT columns with masking support
        $mainSelectColumns = [];
        $columnIndex = 0;
        $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
        
        foreach ($columnsWithProperties as $colInfo) {
            $col = $colInfo['column'];
            $property = $entityReflection->getProperty($colInfo['property']);
            $sensitiveAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\SensitiveValue::class);
            
            // Use provider's escapeIdentifier for database-specific formatting
            $quotedCol = $provider->escapeIdentifier($col);
            $quotedAlias = $provider->escapeIdentifier($mainAlias);
            
            if (!empty($sensitiveAttributes) && !$this->isSensitive) {
                // Apply masking
                $sensitiveAttr = $sensitiveAttributes[0]->newInstance();
                $columnRef = "{$quotedAlias}.{$quotedCol}";
                $maskedExpression = $provider->getMaskingSql(
                    $columnRef,
                    $sensitiveAttr->maskChar,
                    $sensitiveAttr->visibleStart,
                    $sensitiveAttr->visibleEnd,
                    $sensitiveAttr->customMask
                );
                $mainSelectColumns[] = "({$maskedExpression}) AS {$quotedCol}";
            } else {
                // No masking
                $mainSelectColumns[] = "{$quotedAlias}.{$quotedCol}";
            }
        }
        
        // Add reference navigation columns (from includes and WHERE filters)
        $referenceNavAliases = [];
        $this->referenceNavIndexes = []; // Store index for each navigation path for ORDER BY and parsing
        $referenceNavIndex = 0;
        // Track thenInclude collection subqueries for reference navigations
        $referenceNavThenIncludeSubqueries = []; // [navPath => [subquery1, subquery2, ...]]
        foreach ($allNavigationPaths as $navPath) {
            $navInfo = $this->getNavigationInfo($navPath);
            log_message('debug', "buildEfCoreStyleQuery: Navigation path '{$navPath}' - navInfo: " . ($navInfo ? json_encode($navInfo) : 'null'));
            if ($navInfo && !$navInfo['isCollection']) {
                $refAlias = $this->getTableAlias($navPath, $referenceNavIndex);
                $referenceNavAliases[$navPath] = $refAlias;
                $this->referenceNavIndexes[$navPath] = $referenceNavIndex; // Store index for ORDER BY and parsing
                log_message('debug', "buildEfCoreStyleQuery: Added reference navigation alias '{$refAlias}' for '{$navPath}' (index: {$referenceNavIndex})");
                $refEntityReflection = new ReflectionClass($navInfo['entityType']);
                $refColumnsWithProperties = $this->getEntityColumnsWithProperties($refEntityReflection);
                
                // Get primary key column name for reference entity
                // getPrimaryKeyColumnName uses getColumnNameFromProperty which already handles [Column] attribute correctly
                $refPrimaryKeyColumn = $this->getPrimaryKeyColumnName($refEntityReflection);
                
                // Get primary key property name for skipping it in the loop
                $refPrimaryKeyProperty = null;
                foreach ($refEntityReflection->getProperties() as $prop) {
                    $keyAttributes = $prop->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Key::class);
                    if (!empty($keyAttributes)) {
                        $refPrimaryKeyProperty = $prop->getName();
                        break;
                    }
                }
                if ($refPrimaryKeyProperty === null) {
                    $commonNames = ['Id', $refEntityReflection->getShortName() . 'Id'];
                    foreach ($commonNames as $name) {
                        if ($refEntityReflection->hasProperty($name)) {
                            $refPrimaryKeyProperty = $name;
                            break;
                        }
                    }
                }
                
                // Check for thenIncludes for this reference navigation
                // Multiple includes can have the same navigation path with different thenIncludes
                // Merge all thenIncludes for this navigation path
                $thenIncludes = [];
                foreach ($this->includes as $include) {
                    $includeNavPath = $include['path'] ?? $include['navigation'] ?? null;
                    if ($includeNavPath === $navPath && isset($include['thenIncludes'])) {
                        // Merge thenIncludes (avoid duplicates)
                        foreach ($include['thenIncludes'] as $thenInclude) {
                            if (!in_array($thenInclude, $thenIncludes)) {
                                $thenIncludes[] = $thenInclude;
                            }
                        }
                    }
                }
                
                // Process thenIncludes for reference navigation
                // Note: Collection subquery indexes will be assigned later when all main collection navigations are processed
                $thenIncludeNestedSubqueryIndex = 1;
                $thenIncludeCollectionIndex = 1; // Index for reference thenInclude navigations (for alias generation, start at 1 to avoid conflicts)
                foreach ($thenIncludes as $thenInclude) {
                    $thenNavInfo = $this->getNavigationInfoForEntity($thenInclude, $navInfo['entityType']);
                    if ($thenNavInfo && $thenNavInfo['isCollection']) {
                        // Collection thenInclude - will create subquery later with correct index
                        // Store info for later processing
                        if (!isset($referenceNavThenIncludeSubqueries[$navPath])) {
                            $referenceNavThenIncludeSubqueries[$navPath] = [];
                        }
                        $referenceNavThenIncludeSubqueries[$navPath][] = [
                            'navigation' => $navPath . '.' . $thenInclude,
                            'navInfo' => $thenNavInfo,
                            'nestedSubqueryIndex' => $thenIncludeNestedSubqueryIndex
                        ];
                        $thenIncludeNestedSubqueryIndex++;
                    } elseif ($thenNavInfo && !$thenNavInfo['isCollection']) {
                        // Reference navigation thenInclude - add nested JOIN and columns
                        // Use unique index for nested reference navigation to avoid alias conflicts
                        // For nested navigations, use the thenInclude name itself to generate unique alias
                        $nestedRefIndex = $referenceNavIndex * 100 + $thenIncludeCollectionIndex;
                        // Use thenInclude name (e.g., "CustomField") instead of full path to avoid conflicts
                        $thenRefAlias = $this->getTableAlias($thenInclude, $nestedRefIndex);
                        $thenRefEntityReflection = new ReflectionClass($thenNavInfo['entityType']);
                        $thenRefColumnsWithProperties = $this->getEntityColumnsWithProperties($thenRefEntityReflection);
                        $thenRefPrimaryKeyColumn = $this->getPrimaryKeyColumnName($thenRefEntityReflection);
                        
                        // Get primary key property name
                        $thenRefPrimaryKeyProperty = null;
                        foreach ($thenRefEntityReflection->getProperties() as $prop) {
                            $keyAttributes = $prop->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Key::class);
                            if (!empty($keyAttributes)) {
                                $thenRefPrimaryKeyProperty = $prop->getName();
                                break;
                            }
                        }
                        if ($thenRefPrimaryKeyProperty === null) {
                            $commonNames = ['Id', $thenRefEntityReflection->getShortName() . 'Id'];
                            foreach ($commonNames as $name) {
                                if ($thenRefEntityReflection->hasProperty($name)) {
                                    $thenRefPrimaryKeyProperty = $name;
                                    break;
                                }
                            }
                        }
                        
                        // Add columns for nested reference navigation
                        $thenFirstCol = true;
                        $quotedThenRefAlias = $provider->escapeIdentifier($thenRefAlias);
                        foreach ($thenRefColumnsWithProperties as $colInfo) {
                            $col = $colInfo['column'];
                            $property = $thenRefEntityReflection->getProperty($colInfo['property']);
                            $sensitiveAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\SensitiveValue::class);
                            
                            $quotedRefCol = $provider->escapeIdentifier($col);
                            
                            if ($thenFirstCol && $col === $thenRefPrimaryKeyColumn) {
                                // Use a unique index for nested reference navigation
                                $nestedRefIndex = $referenceNavIndex * 100 + $thenIncludeCollectionIndex; // e.g., 0 -> 0, 1 -> 100, etc.
                                $quotedIdAlias = $provider->escapeIdentifier("Id{$nestedRefIndex}");
                                $mainSelectColumns[] = "{$quotedThenRefAlias}.{$quotedRefCol} AS {$quotedIdAlias}";
                                $thenFirstCol = false;
                            } else {
                                // Apply masking for sensitive columns
                                if (!empty($sensitiveAttributes) && !$this->isSensitive) {
                                    $sensitiveAttr = $sensitiveAttributes[0]->newInstance();
                                    $columnRef = "{$quotedThenRefAlias}.{$quotedRefCol}";
                                    $maskedExpression = $provider->getMaskingSql(
                                        $columnRef,
                                        $sensitiveAttr->maskChar,
                                        $sensitiveAttr->visibleStart,
                                        $sensitiveAttr->visibleEnd,
                                        $sensitiveAttr->customMask
                                    );
                                    $nestedRefIndex = $referenceNavIndex * 100 + $thenIncludeCollectionIndex;
                                    $quotedColAlias = $provider->escapeIdentifier("{$col}{$nestedRefIndex}");
                                    $mainSelectColumns[] = "({$maskedExpression}) AS {$quotedColAlias}";
                                } else {
                                    $nestedRefIndex = $referenceNavIndex * 100 + $thenIncludeCollectionIndex;
                                    $quotedColAlias = $provider->escapeIdentifier("{$col}{$nestedRefIndex}");
                                    $mainSelectColumns[] = "{$quotedThenRefAlias}.{$quotedRefCol} AS {$quotedColAlias}";
                                }
                            }
                        }
                        
                        // Store nested reference navigation alias and index for JOIN and final SELECT
                        $nestedNavPath = $navPath . '.' . $thenInclude;
                        $referenceNavAliases[$nestedNavPath] = $thenRefAlias;
                        $nestedRefIndex = $referenceNavIndex * 100 + $thenIncludeCollectionIndex;
                        $this->referenceNavIndexes[$nestedNavPath] = $nestedRefIndex; // Store index for final SELECT and parsing
                        $thenIncludeCollectionIndex++; // Increment for next reference thenInclude
                    }
                }
                
                // First column gets alias Id0, Id1, etc. (use primary key column, not hardcoded 'Id')
                // Other columns need to be aliased to avoid conflicts (e.g., ProjectID, ReferanceID, Name from multiple navigations)
                $firstCol = true;
                $quotedRefAlias = $provider->escapeIdentifier($refAlias);
                
                foreach ($refColumnsWithProperties as $colInfo) {
                    $col = $colInfo['column'];
                    $property = $refEntityReflection->getProperty($colInfo['property']);
                    $sensitiveAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\SensitiveValue::class);
                    
                    $quotedRefCol = $provider->escapeIdentifier($col);
                    
                    if ($firstCol && $col === $refPrimaryKeyColumn) {
                        $quotedIdAlias = $provider->escapeIdentifier("Id{$referenceNavIndex}");
                        $mainSelectColumns[] = "{$quotedRefAlias}.{$quotedRefCol} AS {$quotedIdAlias}";
                        $firstCol = false;
                    } else {
                        // Apply masking for sensitive columns in reference navigation
                        if (!empty($sensitiveAttributes) && !$this->isSensitive) {
                            $sensitiveAttr = $sensitiveAttributes[0]->newInstance();
                            $columnRef = "{$quotedRefAlias}.{$quotedRefCol}";
                            $maskedExpression = $provider->getMaskingSql(
                                $columnRef,
                                $sensitiveAttr->maskChar,
                                $sensitiveAttr->visibleStart,
                                $sensitiveAttr->visibleEnd,
                                $sensitiveAttr->customMask
                            );
                            // Alias masked columns to avoid conflicts
                            $quotedColAlias = $provider->escapeIdentifier("{$col}{$referenceNavIndex}");
                            $mainSelectColumns[] = "({$maskedExpression}) AS {$quotedColAlias}";
                        } else {
                            // Alias all columns to avoid conflicts with same column names from different navigations
                            $quotedColAlias = $provider->escapeIdentifier("{$col}{$referenceNavIndex}");
                            $mainSelectColumns[] = "{$quotedRefAlias}.{$quotedRefCol} AS {$quotedColAlias}";
                        }
                    }
                }
                $referenceNavIndex++;
            }
        }
        
        // Build main subquery FROM and JOINs
        $quotedTableName = $provider->escapeIdentifier($tableName);
        $quotedMainAlias = $provider->escapeIdentifier($mainAlias);
        $mainFrom = "FROM {$quotedTableName} AS {$quotedMainAlias}";
        $mainJoins = [];
        
        // Add JOINs for reference navigations (many-to-one, one-to-one)
        foreach ($allNavigationPaths as $navPath) {
            $navInfo = $this->getNavigationInfo($navPath);
            if ($navInfo && !$navInfo['isCollection']) {
                if (!isset($referenceNavAliases[$navPath])) {
                    log_message('error', "buildEfCoreStyleQuery: Missing alias for navigation path '{$navPath}'");
                    continue;
                }
                $refAlias = $referenceNavAliases[$navPath];
                $refTableName = $this->context->getTableName($navInfo['entityType']);
                $joinCondition = $this->buildJoinCondition($mainAlias, $refAlias, $navPath, $navInfo);
                // Check if this path is used in WHERE clause (should be INNER JOIN) or is a thenInclude
                $isInWhere = in_array($navPath, $navigationPathsInWhere);
                $isThenInclude = in_array($navPath, $thenIncludePaths);
                $joinType = $this->getJoinType($navPath, $navInfo, $isInWhere, $isThenInclude);
                $quotedRefTableName = $provider->escapeIdentifier($refTableName);
                $quotedRefAlias = $provider->escapeIdentifier($refAlias);
                $joinSql = "{$joinType} {$quotedRefTableName} AS {$quotedRefAlias} ON {$joinCondition}";
                $mainJoins[] = $joinSql;
                log_message('debug', "buildEfCoreStyleQuery: Added JOIN for '{$navPath}': {$joinSql}");
                
                // Add JOINs for thenInclude reference navigations
                // Multiple includes can have the same navigation path with different thenIncludes
                // Process all thenIncludes for this navigation path
                $processedThenIncludes = [];
                foreach ($this->includes as $include) {
                    $includeNavPath = $include['path'] ?? $include['navigation'] ?? null;
                    if ($includeNavPath === $navPath && isset($include['thenIncludes'])) {
                        foreach ($include['thenIncludes'] as $thenInclude) {
                            // Skip if already processed
                            if (in_array($thenInclude, $processedThenIncludes)) {
                                continue;
                            }
                            $processedThenIncludes[] = $thenInclude;
                            $thenNavInfo = $this->getNavigationInfoForEntity($thenInclude, $navInfo['entityType']);
                            if ($thenNavInfo && !$thenNavInfo['isCollection']) {
                                // Reference navigation thenInclude - add nested JOIN
                                $thenIncludePath = $navPath . '.' . $thenInclude;
                                if (isset($referenceNavAliases[$thenIncludePath])) {
                                    $thenRefAlias = $referenceNavAliases[$thenIncludePath];
                                    log_message('debug', "buildEfCoreStyleQuery: For nested JOIN '{$thenIncludePath}', using alias '{$thenRefAlias}' from referenceNavAliases");
                                    $thenRefTableName = $this->context->getTableName($thenNavInfo['entityType']);
                                    
                                    // Build JOIN condition using buildJoinCondition method
                                    // Parent entity is the reference navigation (e.g., Employee), not main entity (Card)
                                    // This method correctly handles cases where FK is in parent entity or related entity
                                    $thenJoinCondition = $this->buildJoinCondition($refAlias, $thenRefAlias, $thenIncludePath, $thenNavInfo, $navInfo['entityType']);
                                    
                                    // thenInclude uses LEFT JOIN to allow null values (similar to include behavior)
                                    $quotedThenRefTableName = $provider->escapeIdentifier($thenRefTableName);
                                    $quotedThenRefAlias = $provider->escapeIdentifier($thenRefAlias);
                                    $thenJoinSql = "LEFT JOIN {$quotedThenRefTableName} AS {$quotedThenRefAlias} ON {$thenJoinCondition}";
                                    $mainJoins[] = $thenJoinSql;
                                    log_message('debug', "buildEfCoreStyleQuery: Added nested JOIN for '{$thenIncludePath}': {$thenJoinSql}");
                                }
                            }
                        }
                    }
                }
            } else {
                if ($navInfo && $navInfo['isCollection']) {
                    log_message('debug', "buildEfCoreStyleQuery: Skipping JOIN for collection navigation '{$navPath}' (will be loaded via subquery)");
                } else {
                    log_message('warning', "buildEfCoreStyleQuery: getNavigationInfo returned null for '{$navPath}'");
                }
            }
        }
        
        // Build WHERE clause
        $whereConditions = [];
        foreach ($this->wheres as $index => $whereItem) {
            $where = is_array($whereItem) ? $whereItem['predicate'] : $whereItem;
            if (isset($navigationFilters[$index])) {
                $paths = $this->detectNavigationPaths($where);
                // Pass referenceNavAliases to use correct aliases in WHERE clause
                $sqlWhere = $this->convertNavigationWhereToSql($where, $paths, $referenceNavAliases);
                if ($sqlWhere) {
                    $whereConditions[] = $sqlWhere;
                }
            } else {
                $sqlWhere = $this->convertSimpleWhereToSql($where, $mainAlias);
                if ($sqlWhere) {
                    $whereConditions[] = $sqlWhere;
                }
            }
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Build OFFSET/FETCH
        // Only apply if takeCount is set and > 0 (negative values mean no limit)
        $offsetFetch = '';
        if ($this->takeCount !== null && $this->takeCount > 0) {
            $offset = $this->skipCount ?? 0;
            $offsetFetch = "OFFSET {$offset} ROWS FETCH NEXT {$this->takeCount} ROWS ONLY";
        } elseif ($this->skipCount !== null && $this->skipCount > 0) {
            // If only skip is set (no take), use a large number for fetch
            $offsetFetch = "OFFSET {$this->skipCount} ROWS FETCH NEXT 999999 ROWS ONLY";
        }
        
        // Build ORDER BY for main subquery
        // SQL Server requires TOP, OFFSET or FOR XML when using ORDER BY in subqueries
        // So we only add ORDER BY if we have OFFSET/FETCH clause
        $mainOrderByClause = '';
        if (!empty($offsetFetch)) {
            // Only add ORDER BY if we have OFFSET/FETCH (which allows ORDER BY in subquery)
            if (!empty($this->orderBys)) {
                // First, populate requiredJoins for ORDER BY navigation properties
                // This ensures convertOrderByToSql can find join info
                // Note: collectionSubqueries will be built later, so we need to handle collection navigation properties differently
                foreach ($this->orderBys as $orderBy) {
                    // Detect navigation property paths in orderBy selector
                    $navigationPaths = $this->detectNavigationPaths($orderBy['selector']);
                    
                    // Also check static variables for navigation property path (from createNavigationKeySelector)
                    if (empty($navigationPaths)) {
                        $reflection = new \ReflectionFunction($orderBy['selector']);
                        $staticVariables = $reflection->getStaticVariables();
                        
                        // Check for 'field' variable that might contain navigation property path (e.g., "Kadro.Name" or "Department.DepartmentName")
                        if (isset($staticVariables['field']) && is_string($staticVariables['field']) && strpos($staticVariables['field'], '.') !== false) {
                            $parts = explode('.', $staticVariables['field'], 2);
                            $navigationProperty = $parts[0];
                            if (!in_array($navigationProperty, $navigationPaths)) {
                                $navigationPaths[] = $navigationProperty;
                                log_message('debug', "buildEfCoreStyleQuery: Detected navigation property from static variable for ORDER BY: {$navigationProperty}");
                            }
                        }
                    }
                    
                    // Add join info to requiredJoins for each navigation property
                    foreach ($navigationPaths as $navPath) {
                        if (!isset($this->requiredJoins[$navPath])) {
                            $navInfo = $this->getNavigationInfo($navPath);
                            if ($navInfo && !$navInfo['isCollection']) {
                                // Reference navigation property - add to requiredJoins
                                $refTableName = $this->context->getTableName($navInfo['entityType']);
                                $this->requiredJoins[$navPath] = [
                                    'table' => $refTableName,
                                    'alias' => $referenceNavAliases[$navPath] ?? $navPath,
                                    'entityType' => $navInfo['entityType']
                                ];
                                log_message('debug', "buildEfCoreStyleQuery: Added requiredJoins for ORDER BY navigation '{$navPath}': table={$refTableName}, alias={$this->requiredJoins[$navPath]['alias']}");
                            } else if ($navInfo && $navInfo['isCollection']) {
                                // Collection navigation property - will be handled via collection subquery
                                // We'll need to find the collection subquery later in convertOrderByToSql
                                log_message('debug', "buildEfCoreStyleQuery: Navigation property '{$navPath}' is a collection, will be handled via collection subquery");
                            }
                        }
                    }
                }
                
                $mainOrderByColumns = [];
                foreach ($this->orderBys as $orderBy) {
                    // Check if this is a collection navigation property ORDER BY
                    // Collection navigation properties can only be used in final query ORDER BY, not main subquery
                    $reflection = new \ReflectionFunction($orderBy['selector']);
                    $staticVariables = $reflection->getStaticVariables();
                    $isCollectionNav = false;
                    
                    // Check for navigation property path in static variables (e.g., "Department.DepartmentName")
                    if (isset($staticVariables['field']) && is_string($staticVariables['field']) && strpos($staticVariables['field'], '.') !== false) {
                        $parts = explode('.', $staticVariables['field'], 2);
                        $firstNavProp = $parts[0];
                        
                        // Check if firstNavProp is accessed via a collection navigation property
                        foreach ($allNavigationPaths as $navPath) {
                            $navInfo = $this->getNavigationInfo($navPath);
                            if ($navInfo && $navInfo['isCollection']) {
                                // Check if firstNavProp is a navigation property in the collection entity
                                $thenNavInfo = $this->getNavigationInfoForEntity($firstNavProp, $navInfo['entityType']);
                                if ($thenNavInfo) {
                                    $isCollectionNav = true;
                                    log_message('debug', "buildEfCoreStyleQuery: Skipping collection navigation ORDER BY '{$staticVariables['field']}' in main subquery (will be used in final query)");
                                    break;
                                }
                            }
                        }
                    }
                    
                    // Only add ORDER BY for non-collection navigation properties in main subquery
                    if (!$isCollectionNav) {
                        $orderBySql = $this->convertOrderByToSql($orderBy['selector'], $orderBy['direction'], $mainAlias);
                        if ($orderBySql) {
                            $mainOrderByColumns[] = $orderBySql;
                        }
                    }
                }
                if (!empty($mainOrderByColumns)) {
                    $mainOrderByClause = "ORDER BY " . implode(', ', $mainOrderByColumns) . "\n";
                }
            }
            
            // If no ORDER BY from user but we have OFFSET/FETCH, use default
            if (empty($mainOrderByClause)) {
                $mainOrderByClause = "ORDER BY (SELECT 1)\n";
            }
        }
        
        // Build main subquery
        log_message('debug', 'buildEfCoreStyleQuery: Building main subquery with ' . count($mainSelectColumns) . ' columns');
        log_message('debug', 'buildEfCoreStyleQuery: Main subquery FROM: ' . $mainFrom);
        log_message('debug', 'buildEfCoreStyleQuery: Main subquery JOINs: ' . count($mainJoins));
        log_message('debug', 'buildEfCoreStyleQuery: WHERE clause: ' . ($whereClause ?: 'none'));
        log_message('debug', 'buildEfCoreStyleQuery: ORDER BY clause: ' . trim($mainOrderByClause));
        
        $mainSubquery = "SELECT " . implode(', ', $mainSelectColumns) . "\n"
            . $mainFrom . "\n"
            . (!empty($mainJoins) ? implode("\n", $mainJoins) . "\n" : '')
            . $whereClause . "\n"
            . $mainOrderByClause
            . (!empty($offsetFetch) ? $offsetFetch . "\n" : '');
        
        log_message('debug', 'buildEfCoreStyleQuery: Main subquery built, length: ' . strlen($mainSubquery) . ' characters');
        
        // Build collection navigation subqueries
        // IMPORTANT: Collection index must match EF Core's indexing (s0, s2, s3, etc.)
        // Nested subqueries (s1) are inside collection subqueries (s2)
        $collectionSubqueries = [];
        $collectionIndex = 0;
        $nestedSubqueryIndex = 1; // Start at 1 for nested subqueries (s1)
        foreach ($this->includes as $include) {
            $navPath = $include['path'] ?? $include['navigation'] ?? null;
            if ($navPath === null) {
                continue;
            }
            $navInfo = $this->getNavigationInfo($navPath);
            log_message('debug', "buildEfCoreStyleQuery: Processing include '{$navPath}' - navInfo: " . ($navInfo ? json_encode($navInfo) : 'null'));
            if ($navInfo && $navInfo['isCollection']) {
                // Check for thenIncludes
                $thenIncludes = $include['thenIncludes'] ?? [];
                log_message('debug', "buildEfCoreStyleQuery: Building collection subquery for '{$navPath}' (index: {$collectionIndex})");
                $subquery = $this->buildCollectionSubquery($navPath, $navInfo, $collectionIndex, $thenIncludes, $nestedSubqueryIndex);
                if ($subquery) {
                    $collectionSubqueries[] = $subquery;
                    log_message('debug', "buildEfCoreStyleQuery: Added collection subquery for '{$navPath}'");
                    // Update nested subquery index if nested subqueries were added
                    if (isset($subquery['nestedSubqueryIndex'])) {
                        $nestedSubqueryIndex = $subquery['nestedSubqueryIndex'];
                    }
                    $collectionIndex++;
                } else {
                    log_message('warning', "buildEfCoreStyleQuery: buildCollectionSubquery returned null for '{$navPath}'");
                }
            } else {
                if (!$navInfo) {
                    log_message('warning', "buildEfCoreStyleQuery: getNavigationInfo returned null for include '{$navPath}'");
                } elseif (!$navInfo['isCollection']) {
                    log_message('debug', "buildEfCoreStyleQuery: Skipping collection subquery for reference navigation '{$navPath}' (will be loaded via JOIN)");
                    
                    // Add thenInclude collection subqueries for this reference navigation
                    if (isset($referenceNavThenIncludeSubqueries[$navPath])) {
                        foreach ($referenceNavThenIncludeSubqueries[$navPath] as $thenIncludeInfo) {
                            // Build subquery with correct collection index
                            $thenIncludeSubquery = $this->buildCollectionSubquery(
                                $thenIncludeInfo['navigation'],
                                $thenIncludeInfo['navInfo'],
                                $collectionIndex,
                                [],
                                $thenIncludeInfo['nestedSubqueryIndex']
                            );
                            if ($thenIncludeSubquery) {
                                $collectionSubqueries[] = $thenIncludeSubquery;
                                log_message('debug', "buildEfCoreStyleQuery: Added thenInclude collection subquery for '{$navPath}': {$thenIncludeInfo['navigation']} (index: {$collectionIndex})");
                                $collectionIndex++;
                            }
                        }
                    }
                }
            }
        }
        
        // Also add reference navigations from includes (for non-collection navigations not in WHERE)
        foreach ($this->includes as $include) {
            $navPath = $include['path'] ?? $include['navigation'] ?? null;
            if ($navPath === null) {
                continue;
            }
            if (!in_array($navPath, $allNavigationPaths)) {
                $navInfo = $this->getNavigationInfo($navPath);
                if ($navInfo && !$navInfo['isCollection']) {
                    $allNavigationPaths[] = $navPath;
                    $refAlias = $this->getTableAlias($navPath, $referenceNavIndex);
                    $referenceNavAliases[$navPath] = $refAlias;
                    $refEntityReflection = new ReflectionClass($navInfo['entityType']);
                    $refColumns = $this->getEntityColumns($refEntityReflection);
                    
                    // First column gets alias Id0, Id1, etc.
                    // Other columns need to be aliased to avoid conflicts
                    $refPrimaryKeyColumn = $this->getPrimaryKeyColumnName($refEntityReflection);
                    $firstCol = true;
                    foreach ($refColumns as $col) {
                        if ($firstCol && $col === $refPrimaryKeyColumn) {
                            $mainSelectColumns[] = "[{$refAlias}].[{$col}] AS [Id{$referenceNavIndex}]";
                            $firstCol = false;
                        } else {
                            // Alias all columns to avoid conflicts with same column names from different navigations
                            $mainSelectColumns[] = "[{$refAlias}].[{$col}] AS [{$col}{$referenceNavIndex}]";
                        }
                    }
                    $referenceNavIndex++;
                }
            }
        }
        
        // Re-add JOINs for includes (if not already added)
        foreach ($this->includes as $include) {
            $navPath = $include['path'] ?? $include['navigation'] ?? null;
            if ($navPath === null) {
                continue;
            }
            if (!in_array($navPath, $allNavigationPaths)) {
                $navInfo = $this->getNavigationInfo($navPath);
                if ($navInfo && !$navInfo['isCollection']) {
                    $refAlias = $referenceNavAliases[$navPath];
                    $refTableName = $this->context->getTableName($navInfo['entityType']);
                    $joinCondition = $this->buildJoinCondition($mainAlias, $refAlias, $navPath, $navInfo);
                    // Check if this path is used in WHERE clause (should be INNER JOIN) or is a thenInclude
                    $isInWhere = in_array($navPath, $navigationPathsInWhere);
                    $isThenInclude = in_array($navPath, $thenIncludePaths);
                    $joinType = $this->getJoinType($navPath, $navInfo, $isInWhere, $isThenInclude);
                    $mainJoins[] = "{$joinType} [{$refTableName}] AS [{$refAlias}] ON {$joinCondition}";
                }
            }
        }
        
        // Build final SELECT with all columns
        // IMPORTANT: Use aliases to avoid column name conflicts in CodeIgniter result array
        $finalSelectColumns = [];
        // Main entity columns - prefix with 's_' to avoid conflicts
        foreach ($entityColumns as $col) {
            $finalSelectColumns[] = "[{$subqueryAlias}].[{$col}] AS [s_{$col}]";
        }
        // Reference navigation columns
        // Include columns for both top-level and nested reference navigations
        $refIndex = 0;
        foreach ($referenceNavAliases as $navPath => $refAlias) {
            // Check if this is a nested navigation (contains '.')
            $isNested = strpos($navPath, '.') !== false;
            
            // For top-level navigations, only include if added to main subquery
            // For nested navigations, they're always in main subquery (added during thenInclude processing)
            if (!$isNested && !in_array($navPath, $allNavigationPaths)) {
                continue; // Skip if not in main subquery
            }
            
            // Get navigation info - for nested navigations, use getNavigationInfoForEntity
            if ($isNested) {
                $parts = explode('.', $navPath, 2);
                $parentNavPath = $parts[0];
                $thenInclude = $parts[1];
                $parentNavInfo = $this->getNavigationInfo($parentNavPath);
                if (!$parentNavInfo) {
                    continue;
                }
                $navInfo = $this->getNavigationInfoForEntity($thenInclude, $parentNavInfo['entityType']);
            } else {
                $navInfo = $this->getNavigationInfo($navPath);
            }
            
            if (!$navInfo) {
                continue;
            }
            $refEntityReflection = new ReflectionClass($navInfo['entityType']);
            $refColumns = $this->getEntityColumns($refEntityReflection);
            $refPrimaryKeyColumn = $this->getPrimaryKeyColumnName($refEntityReflection);
            
            // For nested navigations, get index from referenceNavIndexes (stored during main subquery building)
            if ($isNested) {
                $refIndex = isset($this->referenceNavIndexes[$navPath]) ? $this->referenceNavIndexes[$navPath] : 0;
            }
            
            $firstCol = true;
            foreach ($refColumns as $col) {
                if ($firstCol && $col === $refPrimaryKeyColumn) {
                    // Primary key column is aliased as Id{index} in subquery
                    $finalSelectColumns[] = "[{$subqueryAlias}].[Id{$refIndex}] AS [s_Id{$refIndex}]";
                    $firstCol = false;
                } else {
                    // Other columns are aliased as {col}{refIndex} in subquery (e.g., ProjectID0, ProjectID1)
                    $colAlias = "{$col}{$refIndex}";
                    $finalSelectColumns[] = "[{$subqueryAlias}].[{$colAlias}] AS [s_{$colAlias}]";
                }
            }
            
            // Only increment refIndex for top-level navigations
            if (!$isNested) {
                $refIndex++;
            }
        }
        // Collection navigation columns (from subqueries) - prefix with subquery alias
        // Use selectColumns from subquery if available (includes nested subquery columns)
        foreach ($collectionSubqueries as $idx => $subquery) {
            $collectionSubqueryAlias = 's' . $idx; // Use different variable name to avoid conflicts
            $navPath = $subquery['navigation'];
            
            // If subquery has selectColumns (includes nested subquery columns), use them
            if (isset($subquery['selectColumns'])) {
                // Collection subquery SELECT contains expressions like:
                // "[u2].[Id]", "[u1].[Id] AS [Id0]", "[s1].[Id] AS [Id1]", "[s1].[AuthorizationId] AS [AuthorizationId0]", etc.
                // For final SELECT, we reference these by their alias (if exists) or column name from collection subquery
                // EF Core format: [s1].[Id], [s1].[UserId], [s1].[AuthorizationId], [s1].[Id0], [s1].[Name], [s1].[Description], [s1].[Id1], [s1].[AuthorizationId0], [s1].[OperationClaimId], [s1].[Id00], [s1].[Description0], [s1].[Name0]
                $seenAliases = []; // Track aliases to avoid duplicates
                foreach ($subquery['selectColumns'] as $colExpr) {
                    if (preg_match('/\[([^\]]+)\]\.\[([^\]]+)\](?:\s+AS\s+\[([^\]]+)\])?/', $colExpr, $matches)) {
                        $tableAlias = $matches[1]; // u2, u1, s1, etc.
                        $columnName = $matches[2]; // Id, Name, Description, etc.
                        $alias = $matches[3] ?? null; // Id0, Id1, AuthorizationId0, etc. (if exists)
                        
                        // For final SELECT, use the alias if it exists, otherwise use column name
                        // Reference from collection subquery: [s1].[alias] or [s1].[columnName]
                        $finalAlias = $alias ?? $columnName;
                        
                        // Skip if we've already added this alias (avoid duplicates)
                        $finalAliasKey = "{$collectionSubqueryAlias}_{$finalAlias}";
                        if (isset($seenAliases[$finalAliasKey])) {
                            continue;
                        }
                        $seenAliases[$finalAliasKey] = true;
                        
                        if ($alias !== null) {
                            // Column has alias in collection subquery SELECT, reference by alias
                            $finalSelectColumns[] = "[{$collectionSubqueryAlias}].[{$alias}] AS [{$collectionSubqueryAlias}_{$alias}]";
                        } else {
                            // Column has no alias, reference by column name
                            $finalSelectColumns[] = "[{$collectionSubqueryAlias}].[{$columnName}] AS [{$collectionSubqueryAlias}_{$columnName}]";
                        }
                    }
                }
            } else {
                // Fallback: use entityType from subquery (which is the actual related entity, not join entity)
                $joinEntityType = $subquery['joinEntityType'];
                $relatedEntityType = $subquery['entityType'];
                $joinEntityReflection = new ReflectionClass($joinEntityType);
                $joinColumns = $this->getEntityColumns($joinEntityReflection);
                $relatedEntityReflection = new ReflectionClass($relatedEntityType);
                $relatedColumns = $this->getEntityColumns($relatedEntityReflection);
                
                // Join entity columns - prefix with collection subquery alias
                foreach ($joinColumns as $col) {
                    $finalSelectColumns[] = "[{$collectionSubqueryAlias}].[{$col}] AS [{$collectionSubqueryAlias}_{$col}]";
                }
                // Related entity columns - prefix with collection subquery alias
                $relatedIdx = 0;
                $firstCol = true;
                foreach ($relatedColumns as $col) {
                    if ($firstCol && $col === 'Id') {
                        $finalSelectColumns[] = "[{$collectionSubqueryAlias}].[Id{$relatedIdx}] AS [{$collectionSubqueryAlias}_Id{$relatedIdx}]";
                        $firstCol = false;
                    } else {
                        $finalSelectColumns[] = "[{$collectionSubqueryAlias}].[{$col}] AS [{$collectionSubqueryAlias}_{$col}]";
                    }
                }
            }
        }
        
        // Build final query with LEFT JOINs for collections
        log_message('debug', 'buildEfCoreStyleQuery: Building final query with ' . count($finalSelectColumns) . ' final select columns');
        log_message('debug', 'buildEfCoreStyleQuery: Collection subqueries: ' . count($collectionSubqueries));
        
        $finalQuery = "SELECT " . implode(', ', $finalSelectColumns) . "\n"
            . "FROM (\n"
            . "    " . str_replace("\n", "\n    ", $mainSubquery) . "\n"
            . ") AS [{$subqueryAlias}]";
        
        log_message('debug', 'buildEfCoreStyleQuery: Final query base built');
        
        // Add LEFT JOINs for collection subqueries
        foreach ($collectionSubqueries as $idx => $subquery) {
            $collectionSubqueryAlias = 's' . $idx; // Collection subquery alias (s0, s1, s2, etc.)
            $navPath = $subquery['navigation'];
            
            // Check if this is a thenInclude collection subquery for a reference navigation
            // Pattern: "ParentNav.ThenIncludeNav" (e.g., "Employee.CustomFields")
            $isReferenceNavThenInclude = false;
            $parentNavPath = null;
            if (strpos($navPath, '.') !== false) {
                $parts = explode('.', $navPath, 2);
                $parentNavPath = $parts[0];
                $isReferenceNavThenInclude = isset($referenceNavAliases[$parentNavPath]);
            }
            
            // Get foreign key column name for join condition
            $mainEntityReflection = new ReflectionClass($this->entityType);
            $mainPrimaryKeyColumn = $this->getPrimaryKeyColumnName($mainEntityReflection);
            
            $joinEntityType = $subquery['joinEntityType'];
            if ($joinEntityType) {
                // Has join entity - FK is in join entity (e.g., UserDepartment.UserId)
                $entityReflection = new ReflectionClass($this->entityType);
                $entityShortName = $entityReflection->getShortName();
                $expectedFkName = $entityShortName . 'Id'; // e.g., UserId
                
                // Get join entity reflection to find the actual FK column name
                $joinEntityReflection = new ReflectionClass($joinEntityType);
                
                // Find FK property in join entity
                $fkColumn = null;
                if ($joinEntityReflection->hasProperty($expectedFkName)) {
                    $fkColumn = $this->getColumnNameFromProperty($joinEntityReflection, $expectedFkName);
                } else {
                    // Try to find by ForeignKey attribute
                    foreach ($joinEntityReflection->getProperties() as $property) {
                        $attributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\ForeignKey::class);
                        if (!empty($attributes)) {
                            $fkAttr = $attributes[0]->newInstance();
                            if ($fkAttr->navigationProperty === $navPath || 
                                strpos($property->getName(), $entityShortName) !== false) {
                                $fkColumn = $this->getColumnNameFromProperty($joinEntityReflection, $property->getName());
                                break;
                            }
                        }
                    }
                }
                
                if ($fkColumn === null) {
                    $fkColumn = $expectedFkName; // Fallback
                }
                
                // Join condition: main subquery primary key = collection subquery FK (in join entity)
                log_message('debug', "buildEfCoreStyleQuery: Using primary key column '{$mainPrimaryKeyColumn}' for join condition with collection subquery (join entity FK: {$fkColumn})");
                $joinCondition = "[{$subqueryAlias}].[{$mainPrimaryKeyColumn}] = [{$collectionSubqueryAlias}].[{$fkColumn}]";
            } else {
                // No join entity - FK is directly in related entity (e.g., EmployeeDepartment.EmployeeId)
                $fkPropertyName = isset($subquery['foreignKey']) ? $subquery['foreignKey'] : null;
                if ($fkPropertyName === null) {
                    // Fallback: use convention
                    $entityShortName = $mainEntityReflection->getShortName();
                    $fkPropertyName = $entityShortName . 'Id';
                }
                
                $relatedEntityType = $subquery['entityType'];
                $relatedEntityReflection = new ReflectionClass($relatedEntityType);
                
                // Get column name from property (handles Column attribute)
                // This should match the column name used in the subquery SELECT
                if ($relatedEntityReflection->hasProperty($fkPropertyName)) {
                    $fkColumn = $this->getColumnNameFromProperty($relatedEntityReflection, $fkPropertyName);
                } else {
                    // Fallback: use property name as column name
                    $fkColumn = $fkPropertyName;
                }
                
                // Verify the column name exists in subquery SELECT by checking selectColumns
                // Subquery SELECT uses getEntityColumnsWithProperties which uses getColumnNameFromProperty
                // So we need to ensure we're using the same column name
                // IMPORTANT: If the FK column is the primary key and is aliased (e.g., Id0), use the alias
                $relatedPrimaryKeyColumn = $this->getPrimaryKeyColumnName($relatedEntityReflection);
                $fkColumnAlias = null;
                if (isset($subquery['selectColumns'])) {
                    // Try to find the FK column in subquery SELECT
                    // Pattern: [alias].[ColumnName] or [alias].[ColumnName] AS [Alias]
                    $foundFkColumn = null;
                    foreach ($subquery['selectColumns'] as $colExpr) {
                        // Match: [u1].[EmployeeID] or [u1].[EmployeeID] AS [Id0]
                        if (preg_match('/\[[^\]]+\]\.\[([^\]]+)\](?:\s+AS\s+\[([^\]]+)\])?/', $colExpr, $matches)) {
                            $selectColName = $matches[1];
                            $selectAlias = isset($matches[2]) ? $matches[2] : null;
                            
                            // Check if this matches the FK column (case-insensitive for SQL Server)
                            if (strcasecmp($selectColName, $fkColumn) === 0) {
                                // If this column is the primary key and has an alias, use the alias
                                if ($selectColName === $relatedPrimaryKeyColumn && $selectAlias !== null) {
                                    $fkColumnAlias = $selectAlias; // Use alias (e.g., Id0)
                                } else {
                                    $foundFkColumn = $selectColName; // Use exact case from SELECT
                                }
                                break;
                            }
                        }
                    }
                    if ($foundFkColumn !== null && $fkColumnAlias === null) {
                        $fkColumn = $foundFkColumn;
                    }
                }
                
                // Use alias if available, otherwise use column name
                $fkColumnForJoin = $fkColumnAlias !== null ? $fkColumnAlias : $fkColumn;
                
                // Join condition: depends on whether this is a thenInclude for a reference navigation
                if ($isReferenceNavThenInclude && $parentNavPath !== null) {
                    // This is a thenInclude collection subquery for a reference navigation
                    // Join on reference navigation's primary key (e.g., Employee.EmployeeID)
                    $parentNavInfo = $this->getNavigationInfo($parentNavPath);
                    if ($parentNavInfo) {
                        $parentEntityReflection = new ReflectionClass($parentNavInfo['entityType']);
                        $parentPrimaryKeyColumn = $this->getPrimaryKeyColumnName($parentEntityReflection);
                        $parentRefIndex = $this->referenceNavIndexes[$parentNavPath] ?? 0;
                        // Reference navigation primary key is aliased as Id0, Id1, etc. in main subquery
                        log_message('debug', "buildEfCoreStyleQuery: Using reference navigation primary key 'Id{$parentRefIndex}' for join condition with thenInclude collection subquery (FK property: '{$fkPropertyName}', column: '{$fkColumn}', alias: " . ($fkColumnAlias !== null ? "'{$fkColumnAlias}'" : "none") . ")");
                        $joinCondition = "[{$subqueryAlias}].[Id{$parentRefIndex}] = [{$collectionSubqueryAlias}].[{$fkColumnForJoin}]";
                    } else {
                        // Fallback to main entity primary key
                        log_message('debug', "buildEfCoreStyleQuery: Using primary key column '{$mainPrimaryKeyColumn}' for join condition with collection subquery (related entity FK property: '{$fkPropertyName}', column: '{$fkColumn}', alias: " . ($fkColumnAlias !== null ? "'{$fkColumnAlias}'" : "none") . ")");
                        $joinCondition = "[{$subqueryAlias}].[{$mainPrimaryKeyColumn}] = [{$collectionSubqueryAlias}].[{$fkColumnForJoin}]";
                    }
                } else {
                    // Regular collection subquery - join on main entity primary key
                    log_message('debug', "buildEfCoreStyleQuery: Using primary key column '{$mainPrimaryKeyColumn}' for join condition with collection subquery (related entity FK property: '{$fkPropertyName}', column: '{$fkColumn}', alias: " . ($fkColumnAlias !== null ? "'{$fkColumnAlias}'" : "none") . ")");
                    $joinCondition = "[{$subqueryAlias}].[{$mainPrimaryKeyColumn}] = [{$collectionSubqueryAlias}].[{$fkColumnForJoin}]";
                }
            }
            
            $finalQuery .= "\nLEFT JOIN (\n"
                . "    " . str_replace("\n", "\n    ", $subquery['sql']) . "\n"
                . ") AS [{$collectionSubqueryAlias}] ON {$joinCondition}";
        }
        
        // Add ORDER BY
        // Use user-defined ORDER BY if available, otherwise use default
        $orderByColumns = [];
        
        if (!empty($this->orderBys)) {
            // Use user-defined ORDER BY
            foreach ($this->orderBys as $orderBy) {
                // Check if this is a collection navigation property ORDER BY
                $reflection = new \ReflectionFunction($orderBy['selector']);
                $staticVariables = $reflection->getStaticVariables();
                $isCollectionNav = false;
                $collectionSubqueryAlias = null;
                $collectionColumnName = null;
                
                // Check for navigation property path in static variables (e.g., "Department.DepartmentName")
                if (isset($staticVariables['field']) && is_string($staticVariables['field']) && strpos($staticVariables['field'], '.') !== false) {
                    $parts = explode('.', $staticVariables['field'], 2);
                    $firstNavProp = $parts[0];
                    $nestedProp = $parts[1];
                    
                    // Check if firstNavProp is accessed via a collection navigation property
                    // e.g., "Department.DepartmentName" where "Department" is in "EmployeeDepartments" collection
                    // We need to find which collection subquery contains this navigation
                    foreach ($collectionSubqueries as $idx => $subquery) {
                        $collectionNavPath = $subquery['navigation'];
                        $collectionNavInfo = $this->getNavigationInfo($collectionNavPath);
                        
                        if ($collectionNavInfo && $collectionNavInfo['isCollection']) {
                            // Check if firstNavProp is a navigation property in the collection entity
                            $thenNavInfo = $this->getNavigationInfoForEntity($firstNavProp, $collectionNavInfo['entityType']);
                            if ($thenNavInfo && !$thenNavInfo['isCollection']) {
                                // Found it! firstNavProp is a reference navigation in the collection entity
                                // Now get the nested property column name
                                $thenEntityReflection = new \ReflectionClass($thenNavInfo['entityType']);
                                $collectionColumnName = $this->getColumnNameFromProperty($thenEntityReflection, $nestedProp);
                                $collectionSubqueryAlias = 's' . $idx;
                                $isCollectionNav = true;
                                log_message('debug', "buildEfCoreStyleQuery: Found collection navigation ORDER BY: {$firstNavProp}.{$nestedProp} via collection '{$collectionNavPath}' -> collection subquery alias: {$collectionSubqueryAlias}, column: {$collectionColumnName}");
                                break;
                            }
                        }
                    }
                }
                
                if ($isCollectionNav && $collectionSubqueryAlias && $collectionColumnName) {
                    // Use collection subquery alias and column
                    $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
                    $quotedAlias = $provider->escapeIdentifier($collectionSubqueryAlias);
                    $quotedColumn = $provider->escapeIdentifier($collectionColumnName);
                    $direction = strtoupper($orderBy['direction']);
                    if ($direction !== 'ASC' && $direction !== 'DESC') {
                        $direction = 'ASC';
                    }
                    $orderBySql = "{$quotedAlias}.{$quotedColumn} {$direction}";
                    log_message('debug', "buildEfCoreStyleQuery: Generated ORDER BY SQL for collection navigation: {$orderBySql}");
                    $orderByColumns[] = $orderBySql;
                } else {
                    // Regular ORDER BY - use convertOrderByToSql
                    $orderBySql = $this->convertOrderByToSql($orderBy['selector'], $orderBy['direction'], $subqueryAlias);
                    if ($orderBySql) {
                        $orderByColumns[] = $orderBySql;
                    }
                }
            }
        }
        
        // If no user-defined ORDER BY, use default (primary key + navigation properties)
        if (empty($orderByColumns)) {
            // Get primary key column name for main entity
            $mainEntityReflection = new ReflectionClass($this->entityType);
            $mainPrimaryKeyColumn = $this->getPrimaryKeyColumnName($mainEntityReflection);
            log_message('debug', "buildEfCoreStyleQuery: Using primary key column '{$mainPrimaryKeyColumn}' for ORDER BY");
            $orderByColumns[] = "[{$subqueryAlias}].[{$mainPrimaryKeyColumn}]";
            
            foreach ($referenceNavAliases as $navPath => $refAlias) {
                // Only add if this navigation was included in main subquery
                // Check if it's in allNavigationPaths (which means it was added to main subquery)
                if (in_array($navPath, $allNavigationPaths) && isset($referenceNavIndexes[$navPath])) {
                    $navInfo = $this->getNavigationInfo($navPath);
                    if ($navInfo) {
                        $refEntityReflection = new ReflectionClass($navInfo['entityType']);
                        $refPrimaryKeyColumn = $this->getPrimaryKeyColumnName($refEntityReflection);
                        $refIndex = $referenceNavIndexes[$navPath]; // Use stored index
                        log_message('debug', "buildEfCoreStyleQuery: Adding reference navigation ORDER BY for '{$navPath}' with primary key '{$refPrimaryKeyColumn}' (index: {$refIndex})");
                        // Reference navigation Id columns are aliased as Id0, Id1, etc. in the main subquery
                        $orderByColumns[] = "[{$subqueryAlias}].[Id{$refIndex}]";
                    }
                }
            }
            foreach ($collectionSubqueries as $idx => $subquery) {
                $collectionSubqueryAlias = 's' . $idx;
                // Get primary key for the related entity in collection subquery
                // In collection subquery, primary key may be aliased as Id0 (if it's 'Id') or use actual column name
                if (isset($subquery['entityType'])) {
                    $relatedEntityReflection = new ReflectionClass($subquery['entityType']);
                    $relatedPrimaryKeyColumn = $this->getPrimaryKeyColumnName($relatedEntityReflection);
                    // Check if primary key is aliased in subquery SELECT
                    $primaryKeyAlias = null;
                    if (isset($subquery['selectColumns'])) {
                        // Try to find the primary key alias in selectColumns
                        // Pattern: [alias].[column] AS [Id0] or [alias].[Id] AS [Id0]
                        foreach ($subquery['selectColumns'] as $colExpr) {
                            if (preg_match('/\.\[?' . preg_quote($relatedPrimaryKeyColumn, '/') . '\]?\s+AS\s+\[?Id0\]?/i', $colExpr)) {
                                $primaryKeyAlias = "Id0";
                                break;
                            }
                        }
                    }
                    // If not aliased, use actual column name
                    if ($primaryKeyAlias === null) {
                        $primaryKeyAlias = $relatedPrimaryKeyColumn;
                    }
                    log_message('debug', "buildEfCoreStyleQuery: Adding collection navigation ORDER BY for subquery '{$collectionSubqueryAlias}' with primary key '{$primaryKeyAlias}' (actual column: '{$relatedPrimaryKeyColumn}')");
                    $orderByColumns[] = "[{$collectionSubqueryAlias}].[{$primaryKeyAlias}]";
                } else {
                    // Fallback to Id0 if entityType not available
                    log_message('warning', "buildEfCoreStyleQuery: Collection subquery '{$collectionSubqueryAlias}' has no entityType, using default 'Id0'");
                    $orderByColumns[] = "[{$collectionSubqueryAlias}].[Id0]";
                }
            }
        }
        
        if (!empty($orderByColumns)) {
            $finalQuery .= "\nORDER BY " . implode(', ', $orderByColumns);
        }
        
        // Log the generated SQL for debugging
        log_message('debug', 'Generated EF Core Style SQL: ' . $finalQuery);
        log_message('debug', 'buildEfCoreStyleQuery: ORDER BY columns: ' . implode(', ', $orderByColumns));
        
        return $finalQuery;
    }

    /**
     * Get entity columns (excluding navigation properties and internal tracking properties)
     */
    private function getEntityColumns(ReflectionClass $entityReflection): array
    {
        // Internal tracking properties that should be excluded
        $excludedProperties = [
            'entityState',
            'originalValues',
            'currentValues',
            'navigationProperties',
            'isTracking'
        ];
        
        $columns = [];
        foreach ($entityReflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            
            $propertyName = $property->getName();
            
            // Skip internal tracking properties
            if (in_array($propertyName, $excludedProperties)) {
                continue;
            }
            
            // Check for NotMapped attribute - exclude these properties
            $notMappedAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\NotMapped::class);
            if (!empty($notMappedAttributes)) {
                continue;
            }
            
            // Check for InverseProperty attribute - these are navigation properties
            $inversePropertyAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\InverseProperty::class);
            if (!empty($inversePropertyAttributes)) {
                continue;
            }
            
            // Check if property type hint is an Entity class
            if ($property->hasType()) {
                $type = $property->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $typeName = $type->getName();
                    // Resolve if it's a short name
                    if (!str_starts_with($typeName, '\\')) {
                        $resolvedType = $this->resolveEntityType($typeName, $entityReflection);
                        if ($resolvedType !== null) {
                            $typeName = $resolvedType;
                        }
                    }
                    // Check if it extends Entity
                    if (class_exists($typeName)) {
                        $typeReflection = new ReflectionClass($typeName);
                        if ($typeReflection->isSubclassOf(\Yakupeyisan\CodeIgniter4\EntityFramework\Core\Entity::class)) {
                            continue; // This is a navigation property
                        }
                    }
                }
            }
            
            // Check @var doccomment for entity types
            $docComment = $property->getDocComment();
            if ($docComment) {
                // Match: @var EntityType or @var EntityType[]
                if (preg_match('/@var\s+([A-Za-z_][A-Za-z0-9_\\\\]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)(\[\])?/', $docComment, $matches)) {
                    $varType = $matches[1];
                    // Resolve if it's a short name
                    if (!str_starts_with($varType, '\\')) {
                        $resolvedType = $this->resolveEntityType($varType, $entityReflection);
                        if ($resolvedType !== null) {
                            $varType = $resolvedType;
                        }
                    }
                    // Check if it's an Entity class
                    if (class_exists($varType)) {
                        $varTypeReflection = new ReflectionClass($varType);
                        if ($varTypeReflection->isSubclassOf(\Yakupeyisan\CodeIgniter4\EntityFramework\Core\Entity::class)) {
                            continue; // This is a navigation property
                        }
                    }
                }
                // Also check for array type (could be collection navigation)
                if (preg_match('/@var\s+array/', $docComment)) {
                    // If it's an array and has InverseProperty, it's a navigation property
                    if (!empty($inversePropertyAttributes)) {
                        continue;
                    }
                }
            }
            
            // Skip protected/private properties that are not database columns
            // (unless they have Column attribute)
            if ($property->isProtected() || $property->isPrivate()) {
                $attributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Column::class);
                if (empty($attributes)) {
                    continue;
                }
            }
            
            // Only include properties with Column attribute or public properties
            $hasColumnAttr = !empty($property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Column::class));
            if ($hasColumnAttr || $property->isPublic()) {
                $columnName = $this->getColumnNameFromProperty($entityReflection, $propertyName);
                $columns[] = $columnName;
            }
        }
        return $columns;
    }

    /**
     * Get column SELECT expression with sensitive value masking if needed
     * 
     * @param ReflectionClass $entityReflection Entity reflection
     * @param string $propertyName Property name
     * @param string $tableAlias Table alias for SQL
     * @return string SQL SELECT expression
     */
    private function getColumnSelectExpression(ReflectionClass $entityReflection, string $propertyName, string $tableAlias = 'main'): string
    {
        if (!$entityReflection->hasProperty($propertyName)) {
            return '';
        }

        $property = $entityReflection->getProperty($propertyName);
        $columnName = $this->getColumnNameFromProperty($entityReflection, $propertyName);
        $quotedColumn = $this->connection->escapeIdentifiers($columnName);
        $quotedAlias = $this->connection->escapeIdentifiers($tableAlias);
        
        // Check if disableSensitive is enabled
        if ($this->isSensitive) {
            return "[{$quotedAlias}].{$quotedColumn}";
        }

        // Check for SensitiveValue attribute
        $sensitiveAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\SensitiveValue::class);
        if (empty($sensitiveAttributes)) {
            return "[{$quotedAlias}].{$quotedColumn}";
        }

        // Get masking configuration
        $sensitiveAttr = $sensitiveAttributes[0]->newInstance();
        $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
        
        // Build masked column expression
        $maskedExpression = $provider->getMaskingSql(
            "[{$quotedAlias}].{$quotedColumn}",
            $sensitiveAttr->maskChar,
            $sensitiveAttr->visibleStart,
            $sensitiveAttr->visibleEnd,
            $sensitiveAttr->customMask
        );
        
        return "({$maskedExpression}) AS {$quotedColumn}";
    }

    /**
     * Get entity columns with property names mapping
     * Returns array of ['column' => 'ColumnName', 'property' => 'PropertyName']
     */
    private function getEntityColumnsWithProperties(ReflectionClass $entityReflection): array
    {
        $excludedProperties = [
            'entityState',
            'originalValues',
            'currentValues',
            'navigationProperties',
            'isTracking'
        ];
        
        $columns = [];
        foreach ($entityReflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            
            $propertyName = $property->getName();
            
            if (in_array($propertyName, $excludedProperties)) {
                continue;
            }
            
            // Check for NotMapped attribute - exclude these properties
            $notMappedAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\NotMapped::class);
            if (!empty($notMappedAttributes)) {
                continue;
            }
            
            // Check for InverseProperty attribute - these are navigation properties
            $inversePropertyAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\InverseProperty::class);
            if (!empty($inversePropertyAttributes)) {
                continue;
            }
            
            // Check if property type hint is an Entity class
            if ($property->hasType()) {
                $type = $property->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $typeName = $type->getName();
                    // Resolve if it's a short name
                    if (!str_starts_with($typeName, '\\')) {
                        $resolvedType = $this->resolveEntityType($typeName, $entityReflection);
                        if ($resolvedType !== null) {
                            $typeName = $resolvedType;
                        }
                    }
                    // Check if it extends Entity
                    if (class_exists($typeName)) {
                        $typeReflection = new ReflectionClass($typeName);
                        if ($typeReflection->isSubclassOf(\Yakupeyisan\CodeIgniter4\EntityFramework\Core\Entity::class)) {
                            continue; // This is a navigation property
                        }
                    }
                }
            }
            
            // Check @var doccomment for entity types
            $docComment = $property->getDocComment();
            if ($docComment) {
                // Match: @var EntityType or @var EntityType[]
                if (preg_match('/@var\s+([A-Za-z_][A-Za-z0-9_\\\\]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)(\[\])?/', $docComment, $matches)) {
                    $varType = $matches[1];
                    // Resolve if it's a short name
                    if (!str_starts_with($varType, '\\')) {
                        $resolvedType = $this->resolveEntityType($varType, $entityReflection);
                        if ($resolvedType !== null) {
                            $varType = $resolvedType;
                        }
                    }
                    // Check if it's an Entity class
                    if (class_exists($varType)) {
                        $varTypeReflection = new ReflectionClass($varType);
                        if ($varTypeReflection->isSubclassOf(\Yakupeyisan\CodeIgniter4\EntityFramework\Core\Entity::class)) {
                            continue; // This is a navigation property
                        }
                    }
                }
                // Also check for array type (could be collection navigation)
                if (preg_match('/@var\s+array/', $docComment)) {
                    // If it's an array and has InverseProperty, it's a navigation property
                    if (!empty($inversePropertyAttributes)) {
                        continue;
                    }
                }
            }
            
            if ($property->isProtected() || $property->isPrivate()) {
                $attributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Column::class);
                if (empty($attributes)) {
                    continue;
                }
            }
            
            $hasColumnAttr = !empty($property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Column::class));
            if ($hasColumnAttr || $property->isPublic()) {
                $columnName = $this->getColumnNameFromProperty($entityReflection, $propertyName);
                $columns[] = [
                    'column' => $columnName,
                    'property' => $propertyName
                ];
            }
        }
        return $columns;
    }

    /**
     * Resolve entity type from short name by parsing use statements
     * 
     * @param string $shortName The short class name (e.g., "Employee")
     * @param ReflectionClass $entityReflection Reflection of the entity class containing the navigation property
     * @return string|null Fully qualified class name or null if not found
     */
    private function resolveEntityType(string $shortName, ReflectionClass $entityReflection): ?string
    {
        // Check if already fully qualified
        if (str_starts_with($shortName, '\\')) {
            return $shortName;
        }
        
        // Try current namespace first
        $currentNamespace = $entityReflection->getNamespaceName();
        $fullyQualified = $currentNamespace . '\\' . $shortName;
        if (class_exists($fullyQualified)) {
            return $fullyQualified;
        }
        
        // Parse use statements from the file
        $fileName = $entityReflection->getFileName();
        if ($fileName && file_exists($fileName)) {
            $content = file_get_contents($fileName);
            
            // Match: use App\Entities\General\Employee;
            // This pattern matches: use [fully qualified path ending with shortName];
            if (preg_match('/use\s+([A-Za-z0-9_\\\\]+\\\\' . preg_quote($shortName, '/') . ')\s*;/m', $content, $matches)) {
                if (class_exists($matches[1])) {
                    return $matches[1];
                }
            }
            
            // Match: use App\Entities\General\Employee as EmployeeAlias;
            // This pattern matches: use [fully qualified path] as [shortName];
            if (preg_match('/use\s+([A-Za-z0-9_\\\\]+)\s+as\s+' . preg_quote($shortName, '/') . '\s*;/m', $content, $matches)) {
                if (class_exists($matches[1])) {
                    return $matches[1];
                }
            }
        }
        
        // Fallback to common namespaces
        $commonNamespaces = ['App\\Models', 'App\\EntityFramework\\Core'];
        foreach ($commonNamespaces as $ns) {
            $fullyQualified = $ns . '\\' . $shortName;
            if (class_exists($fullyQualified)) {
                return $fullyQualified;
            }
        }
        
        return null;
    }

    /**
     * Get navigation property info
     */
    private function getNavigationInfo(string $navigationProperty): ?array
    {
        $entityReflection = new ReflectionClass($this->entityType);
        if (!$entityReflection->hasProperty($navigationProperty)) {
            log_message('debug', "getNavigationInfo: Property '{$navigationProperty}' not found in entity {$this->entityType}");
            return null;
        }
        
        $navProperty = $entityReflection->getProperty($navigationProperty);
        $navProperty->setAccessible(true);
        $docComment = $navProperty->getDocComment();
        
        $relatedEntityType = null;
        $isCollection = false;
        
        // Try to get type from @var annotation first
        // Pattern matches both fully qualified (\App\Entities\...) and short names (EntityName)
        if ($docComment && preg_match('/@var\s+(\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)(\[\])?/', $docComment, $matches)) {
            $relatedEntityType = $matches[1];
            $isCollection = !empty($matches[2]);
            
            log_message('debug', "getNavigationInfo: Found @var annotation for '{$navigationProperty}': {$relatedEntityType}" . ($isCollection ? '[]' : ''));
            
            // Resolve namespace using use statements (only if not fully qualified)
            if ($relatedEntityType && !str_starts_with($relatedEntityType, '\\')) {
                $resolved = $this->resolveEntityType($relatedEntityType, $entityReflection);
                if ($resolved !== null) {
                    log_message('debug', "getNavigationInfo: Resolved '{$relatedEntityType}' to '{$resolved}'");
                    $relatedEntityType = $resolved;
                } else {
                    log_message('warning', "getNavigationInfo: Failed to resolve '{$relatedEntityType}' for '{$navigationProperty}'");
                }
            }
        } 
        // If no @var annotation, try to get type from type hint
        elseif ($navProperty->hasType()) {
            $type = $navProperty->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $relatedEntityType = $type->getName();
                // Check if it's a collection by checking if property type is array
                $isCollection = $type->getName() === 'array';
                
                log_message('debug', "getNavigationInfo: Found type hint for '{$navigationProperty}': {$relatedEntityType}" . ($isCollection ? '[]' : ''));
                
                // If not fully qualified, resolve namespace
                if ($relatedEntityType && !str_starts_with($relatedEntityType, '\\')) {
                    $resolved = $this->resolveEntityType($relatedEntityType, $entityReflection);
                    if ($resolved !== null) {
                        log_message('debug', "getNavigationInfo: Resolved type hint '{$relatedEntityType}' to '{$resolved}'");
                        $relatedEntityType = $resolved;
                    }
                }
            }
        }
        
        if ($relatedEntityType === null) {
            log_message('warning', "getNavigationInfo: No @var annotation or type hint found for '{$navigationProperty}' in entity {$this->entityType}");
        }
        
        if ($relatedEntityType === null) {
            log_message('debug', "getNavigationInfo: relatedEntityType is null for '{$navigationProperty}'");
            return null;
        }
        
        if (!class_exists($relatedEntityType)) {
            log_message('error', "getNavigationInfo: Class '{$relatedEntityType}' does not exist for '{$navigationProperty}'");
            return null;
        }
        
        // For collection navigation, foreign key is in the join entity, not in the related entity
        // The foreignKey returned here is the FK in join entity that points to main entity (e.g., UserId in UserDepartment)
        // For the FK in join entity that points to related entity (e.g., DepartmentId in UserDepartment),
        // we'll determine it in buildCollectionSubquery()
        $foreignKey = null;
        if ($isCollection) {
            // For collection navigation, FK in join entity pointing to main entity
            // Convention: MainEntityName + "Id" (e.g., UserId for User entity)
            $mainEntityShortName = $entityReflection->getShortName();
            $foreignKey = $mainEntityShortName . 'Id';
        } else {
            // For reference navigation, use existing logic
            $foreignKey = $this->getForeignKeyForNavigation($entityReflection, $navigationProperty, $isCollection, $this->entityType);
        }
        
        $result = [
            'entityType' => $relatedEntityType,
            'isCollection' => $isCollection,
            'foreignKey' => $foreignKey,
            'joinEntityType' => $isCollection ? $this->getJoinEntityType($navigationProperty) : null
        ];
        
        log_message('debug', "getNavigationInfo: Returning info for '{$navigationProperty}': " . json_encode($result));
        
        return $result;
    }

    /**
     * Get join entity type for collection navigation (e.g., UserDepartment for UserDepartments)
     */
    private function getJoinEntityType(string $navigationProperty): ?string
    {
        // For collection navigations, the join entity is typically the navigation property name
        // e.g., UserDepartments -> UserDepartment
        $entityReflection = new ReflectionClass($this->entityType);
        $navProperty = $entityReflection->getProperty($navigationProperty);
        $docComment = $navProperty->getDocComment();
        
        // Try to extract join entity type from doc comment
        // Pattern: @var UserDepartment[]
        if (preg_match('/@var\s+([A-Za-z_][A-Za-z0-9_\\\\]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)\[\]/', $docComment, $matches)) {
            $joinEntityType = $matches[1];
            
            // Resolve namespace using use statements
            if ($joinEntityType && !str_starts_with($joinEntityType, '\\')) {
                $resolved = $this->resolveEntityType($joinEntityType, $entityReflection);
                if ($resolved !== null) {
                    return $resolved;
                }
            } else {
                return $joinEntityType;
            }
        }
        
        return null;
    }

    /**
     * Get table alias for navigation property
     */
    private function getTableAlias(string $navigationProperty, int $index): string
    {
        // Use first letter of navigation property or index-based alias
        $firstLetter = strtolower(substr($navigationProperty, 0, 1));
        // For nested navigations (index >= 100), always include index to ensure uniqueness
        // For regular navigations, include index if > 0
        if ($index >= 100 || $index > 0) {
            return $firstLetter . $index;
        }
        return $firstLetter;
    }

    /**
     * Build JOIN condition
     */
    private function buildJoinCondition(string $mainAlias, string $refAlias, string $navPath, array $navInfo, ?string $parentEntityType = null): string
    {
        $entityReflection = new ReflectionClass($parentEntityType ?? $this->entityType);
        $foreignKey = $navInfo['foreignKey'];
        $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
        
        // Check if FK is in main entity (many-to-one) or related entity (one-to-one)
        if ($entityReflection->hasProperty($foreignKey)) {
            // Many-to-one: FK in main entity
            $fkColumn = $this->getColumnNameFromProperty($entityReflection, $foreignKey);
            // Get primary key column name for related entity
            $refEntityReflection = new ReflectionClass($navInfo['entityType']);
            // Try to get column name from property, fallback to 'Id' if property doesn't exist
            $refPrimaryKeyProperty = null;
            foreach ($refEntityReflection->getProperties() as $prop) {
                $keyAttributes = $prop->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Key::class);
                if (!empty($keyAttributes)) {
                    $refPrimaryKeyProperty = $prop->getName();
                    break;
                }
            }
            if ($refPrimaryKeyProperty === null) {
                // Try common names
                $commonNames = ['Id', $refEntityReflection->getShortName() . 'Id'];
                foreach ($commonNames as $name) {
                    if ($refEntityReflection->hasProperty($name)) {
                        $refPrimaryKeyProperty = $name;
                        break;
                    }
                }
            }
            // Get column name from property (handles [Column] attribute)
            // getColumnNameFromProperty already handles [Column] attribute correctly:
            // - If [Column] attribute exists and name is set, use that name
            // - Otherwise, use property name
            $refIdColumn = $refPrimaryKeyProperty ? $this->getColumnNameFromProperty($refEntityReflection, $refPrimaryKeyProperty) : 'Id';
            log_message('debug', "buildJoinCondition: For navigation '{$navPath}', primary key property: '{$refPrimaryKeyProperty}', column name from property: '{$refIdColumn}'");
            
            $quotedMainAlias = $provider->escapeIdentifier($mainAlias);
            $quotedFkColumn = $provider->escapeIdentifier($fkColumn);
            $quotedRefAlias = $provider->escapeIdentifier($refAlias);
            $quotedRefIdColumn = $provider->escapeIdentifier($refIdColumn);
            $joinCondition = "{$quotedMainAlias}.{$quotedFkColumn} = {$quotedRefAlias}.{$quotedRefIdColumn}";
            log_message('debug', "buildJoinCondition: Final JOIN condition for '{$navPath}': {$joinCondition}");
            return $joinCondition;
        } else {
            // One-to-one: FK in related entity
            $refEntityReflection = new ReflectionClass($navInfo['entityType']);
            $fkColumn = $this->getColumnNameFromProperty($refEntityReflection, $foreignKey);
            // Get primary key column name for main entity using getPrimaryKeyColumnName
            // This method already handles [Column] attributes and common naming conventions
            $mainIdColumn = $this->getPrimaryKeyColumnName($entityReflection);
            
            $quotedRefAlias = $provider->escapeIdentifier($refAlias);
            $quotedFkColumn = $provider->escapeIdentifier($fkColumn);
            $quotedMainAlias = $provider->escapeIdentifier($mainAlias);
            $quotedMainIdColumn = $provider->escapeIdentifier($mainIdColumn);
            return "{$quotedRefAlias}.{$quotedFkColumn} = {$quotedMainAlias}.{$quotedMainIdColumn}";
        }
    }

    /**
     * Get JOIN type (INNER or LEFT)
     * @param string $navPath Navigation property path
     * @param array $navInfo Navigation info
     * @param bool $isInWhere Whether this navigation is used in WHERE clause
     * @param bool $isThenInclude Whether this is a thenInclude navigation
     * @return string JOIN type ('INNER JOIN' or 'LEFT JOIN')
     * 
     * Note: This method is used for main-level includes. Nested reference navigation thenIncludes
     * (e.g., Employee.Company) use LEFT JOIN manually to allow null values.
     */
    private function getJoinType(string $navPath, array $navInfo, bool $isInWhere = false, bool $isThenInclude = false): string
    {
        // thenInclude uses INNER JOIN for main-level includes (when used in WHERE)
        // But nested reference navigation thenIncludes use LEFT JOIN (handled manually)
        if ($isThenInclude) {
            return 'INNER JOIN';
        }
        
        // If used in WHERE clause, use INNER JOIN
        if ($isInWhere) {
            return 'INNER JOIN';
        }
        
        // For include() without WHERE clause, use LEFT JOIN
        return 'LEFT JOIN';
    }

    /**
     * Build collection navigation subquery
     * @param string $navPath Navigation property path
     * @param array $navInfo Navigation info
     * @param int $index Collection subquery index (0, 2, 3, etc.)
     * @param array $thenIncludes Array of thenInclude navigation properties
     * @param int $nestedSubqueryIndex Starting index for nested subqueries (1, etc.)
     * @return array|null Subquery info with SQL and metadata
     */
    private function buildCollectionSubquery(string $navPath, array $navInfo, int $index, array $thenIncludes = [], int &$nestedSubqueryIndex = 1): ?array
    {
        if (!$navInfo['isCollection']) {
            return null;
        }
        
        // Initialize foreignKeyColumn for return statement
        $foreignKeyColumn = null;
        
        // If joinEntityType is null, the entityType itself is both the join entity and related entity
        // This happens when collection navigation directly references the related entity (e.g., EmployeeDepartments -> EmployeeDepartment)
        // In this case, there's no separate join entity, just the related entity with a foreign key
        if ($navInfo['joinEntityType']) {
            $joinEntityType = $navInfo['joinEntityType'];
            // For collection navigation with join entity, entityType is the join entity (e.g., UserDepartment)
            // We need to find the actual related entity (e.g., Department) from the join entity
            $joinEntityReflection = new ReflectionClass($joinEntityType);
            $relatedEntityType = $this->findRelatedEntityFromJoinEntity($joinEntityType, $navPath);
            
            if ($relatedEntityType === null) {
                log_message('error', "buildCollectionSubquery: Could not determine related entity from join entity {$joinEntityType} for navigation {$navPath}");
                return null;
            }
            
            $joinTableName = $this->context->getTableName($joinEntityType);
            $relatedTableName = $this->context->getTableName($relatedEntityType);
        } else {
            // No join entity - entityType is the related entity itself
            $joinEntityType = null; // No join entity
            $relatedEntityType = $navInfo['entityType'];
            $joinTableName = null; // No join table
            $relatedTableName = $this->context->getTableName($relatedEntityType);
        }
        
        $relatedEntityReflection = new ReflectionClass($relatedEntityType);
        $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
        
        if ($joinEntityType) {
            // Has join entity (many-to-many or join table scenario)
            $joinAlias = 'u' . ($index + 1);
            $relatedAlias = $this->getTableAlias($navPath, $index);
            
            // Get columns
            $joinEntityReflection = new ReflectionClass($joinEntityType);
            $joinColumns = $this->getEntityColumns($joinEntityReflection);
            $relatedColumnsWithProperties = $this->getEntityColumnsWithProperties($relatedEntityReflection);
            
            log_message('debug', "buildCollectionSubquery: Join entity: {$joinEntityType}, Related entity: {$relatedEntityType}");
            
            // Build SELECT
            $selectColumns = [];
            $quotedJoinAlias = $provider->escapeIdentifier($joinAlias);
            foreach ($joinColumns as $col) {
                $quotedJoinCol = $provider->escapeIdentifier($col);
                $selectColumns[] = "{$quotedJoinAlias}.{$quotedJoinCol}";
            }
            $relatedIdx = 0;
            $firstCol = true;
            $quotedRelatedAlias = $provider->escapeIdentifier($relatedAlias);
            foreach ($relatedColumnsWithProperties as $colInfo) {
                $col = $colInfo['column'];
                $property = $relatedEntityReflection->getProperty($colInfo['property']);
                $sensitiveAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\SensitiveValue::class);
                
                $quotedRelatedCol = $provider->escapeIdentifier($col);
                
                if ($firstCol && $col === 'Id') {
                    $quotedIdAlias = $provider->escapeIdentifier("Id{$relatedIdx}");
                    $selectColumns[] = "{$quotedRelatedAlias}.{$quotedRelatedCol} AS {$quotedIdAlias}";
                    $firstCol = false;
                } else {
                    // Apply masking for sensitive columns in related entity
                    if (!empty($sensitiveAttributes) && !$this->isSensitive) {
                        $sensitiveAttr = $sensitiveAttributes[0]->newInstance();
                        $columnRef = "{$quotedRelatedAlias}.{$quotedRelatedCol}";
                        $maskedExpression = $provider->getMaskingSql(
                            $columnRef,
                            $sensitiveAttr->maskChar,
                            $sensitiveAttr->visibleStart,
                            $sensitiveAttr->visibleEnd,
                            $sensitiveAttr->customMask
                        );
                        $selectColumns[] = "({$maskedExpression}) AS {$quotedRelatedCol}";
                    } else {
                        // No masking
                        $selectColumns[] = "{$quotedRelatedAlias}.{$quotedRelatedCol}";
                    }
                }
            }
            
            // Build JOIN condition
            // For join entity (e.g., UserDepartment), find the FK that points to related entity (e.g., Department)
            // Check ForeignKey attribute on join entity properties
            $joinFk = null;
            $relatedEntityShortName = (new ReflectionClass($relatedEntityType))->getShortName();
            $expectedFkName = $relatedEntityShortName . 'Id'; // e.g., DepartmentId
            
            log_message('debug', "buildCollectionSubquery: Looking for FK in join entity {$joinEntityType} pointing to {$relatedEntityType} (expected: {$expectedFkName})");
            
            // First, try to find FK by convention (RelatedEntityName + "Id")
            if ($joinEntityReflection->hasProperty($expectedFkName)) {
                $joinFk = $expectedFkName;
                log_message('debug', "buildCollectionSubquery: Found FK by convention: {$joinFk}");
            } else {
                log_message('debug', "buildCollectionSubquery: Property {$expectedFkName} not found, checking ForeignKey attributes");
                
                // Check ForeignKey attribute on properties
                foreach ($joinEntityReflection->getProperties() as $property) {
                    $attributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\ForeignKey::class);
                    if (!empty($attributes)) {
                        $fkAttr = $attributes[0]->newInstance();
                        $propName = $property->getName();
                        
                        // Check if this FK points to the related entity
                        // ForeignKey attribute has navigationProperty that should match related entity name
                        if ($fkAttr->navigationProperty === $relatedEntityShortName || 
                            $propName === $expectedFkName) {
                            $joinFk = $propName;
                            log_message('debug', "buildCollectionSubquery: Found FK by ForeignKey attribute: {$joinFk} (navigationProperty: {$fkAttr->navigationProperty})");
                            break;
                        }
                    }
                }
            }
            
            // Fallback: try to find property that matches related entity name pattern
            if ($joinFk === null) {
                log_message('debug', "buildCollectionSubquery: Trying pattern matching for FK");
                foreach ($joinEntityReflection->getProperties() as $property) {
                    $propName = $property->getName();
                    if (str_ends_with($propName, 'Id') && $propName !== 'Id') {
                        // Check if it might be the FK we're looking for
                        $possibleEntityName = str_replace('Id', '', $propName);
                        if (strcasecmp($possibleEntityName, $relatedEntityShortName) === 0) {
                            $joinFk = $propName;
                            log_message('debug', "buildCollectionSubquery: Found FK by pattern matching: {$joinFk}");
                            break;
                        }
                    }
                }
            }
            
            if ($joinFk === null) {
                // Last resort: use convention
                $joinFk = $expectedFkName;
                log_message('debug', "buildCollectionSubquery: Using convention as last resort: {$joinFk}");
            }
            
            // Verify the property exists before getting column name
            if (!$joinEntityReflection->hasProperty($joinFk)) {
                log_message('error', "buildCollectionSubquery: FK property {$joinFk} does not exist in {$joinEntityType}");
                // List all properties for debugging
                $allProps = [];
                foreach ($joinEntityReflection->getProperties() as $prop) {
                    $allProps[] = $prop->getName();
                }
                log_message('debug', "buildCollectionSubquery: Available properties in {$joinEntityType}: " . implode(', ', $allProps));
                throw new \RuntimeException("Foreign key property {$joinFk} not found in join entity {$joinEntityType}");
            }
            
            $joinFkColumn = $this->getColumnNameFromProperty($joinEntityReflection, $joinFk);
            log_message('debug', "buildCollectionSubquery: Using FK column: {$joinFkColumn} for join condition");
            $quotedJoinFkColumn = $provider->escapeIdentifier($joinFkColumn);
            $quotedRelatedId = $provider->escapeIdentifier('Id');
            $joinCondition = "{$quotedJoinAlias}.{$quotedJoinFkColumn} = {$quotedRelatedAlias}.{$quotedRelatedId}";
        } else {
            // No join entity - related entity directly has foreign key to main entity
            $relatedAlias = 'u' . ($index + 1);
            
            // Get columns from related entity only
            $relatedColumnsWithProperties = $this->getEntityColumnsWithProperties($relatedEntityReflection);
            
            log_message('debug', "buildCollectionSubquery: No join entity, Related entity: {$relatedEntityType}");
            
            // Build SELECT - only related entity columns
            $selectColumns = [];
            $relatedIdx = 0;
            $firstCol = true;
            $quotedRelatedAlias = $provider->escapeIdentifier($relatedAlias);
            // Get primary key column name for related entity
            $relatedPrimaryKeyColumn = $this->getPrimaryKeyColumnName($relatedEntityReflection);
            foreach ($relatedColumnsWithProperties as $colInfo) {
                $col = $colInfo['column'];
                $property = $relatedEntityReflection->getProperty($colInfo['property']);
                $sensitiveAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\SensitiveValue::class);
                
                $quotedRelatedCol = $provider->escapeIdentifier($col);
                
                if ($firstCol && $col === $relatedPrimaryKeyColumn) {
                    $quotedIdAlias = $provider->escapeIdentifier("Id{$relatedIdx}");
                    $selectColumns[] = "{$quotedRelatedAlias}.{$quotedRelatedCol} AS {$quotedIdAlias}";
                    $firstCol = false;
                } else {
                    // Apply masking for sensitive columns in related entity
                    if (!empty($sensitiveAttributes) && !$this->isSensitive) {
                        $sensitiveAttr = $sensitiveAttributes[0]->newInstance();
                        $columnRef = "{$quotedRelatedAlias}.{$quotedRelatedCol}";
                        $maskedExpression = $provider->getMaskingSql(
                            $columnRef,
                            $sensitiveAttr->maskChar,
                            $sensitiveAttr->visibleStart,
                            $sensitiveAttr->visibleEnd,
                            $sensitiveAttr->customMask
                        );
                        $selectColumns[] = "({$maskedExpression}) AS {$quotedRelatedCol}";
                    } else {
                        // No masking
                        $selectColumns[] = "{$quotedRelatedAlias}.{$quotedRelatedCol}";
                    }
                }
            }
            
            // Build JOIN condition - foreign key in related entity points to main entity
            // Foreign key is in navInfo
            $foreignKey = $navInfo['foreignKey']; // e.g., EmployeeId
            // Get column name from getEntityColumnsWithProperties to match what's selected in subquery
            $foreignKeyColumn = null;
            foreach ($relatedColumnsWithProperties as $colInfo) {
                if ($colInfo['property'] === $foreignKey) {
                    $foreignKeyColumn = $colInfo['column']; // Use the exact column name from getEntityColumnsWithProperties
                    break;
                }
            }
            // Fallback to getColumnNameFromProperty if not found
            if ($foreignKeyColumn === null) {
                $foreignKeyColumn = $this->getColumnNameFromProperty($relatedEntityReflection, $foreignKey);
            }
            $mainEntityReflection = new ReflectionClass($this->entityType);
            $mainPrimaryKeyColumn = $this->getPrimaryKeyColumnName($mainEntityReflection);
            
            log_message('debug', "buildCollectionSubquery: Using FK column: {$foreignKeyColumn} in related entity pointing to main entity primary key: {$mainPrimaryKeyColumn}");
            
            $quotedFkColumn = $provider->escapeIdentifier($foreignKeyColumn);
            $quotedMainPkColumn = $provider->escapeIdentifier($mainPrimaryKeyColumn);
            // Note: This join condition will be used in the final LEFT JOIN, not here
            // For now, we just need to know the FK column for the subquery
            $joinCondition = null; // Will be set in final query
        }
        
        // Build nested subqueries for thenIncludes
        $nestedSubqueries = [];
        $nestedSubqueryJoins = [];
        $nestedSelectColumns = [];
        $currentNestedIndex = $nestedSubqueryIndex;
        
        foreach ($thenIncludes as $thenInclude) {
            // Get navigation info for thenInclude (from related entity)
            $thenNavInfo = $this->getNavigationInfoForEntity($thenInclude, $relatedEntityType);
            if ($thenNavInfo && $thenNavInfo['isCollection']) {
                // Build nested subquery for collection navigation
                $nestedSubquery = $this->buildNestedCollectionSubquery($thenInclude, $thenNavInfo, $currentNestedIndex, $relatedEntityType);
                if ($nestedSubquery) {
                    $nestedSubqueries[] = $nestedSubquery;
                    // Add nested subquery columns to main collection subquery SELECT
                    $nestedAlias = 's' . $currentNestedIndex;
                    $nestedJoinEntityType = $nestedSubquery['joinEntityType'];
                    $nestedRelatedEntityType = $nestedSubquery['entityType'];
                    $nestedJoinEntityReflection = new ReflectionClass($nestedJoinEntityType);
                    $nestedJoinColumns = $this->getEntityColumns($nestedJoinEntityReflection);
                    $nestedRelatedEntityReflection = new ReflectionClass($nestedRelatedEntityType);
                    $nestedRelatedColumns = $this->getEntityColumns($nestedRelatedEntityReflection);
                    
                    // Add nested subquery columns to main collection subquery SELECT (EF Core format)
                    // Nested subquery returns: [a0].[Id], [a0].[AuthorizationId], [a0].[OperationClaimId], [o].[Id] AS [Id0], [o].[Description], [o].[Name]
                    // In collection subquery SELECT, these become: [s1].[Id], [s1].[AuthorizationId], [s1].[OperationClaimId], [s1].[Id0], [s1].[Description], [s1].[Name]
                    // Then aliased in final SELECT: [s1].[Id] AS [Id1], [s1].[AuthorizationId] AS [AuthorizationId0], [s1].[OperationClaimId] AS [OperationClaimId0], [s1].[Id0] AS [Id00], [s1].[Description] AS [Description0], [s1].[Name] AS [Name0]
                    
                    // Add nested join entity columns with aliases (EF Core format)
                    // EF Core: [s1].[Id] AS [Id1], [s1].[AuthorizationId] AS [AuthorizationId0], [s1].[OperationClaimId]
                    foreach ($nestedJoinColumns as $col) {
                        if ($col === 'Id') {
                            // Join entity Id: [s1].[Id] AS [Id1]
                            $nestedSelectColumns[] = "[{$nestedAlias}].[{$col}] AS [Id{$currentNestedIndex}]";
                        } elseif (str_ends_with($col, 'Id') && $col !== 'Id') {
                            // Foreign key columns: Check if this is the FK pointing to parent entity
                            $parentEntityShortName = (new ReflectionClass($relatedEntityType))->getShortName();
                            if ($col === $parentEntityShortName . 'Id') {
                                // FK to parent entity: [s1].[AuthorizationId] AS [AuthorizationId0]
                                $nestedSelectColumns[] = "[{$nestedAlias}].[{$col}] AS [{$col}0]";
                            } else {
                                // Other FK columns: [s1].[OperationClaimId] (no alias)
                                $nestedSelectColumns[] = "[{$nestedAlias}].[{$col}]";
                            }
                        } else {
                            $nestedSelectColumns[] = "[{$nestedAlias}].[{$col}]";
                        }
                    }
                    // Add nested related entity columns with aliases (EF Core format)
                    // EF Core: [s1].[Id0] AS [Id00], [s1].[Description] AS [Description0], [s1].[Name] AS [Name0]
                    $nestedRelatedIdx = 0;
                    $firstCol = true;
                    foreach ($nestedRelatedColumns as $col) {
                        if ($firstCol && $col === 'Id') {
                            // Related entity Id: [s1].[Id0] AS [Id00] (NOT Id1!)
                            $nestedSelectColumns[] = "[{$nestedAlias}].[Id{$nestedRelatedIdx}] AS [Id00]";
                            $firstCol = false;
                        } else {
                            // Other columns: [s1].[Description] AS [Description0], [s1].[Name] AS [Name0]
                            $nestedSelectColumns[] = "[{$nestedAlias}].[{$col}] AS [{$col}0]";
                        }
                    }
                    
                    // Build nested subquery LEFT JOIN condition
                    // For AuthorizationOperationClaims nested in Authorization, join on Authorization.Id
                    $parentEntityReflection = new ReflectionClass($relatedEntityType);
                    $parentIdColumn = 'Id';
                    $nestedJoinFkColumn = 'AuthorizationId'; // TODO: Make this dynamic based on thenInclude navigation
                    $nestedJoinCondition = "[{$relatedAlias}].[{$parentIdColumn}] = [{$nestedAlias}].[{$nestedJoinFkColumn}]";
                    $nestedSubqueryJoins[] = "LEFT JOIN (\n    " . str_replace("\n", "\n    ", $nestedSubquery['sql']) . "\n) AS [{$nestedAlias}] ON {$nestedJoinCondition}";
                    
                    $currentNestedIndex++;
                }
            } elseif ($thenNavInfo && !$thenNavInfo['isCollection']) {
                // Reference navigation - add JOIN in collection subquery
                $thenRelatedEntityType = $thenNavInfo['entityType'];
                $thenForeignKey = $thenNavInfo['foreignKey'];
                $thenRelatedEntityReflection = new ReflectionClass($thenRelatedEntityType);
                $thenRelatedTableName = $this->context->getTableName($thenRelatedEntityType);
                $thenRelatedAlias = $this->getTableAlias($thenInclude, 0);
                
                // Get columns from thenInclude reference entity
                $thenRelatedColumnsWithProperties = $this->getEntityColumnsWithProperties($thenRelatedEntityReflection);
                $thenRelatedPrimaryKeyColumn = $this->getPrimaryKeyColumnName($thenRelatedEntityReflection);
                
                // Add columns to SELECT
                $quotedThenRelatedAlias = $provider->escapeIdentifier($thenRelatedAlias);
                $thenFirstCol = true;
                foreach ($thenRelatedColumnsWithProperties as $colInfo) {
                    $col = $colInfo['column'];
                    $property = $thenRelatedEntityReflection->getProperty($colInfo['property']);
                    $sensitiveAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\SensitiveValue::class);
                    
                    $quotedThenCol = $provider->escapeIdentifier($col);
                    
                    if ($thenFirstCol && $col === $thenRelatedPrimaryKeyColumn) {
                        // Primary key gets alias - but we need to avoid conflicts
                        // Use a unique alias based on thenInclude name
                        $thenIdAlias = "Id" . ucfirst($thenInclude) . "0";
                        $nestedSelectColumns[] = "{$quotedThenRelatedAlias}.{$quotedThenCol} AS [{$thenIdAlias}]";
                        $thenFirstCol = false;
                    } else {
                        // Apply masking if needed
                        if (!empty($sensitiveAttributes) && !$this->isSensitive) {
                            $sensitiveAttr = $sensitiveAttributes[0]->newInstance();
                            $columnRef = "{$quotedThenRelatedAlias}.{$quotedThenCol}";
                            $maskedExpression = $provider->getMaskingSql(
                                $columnRef,
                                $sensitiveAttr->maskChar,
                                $sensitiveAttr->visibleStart,
                                $sensitiveAttr->visibleEnd,
                                $sensitiveAttr->customMask
                            );
                            $nestedSelectColumns[] = "({$maskedExpression}) AS {$quotedThenCol}";
                        } else {
                            $nestedSelectColumns[] = "{$quotedThenRelatedAlias}.{$quotedThenCol}";
                        }
                    }
                }
                
                // Build JOIN condition
                $thenFkColumn = $this->getColumnNameFromProperty($relatedEntityReflection, $thenForeignKey);
                $thenRelatedPkColumn = $this->getPrimaryKeyColumnName($thenRelatedEntityReflection);
                $quotedThenFkColumn = $provider->escapeIdentifier($thenFkColumn);
                $quotedThenRelatedPkColumn = $provider->escapeIdentifier($thenRelatedPkColumn);
                $quotedThenRelatedTableName = $provider->escapeIdentifier($thenRelatedTableName);
                
                // Add JOIN to SQL (will be added after FROM clause)
                $thenJoinCondition = "{$quotedRelatedAlias}.{$quotedThenFkColumn} = {$quotedThenRelatedAlias}.{$quotedThenRelatedPkColumn}";
                // Store join info for later
                if (!isset($nestedSubqueryJoins)) {
                    $nestedSubqueryJoins = [];
                }
                $nestedSubqueryJoins[] = "LEFT JOIN {$quotedThenRelatedTableName} AS {$quotedThenRelatedAlias} ON {$thenJoinCondition}";
            }
        }
        
        // Update nestedSubqueryIndex for next collection subquery
        $nestedSubqueryIndex = $currentNestedIndex;
        
        // Combine all SELECT columns
        $allSelectColumns = array_merge($selectColumns, $nestedSelectColumns);
        
        // Build SQL with nested subqueries
        if ($joinEntityType) {
            // Has join entity - use JOIN between join entity and related entity
            // For include() collections, use LEFT JOIN (thenInclude uses INNER JOIN in nested subquery)
            $quotedJoinTableName = $provider->escapeIdentifier($joinTableName);
            $quotedRelatedTableName = $provider->escapeIdentifier($relatedTableName);
            $sql = "SELECT " . implode(', ', $allSelectColumns) . "\n"
                . "FROM {$quotedJoinTableName} AS {$quotedJoinAlias}\n"
                . "LEFT JOIN {$quotedRelatedTableName} AS {$quotedRelatedAlias} ON {$joinCondition}";
        } else {
            // No join entity - just SELECT from related entity
            $quotedRelatedTableName = $provider->escapeIdentifier($relatedTableName);
            $sql = "SELECT " . implode(', ', $allSelectColumns) . "\n"
                . "FROM {$quotedRelatedTableName} AS {$quotedRelatedAlias}";
        }
        
        // Add JOINs for thenInclude reference navigations (if any)
        if (!empty($nestedSubqueryJoins)) {
            // Filter out nested subquery joins (they start with "LEFT JOIN (")
            // and keep only direct JOINs (they start with "LEFT JOIN [table]")
            $directJoins = [];
            $nestedSubqueryLeftJoins = [];
            foreach ($nestedSubqueryJoins as $join) {
                if (strpos($join, 'LEFT JOIN (') === 0) {
                    $nestedSubqueryLeftJoins[] = $join;
                } else {
                    $directJoins[] = $join;
                }
            }
            // Add direct JOINs first (for thenInclude reference navigations)
            if (!empty($directJoins)) {
                $sql .= "\n" . implode("\n", $directJoins);
            }
            // Add nested subquery LEFT JOINs
            if (!empty($nestedSubqueryLeftJoins)) {
                $sql .= "\n" . implode("\n", $nestedSubqueryLeftJoins);
            }
        }
        
        return [
            'navigation' => $navPath,
            'sql' => $sql,
            'joinEntityType' => $joinEntityType,
            'entityType' => $relatedEntityType,
            'nestedSubqueryIndex' => $nestedSubqueryIndex,
            'selectColumns' => $allSelectColumns,
            'foreignKey' => $joinEntityType ? null : ($foreignKeyColumn ?? $navInfo['foreignKey']) // Store FK column name (not property name) for final JOIN
        ];
    }
    
    /**
     * Build nested collection subquery (for thenInclude)
     */
    private function buildNestedCollectionSubquery(string $navPath, array $navInfo, int $index, string $parentEntityType): ?array
    {
        if (!$navInfo['isCollection'] || !$navInfo['joinEntityType']) {
            return null;
        }
        
        $joinEntityType = $navInfo['joinEntityType'];
        $joinEntityReflection = new ReflectionClass($joinEntityType);
        
        // For nested subquery, find related entity excluding parent entity (not main entity)
        $relatedEntityType = $this->findRelatedEntityFromJoinEntityForParent($joinEntityType, $parentEntityType);
        
        if ($relatedEntityType === null) {
            return null;
        }
        
        $joinTableName = $this->context->getTableName($joinEntityType);
        $relatedTableName = $this->context->getTableName($relatedEntityType);
        
        // EF Core uses a0, a1, etc. for join entity, o, o0, etc. for related entity
        $joinAlias = 'a' . ($index - 1); // e.g., a0 (for index 1), a1 (for index 2)
        $relatedAlias = 'o'; // e.g., o
        
        // Get columns
        $joinColumns = $this->getEntityColumns($joinEntityReflection);
        $relatedEntityReflection = new ReflectionClass($relatedEntityType);
        $relatedColumnsWithProperties = $this->getEntityColumnsWithProperties($relatedEntityReflection);
        
        $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
        
        // Build SELECT
        $selectColumns = [];
        $quotedJoinAlias = $provider->escapeIdentifier($joinAlias);
        foreach ($joinColumns as $col) {
            $quotedJoinCol = $provider->escapeIdentifier($col);
            $selectColumns[] = "{$quotedJoinAlias}.{$quotedJoinCol}";
        }
        $relatedIdx = 0;
        $firstCol = true;
        $quotedRelatedAlias = $provider->escapeIdentifier($relatedAlias);
        foreach ($relatedColumnsWithProperties as $colInfo) {
            $col = $colInfo['column'];
            $property = $relatedEntityReflection->getProperty($colInfo['property']);
            $sensitiveAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\SensitiveValue::class);
            
            $quotedRelatedCol = $provider->escapeIdentifier($col);
            
            if ($firstCol && $col === 'Id') {
                $quotedIdAlias = $provider->escapeIdentifier("Id{$relatedIdx}");
                $selectColumns[] = "{$quotedRelatedAlias}.{$quotedRelatedCol} AS {$quotedIdAlias}";
                $firstCol = false;
            } else {
                // Apply masking for sensitive columns in related entity
                if (!empty($sensitiveAttributes) && !$this->isSensitive) {
                    $sensitiveAttr = $sensitiveAttributes[0]->newInstance();
                    $columnRef = "{$quotedRelatedAlias}.{$quotedRelatedCol}";
                    $maskedExpression = $provider->getMaskingSql(
                        $columnRef,
                        $sensitiveAttr->maskChar,
                        $sensitiveAttr->visibleStart,
                        $sensitiveAttr->visibleEnd,
                        $sensitiveAttr->customMask
                    );
                    $selectColumns[] = "({$maskedExpression}) AS {$quotedRelatedCol}";
                } else {
                    // No masking
                    $selectColumns[] = "{$quotedRelatedAlias}.{$quotedRelatedCol}";
                }
            }
        }
        
        // Build JOIN condition
        $relatedEntityShortName = (new ReflectionClass($relatedEntityType))->getShortName();
        $expectedFkName = $relatedEntityShortName . 'Id';
        $joinFk = $expectedFkName;
        
        if ($joinEntityReflection->hasProperty($joinFk)) {
            $joinFkColumn = $this->getColumnNameFromProperty($joinEntityReflection, $joinFk);
            $quotedJoinFkColumn = $provider->escapeIdentifier($joinFkColumn);
            $quotedRelatedId = $provider->escapeIdentifier('Id');
            $joinCondition = "{$quotedJoinAlias}.{$quotedJoinFkColumn} = {$quotedRelatedAlias}.{$quotedRelatedId}";
        } else {
            return null;
        }
        
        $quotedJoinTableName = $provider->escapeIdentifier($joinTableName);
        $quotedRelatedTableName = $provider->escapeIdentifier($relatedTableName);
        // For thenInclude() nested collections, use INNER JOIN
        $sql = "SELECT " . implode(', ', $selectColumns) . "\n"
            . "FROM {$quotedJoinTableName} AS {$quotedJoinAlias}\n"
            . "INNER JOIN {$quotedRelatedTableName} AS {$quotedRelatedAlias} ON {$joinCondition}";
        
        return [
            'navigation' => $navPath,
            'sql' => $sql,
            'joinEntityType' => $joinEntityType,
            'entityType' => $relatedEntityType
        ];
    }
    
    /**
     * Find related entity from join entity for nested subquery (excludes parent entity)
     */
    private function findRelatedEntityFromJoinEntityForParent(string $joinEntityType, string $parentEntityType): ?string
    {
        $joinEntityReflection = new ReflectionClass($joinEntityType);
        $parentEntityShortName = (new ReflectionClass($parentEntityType))->getShortName();
        
        // Look for navigation properties in join entity that point to the related entity
        // The related entity is the one that is NOT the parent entity
        foreach ($joinEntityReflection->getProperties() as $property) {
            $docComment = $property->getDocComment();
            if (!$docComment) {
                continue;
            }
            
            // Check for navigation property (not array, not parent entity)
            if (preg_match('/@var\s+([A-Za-z_][A-Za-z0-9_\\\\]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)(\[\])?/', $docComment, $matches)) {
                $entityType = $matches[1];
                $isCollection = !empty($matches[2]);
                
                // Skip if it's a collection
                if ($isCollection) {
                    continue;
                }
                
                // Resolve namespace using use statements
                if ($entityType && !str_starts_with($entityType, '\\')) {
                    $resolved = $this->resolveEntityType($entityType, $joinEntityReflection);
                    if ($resolved !== null) {
                        $entityType = $resolved;
                    }
                }
                
                $entityShortName = (new ReflectionClass($entityType))->getShortName();
                
                // Return if it's not the parent entity
                if ($entityShortName !== $parentEntityShortName) {
                    log_message('debug', "findRelatedEntityFromJoinEntityForParent: Found related entity {$entityType} (not parent entity {$parentEntityShortName})");
                    return $entityType;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Get navigation info for a specific entity type
     */
    private function getNavigationInfoForEntity(string $navigationProperty, string $entityType): ?array
    {
        // Temporarily change entityType to get navigation info
        $originalEntityType = $this->entityType;
        $this->entityType = $entityType;
        $navInfo = $this->getNavigationInfo($navigationProperty);
        $this->entityType = $originalEntityType;
        return $navInfo;
    }

    /**
     * Convert navigation WHERE clause to SQL
     * Uses aliases from referenceNavAliases map
     */
    private function convertNavigationWhereToSql(callable $predicate, array $navigationPaths, array $referenceNavAliases = []): ?string
    {
        // Use existing applyNavigationWhereToSql logic but return SQL string
        $reflection = new \ReflectionFunction($predicate);
        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();
        
        if (!$file || !$start || !$end) {
            return null;
        }
        
        $lines = file($file);
        $code = implode('', array_slice($lines, $start - 1, $end - $start + 1));
        
        $conditions = [];
        foreach ($navigationPaths as $navProp) {
            $navInfo = $this->getNavigationInfo($navProp);
            if (!$navInfo) {
                continue;
            }
            
            // Get alias from referenceNavAliases map, or generate one
            $alias = $referenceNavAliases[$navProp] ?? $this->getTableAlias($navProp, 0);
            
            // Extract property comparisons from code
            $pattern = '/\$[a-zA-Z_][a-zA-Z0-9_]*->' . preg_quote($navProp, '/') . '->([A-Za-z0-9_]+)\s*(===|==|!=|<>)\s*["\']([^"\']+)["\']/';
            if (preg_match_all($pattern, $code, $matches)) {
                foreach ($matches[1] as $idx => $property) {
                    $operator = $matches[2][$idx] === '==' || $matches[2][$idx] === '===' ? '=' : $matches[2][$idx];
                    $value = $matches[3][$idx];
                    $columnName = $this->getColumnNameFromProperty(new ReflectionClass($navInfo['entityType']), $property);
                    // Use alias instead of table name
                    $conditions[] = "[{$alias}].[{$columnName}] = N'{$value}'";
                }
            }
        }
        
        return !empty($conditions) ? implode(' AND ', $conditions) : null;
    }

    /**
     * Convert simple WHERE clause to SQL
     */
    private function convertSimpleWhereToSql(callable $predicate, string $alias): ?string
    {
        try {
            $parser = new ExpressionParser($this->entityType, $alias, $this->context);
            
            // Try to extract variable values from closure
            $reflection = new \ReflectionFunction($predicate);
            $staticVariables = $reflection->getStaticVariables();
            
            // Parse closure code to extract use() clause variables
            $closureFile = $reflection->getFileName();
            $closureStartLine = $reflection->getStartLine();
            $closureEndLine = $reflection->getEndLine();
            
            $variableValues = [];
            
            // Try to get variables from static variables first
            foreach ($staticVariables as $varName => $varValue) {
                // Skip entity objects (they're the lambda parameter)
                if (!is_object($varValue) || !($varValue instanceof \Yakupeyisan\CodeIgniter4\EntityFramework\Core\Entity)) {
                    $variableValues[$varName] = $varValue;
                }
            }
            
            // Try to parse use() clause from closure code and extract variable names
            $useVarNames = [];
            if ($closureFile && file_exists($closureFile) && $closureStartLine && $closureEndLine) {
                $lines = file($closureFile);
                // Get a few lines before the closure to catch use() clause
                $startLine = max(1, $closureStartLine - 5);
                $endLine = min(count($lines), $closureEndLine);
                $closureCode = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
                
                // Extract use() clause: pattern: use ($var1, $var2, ...) or fn($e) => $e->Id === $id (no use clause)
                if (preg_match('/use\s*\(\s*([^)]+)\s*\)/', $closureCode, $useMatches)) {
                    $useVars = preg_split('/\s*,\s*/', trim($useMatches[1]));
                    foreach ($useVars as $useVar) {
                        $useVar = trim($useVar);
                        $varName = ltrim($useVar, '$');
                        $useVarNames[] = $varName;
                    }
                } else {
                    // No use() clause found - try to extract variable names from the expression itself
                    // Pattern: fn($e) => $e->Id === $id (where $id is a variable in parent scope)
                    if (preg_match('/=>\s*.+?\$([a-zA-Z_][a-zA-Z0-9_]*)/', $closureCode, $varMatches)) {
                        // Check if it's not the lambda parameter ($e, $u, etc.)
                        $possibleVarName = $varMatches[1];
                        // Lambda parameters are usually single letters like $e, $u, $x
                        if (strlen($possibleVarName) > 1 || !in_array($possibleVarName, ['e', 'u', 'x', 'a', 'i', 'o'])) {
                            $useVarNames[] = $possibleVarName;
                        }
                    }
                }
            }
            
            $parser->setVariableValues($variableValues);
            
            $sqlCondition = $parser->parse($predicate);
            
            if (!empty($sqlCondition)) {
                // Get parameter map from parser
                $parameterMap = $parser->getParameterMap();
                
                // Clean up any remaining $variable references that weren't parsed
                // This handles cases where variables weren't properly replaced
                $sqlCondition = preg_replace('/\$[a-zA-Z_][a-zA-Z0-9_]*/', '', $sqlCondition);
                // Remove any -> operators that might have leaked through (not valid in SQL Server)
                $sqlCondition = preg_replace('/\s*->\s*/', ' ', $sqlCondition);
                $sqlCondition = preg_replace('/^->+|->+$/', '', $sqlCondition);
                // Clean up any double spaces or operators
                $sqlCondition = preg_replace('/\s*=\s*=\s*/', ' = ', $sqlCondition);
                $sqlCondition = preg_replace('/\s+/', ' ', $sqlCondition);
                $sqlCondition = trim($sqlCondition);
                
                // Only return if we have a valid condition (not empty after cleanup)
                // Check if condition is not just whitespace or -> operators
                $isValid = !empty($sqlCondition) && trim($sqlCondition) !== '' && strpos($sqlCondition, '->') === false;
                if ($isValid) {
                    // Get parameter values for binding
                    $paramValues = $parser->getParameterValues();
                    
                    // If we have ? placeholders, replace them with actual values
                    if (!empty($paramValues) && strpos($sqlCondition, '?') !== false) {
                        $paramIndex = 0;
                        $sqlCondition = preg_replace_callback('/\?/', function() use (&$paramIndex, &$paramValues, &$variableValues, &$parameterMap) {
                            if ($paramIndex >= count($paramValues)) {
                                return 'NULL';
                            }
                            
                            $value = $paramValues[$paramIndex];
                            $paramIndex++;
                            
                            // If value is null, try to get from variableValues using parameter map
                            if ($value === null && !empty($parameterMap)) {
                                $paramKeys = array_keys($parameterMap);
                                if (isset($paramKeys[$paramIndex - 1])) {
                                    $varName = ltrim($parameterMap[$paramKeys[$paramIndex - 1]], '$');
                                    if (isset($variableValues[$varName])) {
                                        $value = $variableValues[$varName];
                                    }
                                }
                            }
                            
                            // Format value for SQL
                            if (is_string($value)) {
                                $value = "'" . str_replace("'", "''", $value) . "'";
                            } elseif (is_numeric($value)) {
                                $value = (string)$value;
                            } elseif (is_bool($value)) {
                                $value = $value ? '1' : '0';
                            } elseif (is_null($value)) {
                                $value = 'NULL';
                            } else {
                                $value = "'" . str_replace("'", "''", (string)$value) . "'";
                            }
                            return $value;
                        }, $sqlCondition);
                    }
                    
                    // Replace table alias if needed (ExpressionParser might use different alias)
                    // Replace "EntityName.ColumnName" with "[alias].[ColumnName]"
                    $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
                    $quotedAlias = $provider->escapeIdentifier($alias);
                    $entityName = (new \ReflectionClass($this->entityType))->getShortName();
                    // Replace "EntityName.ColumnName" with "[alias].[ColumnName]"
                    $sqlCondition = preg_replace_callback('/\b' . preg_quote($entityName, '/') . '\.([a-zA-Z_][a-zA-Z0-9_]*)\b/', function($matches) use ($quotedAlias, $provider) {
                        $columnName = $matches[1];
                        $quotedColumn = $provider->escapeIdentifier($columnName);
                        return "{$quotedAlias}.{$quotedColumn}";
                    }, $sqlCondition);
                    
                    return $sqlCondition;
                }
            }
        } catch (\Exception $e) {
            log_message('debug', 'convertSimpleWhereToSql failed: ' . $e->getMessage());
            log_message('debug', 'Exception trace: ' . $e->getTraceAsString());
        }
        
        return null;
    }

    /**
     * Convert ORDER BY closure to SQL
     */
    private function convertOrderByToSql(callable $keySelector, string $direction, string $alias): ?string
    {
        try {
            $reflection = new \ReflectionFunction($keySelector);
            $staticVariables = $reflection->getStaticVariables();
            
            log_message('debug', 'convertOrderByToSql: staticVariables = ' . json_encode(array_keys($staticVariables)));
            
            // Try to extract field name from closure's static variables (for $e->$field pattern)
            $fieldName = null;
            $navigationProperty = null;
            $nestedProperty = null;
            
            // Check for 'field' or 'fieldPath' in static variables (from createNavigationKeySelector)
            // Also check all variables that might contain the field path
            foreach ($staticVariables as $varName => $varValue) {
                if (is_string($varValue) && strpos($varValue, '.') !== false) {
                    // This might be a navigation property path
                    $fieldName = $varValue;
                    $parts = explode('.', $fieldName, 2);
                    if (count($parts) === 2) {
                        $navigationProperty = $parts[0];
                        $nestedProperty = $parts[1];
                        log_message('debug', "convertOrderByToSql: Found navigation property from static variable '{$varName}': {$navigationProperty}.{$nestedProperty}");
                        break;
                    }
                }
            }
            
            if ($navigationProperty === null || $nestedProperty === null) {
                // Try to parse closure code to extract property name
                $closureFile = $reflection->getFileName();
                $closureStartLine = $reflection->getStartLine();
                $closureEndLine = $reflection->getEndLine();
                
                if ($closureFile && file_exists($closureFile) && $closureStartLine && $closureEndLine) {
                    $lines = file($closureFile);
                    $startLine = max(1, $closureStartLine - 5);
                    $endLine = min(count($lines), $closureEndLine);
                    $closureCode = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
                    
                    // Pattern: $e->NavProp->Property (navigation property)
                    if (preg_match('/\$[a-zA-Z_][a-zA-Z0-9_]*\s*->\s*([A-Z][a-zA-Z0-9_]*)\s*->\s*([A-Z][a-zA-Z0-9_]*)/', $closureCode, $matches)) {
                        $navigationProperty = $matches[1];
                        $nestedProperty = $matches[2];
                    } elseif (preg_match('/\$[a-zA-Z_][a-zA-Z0-9_]*\s*->\s*\$([a-zA-Z_][a-zA-Z0-9_]*)/', $closureCode, $matches)) {
                        // Dynamic property: $e->$field
                        $varName = $matches[1];
                        if (isset($staticVariables[$varName])) {
                            $fieldName = $staticVariables[$varName];
                            // Check if it's a navigation property (e.g., "Kadro.Name")
                            if (strpos($fieldName, '.') !== false) {
                                $parts = explode('.', $fieldName, 2);
                                $navigationProperty = $parts[0];
                                $nestedProperty = $parts[1];
                            }
                        }
                    } elseif (preg_match('/\$[a-zA-Z_][a-zA-Z0-9_]*\s*->\s*([a-zA-Z_][a-zA-Z0-9_]*)/', $closureCode, $matches)) {
                        // Static property: $e->PropertyName
                        $fieldName = $matches[1];
                    }
                }
            }
            
            // Handle navigation property (e.g., Kadro.Name)
            if ($navigationProperty !== null && $nestedProperty !== null) {
                log_message('debug', "convertOrderByToSql: Navigation property detected: {$navigationProperty}.{$nestedProperty}");
                log_message('debug', "convertOrderByToSql: requiredJoins keys: " . implode(', ', array_keys($this->requiredJoins)));
                log_message('debug', "convertOrderByToSql: alias parameter: {$alias}");
                
                // Get join info for navigation property
                $joinInfo = $this->requiredJoins[$navigationProperty] ?? null;
                if ($joinInfo) {
                    log_message('debug', "convertOrderByToSql: Found join info for {$navigationProperty}: " . json_encode($joinInfo));
                    $relatedTableName = $joinInfo['table'];
                    $relatedEntityType = $joinInfo['entityType'];
                    
                    // Get column name from nested property
                    $relatedReflection = new \ReflectionClass($relatedEntityType);
                    $columnName = $this->getColumnNameFromProperty($relatedReflection, $nestedProperty);
                    
                    // Check if this is for final query (alias is subquery alias like 's')
                    // In final query, navigation property columns are aliased in subquery
                    // We need to use the subquery column alias instead of table.column
                    if ($alias === 's' || $alias === $this->context->getTableName($this->entityType)) {
                        // This is for final query - use subquery column alias
                        // Navigation property columns are aliased as {column}{index} in subquery
                        // e.g., Kadro.Name -> Name1 (if Kadro is index 1)
                        $navAlias = $joinInfo['alias'] ?? $navigationProperty;
                        $refIndex = $this->referenceNavIndexes[$navigationProperty] ?? null;
                        
                        if ($refIndex !== null) {
                            // Get primary key column to check if this is the primary key
                            $refPrimaryKeyColumn = $this->getPrimaryKeyColumnName($relatedReflection);
                            
                            if ($columnName === $refPrimaryKeyColumn) {
                                // Primary key is aliased as Id{index} in subquery
                                $subqueryColumnAlias = "Id{$refIndex}";
                            } else {
                                // Other columns are aliased as {column}{index} in subquery
                                $subqueryColumnAlias = "{$columnName}{$refIndex}";
                            }
                            
                            $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
                            $quotedAlias = $provider->escapeIdentifier($alias);
                            $quotedColumnAlias = $provider->escapeIdentifier($subqueryColumnAlias);
                            
                            // Add direction
                            $direction = strtoupper($direction);
                            if ($direction !== 'ASC' && $direction !== 'DESC') {
                                $direction = 'ASC';
                            }
                            
                            $orderBySql = "{$quotedAlias}.{$quotedColumnAlias} {$direction}";
                            log_message('debug', "convertOrderByToSql: Generated ORDER BY SQL for final query: {$orderBySql}");
                            return $orderBySql;
                        }
                    }
                    
                    // This is for main subquery - use JOIN alias instead of table name
                    // JOIN alias is stored in joinInfo['alias'] (e.g., 'k1' for Kadro)
                    $joinAlias = $joinInfo['alias'] ?? null;
                    if ($joinAlias) {
                        $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
                        $quotedJoinAlias = $provider->escapeIdentifier($joinAlias);
                        $quotedColumn = $provider->escapeIdentifier($columnName);
                        
                        // Add direction
                        $direction = strtoupper($direction);
                        if ($direction !== 'ASC' && $direction !== 'DESC') {
                            $direction = 'ASC';
                        }
                        
                        $orderBySql = "{$quotedJoinAlias}.{$quotedColumn} {$direction}";
                        log_message('debug', "convertOrderByToSql: Generated ORDER BY SQL for main subquery using JOIN alias: {$orderBySql}");
                        return $orderBySql;
                    } else {
                        // Fallback to table.column if alias not available
                        $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
                        $quotedTable = $provider->escapeIdentifier($relatedTableName);
                        $quotedColumn = $provider->escapeIdentifier($columnName);
                        
                        // Add direction
                        $direction = strtoupper($direction);
                        if ($direction !== 'ASC' && $direction !== 'DESC') {
                            $direction = 'ASC';
                        }
                        
                        $orderBySql = "{$quotedTable}.{$quotedColumn} {$direction}";
                        log_message('debug', "convertOrderByToSql: Generated ORDER BY SQL for main subquery (fallback to table name): {$orderBySql}");
                        return $orderBySql;
                    }
                } else {
                    // Navigation property not found in requiredJoins
                    // Check if it's a collection navigation property accessed via nested path
                    // e.g., "Department.DepartmentName" where "Department" is accessed via "EmployeeDepartments" collection
                    // In this case, we need to find the collection subquery that contains this navigation
                    log_message('debug', "convertOrderByToSql: No join info found for {$navigationProperty}, checking if it's a collection navigation property");
                    
                    // For final query, check if this navigation property is in a collection subquery
                    if ($alias === 's' || $alias === $this->context->getTableName($this->entityType)) {
                        // Try to find collection subquery that contains this navigation property
                        // Collection subqueries are built in buildEfCoreStyleQuery, but we don't have access here
                        // Instead, we'll use ExpressionParser fallback which should handle this case
                        log_message('debug', "convertOrderByToSql: Final query ORDER BY for collection navigation property, will use ExpressionParser fallback");
                    }
                }
            }
            
            if ($fieldName !== null) {
                // Get column name from property name
                $entityReflection = new \ReflectionClass($this->entityType);
                $columnName = $this->getColumnNameFromProperty($entityReflection, $fieldName);
                
                // Build ORDER BY SQL
                $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
                $quotedAlias = $provider->escapeIdentifier($alias);
                $quotedColumn = $provider->escapeIdentifier($columnName);
                
                // Add direction
                $direction = strtoupper($direction);
                if ($direction !== 'ASC' && $direction !== 'DESC') {
                    $direction = 'ASC';
                }
                
                return "{$quotedAlias}.{$quotedColumn} {$direction}";
            }
            
            // Fallback: Try ExpressionParser (for complex expressions)
            $parser = new ExpressionParser($this->entityType, $alias, $this->context);
            
            $variableValues = [];
            foreach ($staticVariables as $varName => $varValue) {
                if (!is_object($varValue) || !($varValue instanceof \Yakupeyisan\CodeIgniter4\EntityFramework\Core\Entity)) {
                    $variableValues[$varName] = $varValue;
                }
            }
            
            $parser->setVariableValues($variableValues);
            
            // Parse the key selector to get the column name
            $sqlExpression = $parser->parse($keySelector);
            
            if (!empty($sqlExpression)) {
                // Clean up the expression - remove comparison operators if present
                $sqlExpression = preg_replace('/\s*(>|<|>=|<=|==|!=|===|!==)\s*.*$/', '', $sqlExpression);
                $sqlExpression = preg_replace('/\$[a-zA-Z_][a-zA-Z0-9_]*/', '', $sqlExpression);
                $sqlExpression = preg_replace('/\s*->\s*/', ' ', $sqlExpression);
                $sqlExpression = preg_replace('/^->+|->+$/', '', $sqlExpression);
                $sqlExpression = preg_replace('/\s+/', ' ', $sqlExpression);
                $sqlExpression = trim($sqlExpression);
                
                // Replace entity name with alias
                $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
                $quotedAlias = $provider->escapeIdentifier($alias);
                $entityName = (new \ReflectionClass($this->entityType))->getShortName();
                
                // Replace "EntityName.ColumnName" with "[alias].[ColumnName]"
                $sqlExpression = preg_replace_callback('/\b' . preg_quote($entityName, '/') . '\.([a-zA-Z_][a-zA-Z0-9_]*)\b/', function($matches) use ($quotedAlias, $provider) {
                    $columnName = $matches[1];
                    $quotedColumn = $provider->escapeIdentifier($columnName);
                    return "{$quotedAlias}.{$quotedColumn}";
                }, $sqlExpression);
                
                // If expression doesn't contain a dot (no alias), it's just a column name
                // Add alias prefix: "EmployeeID" -> "[alias].[EmployeeID]"
                if (strpos($sqlExpression, '.') === false && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $sqlExpression)) {
                    $quotedColumn = $provider->escapeIdentifier($sqlExpression);
                    $sqlExpression = "{$quotedAlias}.{$quotedColumn}";
                }
                
                // Add direction
                $direction = strtoupper($direction);
                if ($direction !== 'ASC' && $direction !== 'DESC') {
                    $direction = 'ASC';
                }
                
                return "{$sqlExpression} {$direction}";
            }
        } catch (\Exception $e) {
            log_message('debug', 'convertOrderByToSql failed: ' . $e->getMessage());
            log_message('debug', 'Exception trace: ' . $e->getTraceAsString());
        }
        
        return null;
    }

    /**
     * Parse EF Core style flat result set into hierarchical entities
     */
    private function parseEfCoreStyleResults(array $results): array
    {
        if (empty($results)) {
            return [];
        }
        
        // Group results by main entity ID
        $entitiesMap = [];
        $entityReflection = new ReflectionClass($this->entityType);
        $entityColumns = $this->getEntityColumns($entityReflection);
        
        // Get navigation info for reference navigations
        $referenceNavInfo = [];
        $allNavigationPaths = [];
        foreach ($this->wheres as $whereItem) {
            $where = is_array($whereItem) ? $whereItem['predicate'] : $whereItem;
            $paths = $this->detectNavigationPaths($where);
            foreach ($paths as $path) {
                if (!in_array($path, $allNavigationPaths)) {
                    $allNavigationPaths[] = $path;
                }
            }
        }
        foreach ($this->includes as $include) {
            $navPath = $include['path'] ?? $include['navigation'] ?? null;
            if ($navPath !== null && !in_array($navPath, $allNavigationPaths)) {
                $allNavigationPaths[] = $navPath;
            }
        }
        
        // Build reference navigation info map
        $referenceNavIndex = 0;
        foreach ($allNavigationPaths as $navPath) {
            $navInfo = $this->getNavigationInfo($navPath);
            if ($navInfo && !$navInfo['isCollection']) {
                $referenceNavInfo[$navPath] = [
                    'index' => $referenceNavIndex,
                    'entityType' => $navInfo['entityType'],
                    'columns' => $this->getEntityColumns(new ReflectionClass($navInfo['entityType']))
                ];
                $referenceNavIndex++;
            }
        }
        
        // Add nested reference navigation info (thenInclude reference navigations)
        foreach ($this->includes as $include) {
            $navPath = $include['path'] ?? $include['navigation'] ?? null;
            if ($navPath === null) {
                continue;
            }
            $navInfo = $this->getNavigationInfo($navPath);
            if ($navInfo && !$navInfo['isCollection'] && isset($include['thenIncludes'])) {
                // This is a reference navigation with thenIncludes
                $thenIncludeCollectionIndex = 1; // Start at 1 to match alias generation
                foreach ($include['thenIncludes'] as $thenInclude) {
                    $thenNavInfo = $this->getNavigationInfoForEntity($thenInclude, $navInfo['entityType']);
                    if ($thenNavInfo && !$thenNavInfo['isCollection']) {
                        // Nested reference navigation
                        $thenIncludePath = $navPath . '.' . $thenInclude;
                        // Use stored index from SQL building if available, otherwise calculate
                        if (isset($this->referenceNavIndexes[$thenIncludePath])) {
                            $nestedIndex = $this->referenceNavIndexes[$thenIncludePath];
                        } else {
                            // Fallback: calculate nested index: parent index * 100 + thenInclude index
                            $parentIndex = $referenceNavInfo[$navPath]['index'] ?? 0;
                            $nestedIndex = $parentIndex * 100 + $thenIncludeCollectionIndex;
                        }
                        $referenceNavInfo[$thenIncludePath] = [
                            'index' => $nestedIndex,
                            'entityType' => $thenNavInfo['entityType'],
                            'columns' => $this->getEntityColumns(new ReflectionClass($thenNavInfo['entityType'])),
                            'parentPath' => $navPath // Store parent path for nested parsing
                        ];
                        $thenIncludeCollectionIndex++;
                    }
                }
            }
        }
        
        // Get collection navigation info
        $collectionNavInfo = [];
        $collectionIndex = 0;
        foreach ($this->includes as $include) {
            $navPath = $include['path'] ?? $include['navigation'] ?? null;
            if ($navPath === null) {
                continue;
            }
            $navInfo = $this->getNavigationInfo($navPath);
            if ($navInfo && $navInfo['isCollection']) {
                // Get thenIncludes for this collection navigation
                $thenIncludes = [];
                foreach ($this->includes as $include) {
                    $includeNavPath = $include['path'] ?? $include['navigation'] ?? null;
                    if ($includeNavPath === $navPath && isset($include['thenIncludes'])) {
                        $thenIncludes = $include['thenIncludes'];
                        break;
                    }
                }
                
                // Get the actual related entity type from buildCollectionSubquery
                // For collection navigations with join entity, navInfo['entityType'] is the join entity (e.g., UserDepartment)
                // We need to find the actual related entity (e.g., Department) from the join entity
                // If there's no join entity, navInfo['entityType'] is already the related entity
                $joinEntityType = $navInfo['joinEntityType'];
                if ($joinEntityType !== null) {
                    // Has join entity - find the actual related entity from it
                    $actualRelatedEntityType = $this->findRelatedEntityFromJoinEntity($joinEntityType, $navPath);
                    if ($actualRelatedEntityType === null) {
                        // Fallback to navInfo['entityType'] if we can't find it
                        $actualRelatedEntityType = $navInfo['entityType'];
                    }
                } else {
                    // No join entity - navInfo['entityType'] is already the related entity
                    $actualRelatedEntityType = $navInfo['entityType'];
                }
                
                $collectionNavInfo[$navPath] = [
                    'index' => $collectionIndex,
                    'joinEntityType' => $navInfo['joinEntityType'],
                    'entityType' => $actualRelatedEntityType, // Use actual related entity, not join entity
                    'thenIncludes' => $thenIncludes
                ];
                $collectionIndex++;
            }
        }
        
        // Get primary key column name
        $primaryKeyColumn = $this->getPrimaryKeyColumnName($entityReflection);
        log_message('debug', "parseEfCoreStyleResults: Using primary key column '{$primaryKeyColumn}' for entity " . $entityReflection->getName());
        
        // Cache ReflectionClass and entity columns for collection navigation info to avoid recreating them for each row
        $reflectionCache = [];
        $entityColumnsCache = [];
        foreach ($collectionNavInfo as $navPath => $info) {
            if ($info['joinEntityType'] !== null) {
                $reflectionCache[$info['joinEntityType']] = new ReflectionClass($info['joinEntityType']);
                $entityColumnsCache[$info['joinEntityType']] = $this->getEntityColumns($reflectionCache[$info['joinEntityType']]);
            }
            $reflectionCache[$info['entityType']] = new ReflectionClass($info['entityType']);
            $entityColumnsCache[$info['entityType']] = $this->getEntityColumns($reflectionCache[$info['entityType']]);
        }
        
        // Cache ID property accessors for collection items to avoid reflection on each check
        $idPropertyCache = [];
        
        // Cache collection ID maps for fast lookup (per entity, per navigation)
        $collectionIdMaps = [];
        
        // Cache valid columns for related entities to avoid recalculating for each row
        $validColumnsCache = [];
        foreach ($collectionNavInfo as $navPath => $info) {
            $relatedEntityReflection = $reflectionCache[$info['entityType']] ?? new ReflectionClass($info['entityType']);
            $relatedEntityProps = $relatedEntityReflection->getProperties(\ReflectionProperty::IS_PUBLIC);
            $validColumns = [];
            foreach ($relatedEntityProps as $prop) {
                // Only include properties declared in the related entity class itself
                if ($prop->getDeclaringClass()->getName() !== $relatedEntityReflection->getName()) {
                    continue;
                }
                
                // Skip navigation properties
                $docComment = $prop->getDocComment();
                if ($docComment && (preg_match('/@var\s+[A-Za-z_][a-zA-Z0-9_\\\\]*(?:\\\\[A-Za-z_][a-zA-Z0-9_]*)*(\[\])?/', $docComment) || 
                    preg_match('/@var\s+array/', $docComment))) {
                    continue;
                }
                
                // Get column name from property
                $colName = $this->getColumnNameFromProperty($relatedEntityReflection, $prop->getName());
                if ($colName) {
                    $validColumns[] = $colName;
                }
            }
            $validColumnsCache[$info['entityType']] = $validColumns;
        }
        
        foreach ($results as $row) {
            // Extract main entity data
            // Main entity columns are prefixed with 's_' to avoid conflicts
            $entityData = [];
            foreach ($entityColumns as $col) {
                $prefixedCol = 's_' . $col;
                if (isset($row[$prefixedCol])) {
                    $entityData[$col] = $row[$prefixedCol];
                }
            }
            
            // Get entity ID - it's prefixed with 's_' and uses the actual primary key column name
            $prefixedPrimaryKey = 's_' . $primaryKeyColumn;
            $entityId = $row[$prefixedPrimaryKey] ?? null;
            if ($entityId === null) {
                log_message('debug', "parseEfCoreStyleResults: Entity ID is null, looking for '{$prefixedPrimaryKey}'. Row keys: " . implode(', ', array_keys($row)));
                log_message('debug', "parseEfCoreStyleResults: Available prefixed columns: " . implode(', ', array_filter(array_keys($row), function($key) { return str_starts_with($key, 's_'); })));
                continue;
            }
            
            // Create or get entity
            if (!isset($entitiesMap[$entityId])) {
                $entity = $this->mapRowToEntity($entityData, $entityReflection);
                
                // Initialize navigation properties
                foreach ($referenceNavInfo as $navPath => $info) {
                    // For nested navigations (e.g., Employee.CustomField), only initialize parent navigation
                    // Nested navigations will be initialized when parsing parent entity
                    if (str_contains($navPath, '.')) {
                        continue; // Skip nested navigations here, they'll be handled later
                    }
                    $navProperty = $entityReflection->getProperty($navPath);
                    $navProperty->setAccessible(true);
                    $navProperty->setValue($entity, null);
                }
                foreach ($collectionNavInfo as $navPath => $info) {
                    $navProperty = $entityReflection->getProperty($navPath);
                    $navProperty->setAccessible(true);
                    $navProperty->setValue($entity, []);
                }
                
                $entitiesMap[$entityId] = $entity;
            } else {
                $entity = $entitiesMap[$entityId];
            }
            
            // Parse reference navigation properties
            foreach ($referenceNavInfo as $navPath => $info) {
                // Skip nested navigations (e.g., Employee.CustomField) - they'll be parsed after parent navigation
                if (str_contains($navPath, '.')) {
                    continue;
                }
                
                $navProperty = $entityReflection->getProperty($navPath);
                $navProperty->setAccessible(true);
                
                // Check if navigation is already set
                if ($navProperty->getValue($entity) !== null) {
                    continue; // Already set, skip
                }
                
                // Extract reference navigation data using Id{index} pattern
                // Reference navigation columns come from main subquery [s] with prefix s_
                $refIdKey = 's_Id' . $info['index']; // e.g., s_Id0 for Company
                $refId = $row[$refIdKey] ?? null;
                if ($refId !== null) {
                    $refData = [];
                    
                    // Reference navigation columns are prefixed with s_ and suffixed with index in final SELECT
                    // e.g., s_Name0 for Company.Name (index 0), s_Name3 for WebClientAuthorization.Name (index 3)
                    $refEntityReflection = new ReflectionClass($info['entityType']);
                    $refPrimaryKeyColumn = $this->getPrimaryKeyColumnName($refEntityReflection);
                    $refEntityColumns = $this->getEntityColumns($refEntityReflection);
                    $refIndex = $info['index'];
                    
                    // Set primary key using actual column name
                    $refData[$refPrimaryKeyColumn] = $refId;
                    
                    foreach ($refEntityColumns as $col) {
                        if ($col !== $refPrimaryKeyColumn) {
                            // Reference navigation columns are aliased as s_{col}{index} in final SELECT
                            // e.g., s_ProjectID0, s_Name1, s_AuthorizationGroup2, s_Claims3
                            $key = 's_' . $col . $refIndex; // e.g., s_Name0 for Company.Name, s_Name3 for WebClientAuthorization.Name
                            if (isset($row[$key])) {
                                $refData[$col] = $row[$key];
                            }
                        }
                    }
                    
                    // Create related entity
                    $refEntity = $this->mapRowToEntity($refData, $refEntityReflection);
                    $navProperty->setValue($entity, $refEntity);
                    
                    // Parse nested reference navigation properties (thenInclude reference navigations)
                    // Check if this reference navigation has nested reference navigations
                    foreach ($referenceNavInfo as $nestedNavPath => $nestedInfo) {
                        // Check if this is a nested navigation for the current reference navigation
                        if (isset($nestedInfo['parentPath']) && $nestedInfo['parentPath'] === $navPath) {
                            $nestedNavProperty = $refEntityReflection->getProperty(str_replace($navPath . '.', '', $nestedNavPath));
                            $nestedNavProperty->setAccessible(true);
                            
                            // Check if nested navigation is already set
                            if ($nestedNavProperty->getValue($refEntity) !== null) {
                                continue; // Already set, skip
                            }
                            
                            // Extract nested reference navigation data using Id{index} pattern
                            $nestedRefEntityReflection = new ReflectionClass($nestedInfo['entityType']);
                            $nestedRefPrimaryKeyColumn = $this->getPrimaryKeyColumnName($nestedRefEntityReflection);
                            $nestedRefIdKey = 's_Id' . $nestedInfo['index']; // e.g., s_Id1 for Employee.CustomField
                            $nestedRefId = $row[$nestedRefIdKey] ?? null;
                            if ($nestedRefId !== null) {
                                $nestedRefData = [];
                                
                                // Nested reference navigation columns are aliased as s_{col}{index} in final SELECT
                                $nestedRefEntityColumns = $this->getEntityColumns($nestedRefEntityReflection);
                                $nestedRefIndex = $nestedInfo['index'];
                                
                                // Set primary key using actual column name
                                $nestedRefData[$nestedRefPrimaryKeyColumn] = $nestedRefId;
                                
                                foreach ($nestedRefEntityColumns as $col) {
                                    // Skip primary key column (already set above)
                                    if ($col !== $nestedRefPrimaryKeyColumn) {
                                        $key = 's_' . $col . $nestedRefIndex; // e.g., s_CustomField011 for Employee.CustomField.CustomField01
                                        if (isset($row[$key])) {
                                            $nestedRefData[$col] = $row[$key];
                                        }
                                    }
                                }
                                
                                // Create nested related entity
                                $nestedRefEntity = $this->mapRowToEntity($nestedRefData, $nestedRefEntityReflection);
                                $nestedNavProperty->setValue($refEntity, $nestedRefEntity);
                            }
                        }
                    }
                }
            }
            
            // Parse collection navigation properties
            foreach ($collectionNavInfo as $navPath => $info) {
                $navProperty = $entityReflection->getProperty($navPath);
                $navProperty->setAccessible(true);
                $collection = $navProperty->getValue($entity);
                
                // Collection subquery alias (s0, s1, s2, etc.)
                $subqueryAlias = 's' . $info['index'];
                
                // Check if collection item exists in this row
                // CodeIgniter returns columns without prefix, so we need to check for collection-specific columns
                // Collection join entity columns: Id, UserId, DepartmentId (for s0)
                // We can identify collection items by checking if join entity FK columns exist
                $joinEntityType = $info['joinEntityType'];
                $mainEntityShortName = (new ReflectionClass($this->entityType))->getShortName();
                $expectedFkName = $mainEntityShortName . 'Id'; // e.g., EmployeeId
                
                // If there's no join entity, use the related entity directly
                if ($joinEntityType !== null) {
                    $joinEntityReflection = new ReflectionClass($joinEntityType);
                    $joinEntityShortName = $joinEntityReflection->getShortName();
                } else {
                    // No join entity - use related entity directly
                    $relatedEntityType = $info['entityType'];
                    $joinEntityReflection = new ReflectionClass($relatedEntityType);
                    $joinEntityShortName = $joinEntityReflection->getShortName();
                }
                
                // Check if this row has collection data by checking for join entity FK
                if (isset($row[$expectedFkName]) && $row[$expectedFkName] == $entityId) {
                    // This row has collection data - check if collection item ID exists
                    // Collection item ID is in the row directly (from subquery s0, s1, etc.)
                    // But we need to identify which columns belong to which collection
                    // For now, check if join entity columns exist
                    $joinColumns = $this->getEntityColumns($joinEntityReflection);
                    $hasCollectionData = false;
                    foreach ($joinColumns as $col) {
                        if ($col !== $expectedFkName && isset($row[$col]) && $row[$col] !== null) {
                            $hasCollectionData = true;
                            break;
                        }
                    }
                    
                    if ($hasCollectionData) {
                        // Get collection item ID - it should be in the row
                        // For collection subqueries, the Id column from subquery is the join entity Id
                        $collectionId = null;
                        // Try to find collection item ID - it might be in a column that matches join entity pattern
                        // For UserDepartment, we look for DepartmentId or the join entity Id
                        foreach ($joinColumns as $col) {
                            if ($col === 'Id' && isset($row[$col])) {
                                // But this might be main entity Id, so we need to be careful
                                // Actually, collection subquery columns should be prefixed, but CodeIgniter doesn't do that
                                // So we need a different approach
                            }
                        }
                        
                        // For now, use a heuristic: if DepartmentId exists and matches the pattern, it's collection data
                        $relatedEntityReflection = $reflectionCache[$info['entityType']] ?? new ReflectionClass($info['entityType']);
                        $relatedEntityShortName = $relatedEntityReflection->getShortName();
                        $relatedFkName = $relatedEntityShortName . 'Id'; // e.g., DepartmentId
                        
                        if (isset($row[$relatedFkName]) && $row[$relatedFkName] !== null) {
                            $collectionId = $row['Id'] ?? null; // Use main Id as collection item identifier
                        }
                    }
                }
                
                // Check if collection-specific columns exist
                // Collection columns are prefixed with subquery alias (e.g., s0_Id, s0_UserId, s0_DepartmentId)
                // Check if collection join entity Id exists (e.g., s0_Id0) - this indicates collection data
                // For collection subqueries, join entity's Id column is aliased as Id0 in the subquery,
                // so in the final SELECT it appears as s0_Id0
                // If there's no join entity, related entity's Id column is aliased as Id0
                $collectionIdKey = $subqueryAlias . '_Id0'; // e.g., s0_Id0
                $collectionId = $row[$collectionIdKey] ?? null;
                
                // Fallback: if Id0 doesn't exist, try Id (for backward compatibility)
                if ($collectionId === null) {
                    $collectionIdKey = $subqueryAlias . '_Id';
                    $collectionId = $row[$collectionIdKey] ?? null;
                }
                
                if ($collectionId !== null) {
                    // This row has collection data
                    // Check if this item is already in collection
                    // Use a map for O(1) lookup instead of O(n) iteration
                    $exists = false;
                    if (!empty($collection)) {
                        $itemClass = get_class($collection[0]);
                        // Cache ID property accessor for this class
                        if (!isset($idPropertyCache[$itemClass])) {
                            $itemReflection = new ReflectionClass($itemClass);
                            $idProperty = $itemReflection->getProperty('Id');
                            $idProperty->setAccessible(true);
                            $idPropertyCache[$itemClass] = $idProperty;
                        }
                        $idProperty = $idPropertyCache[$itemClass];
                        
                        // Build a map of existing IDs for fast lookup (only once per entity)
                        $mapKey = $entityId . '_' . $navPath;
                        if (!isset($collectionIdMaps[$mapKey])) {
                            $collectionIdMaps[$mapKey] = [];
                            foreach ($collection as $item) {
                                $itemId = $idProperty->getValue($item);
                                $collectionIdMaps[$mapKey][$itemId] = true;
                            }
                        }
                        
                        $exists = isset($collectionIdMaps[$mapKey][$collectionId]);
                    }
                    
                    if (!$exists) {
                        // Extract collection item data - use cached reflections and columns
                        if ($info['joinEntityType'] !== null) {
                            $joinEntityReflection = $reflectionCache[$info['joinEntityType']] ?? new ReflectionClass($info['joinEntityType']);
                            $joinColumns = $entityColumnsCache[$info['joinEntityType']] ?? $this->getEntityColumns($joinEntityReflection);
                        } else {
                            $joinEntityReflection = null;
                            $joinColumns = [];
                        }
                        $relatedEntityReflection = $reflectionCache[$info['entityType']] ?? new ReflectionClass($info['entityType']);
                        $relatedColumns = $entityColumnsCache[$info['entityType']] ?? $this->getEntityColumns($relatedEntityReflection);
                        
                        // If there's no join entity, collection item is the entity itself (e.g., EmployeeDepartment)
                        // If there's a join entity, we need to create both join entity and related entity
                        if ($info['joinEntityType'] === null) {
                            // No join entity - collection item is the entity itself (e.g., EmployeeDepartment)
                            // Collection item Id is aliased as Id0 in subquery
                            $collectionItemIdKey = $subqueryAlias . '_Id0'; // e.g., s0_Id0
                            $collectionItemData = [];
                            if (isset($row[$collectionItemIdKey]) && $row[$collectionItemIdKey] !== null) {
                                $collectionItemData['Id'] = $row[$collectionItemIdKey];
                                
                                // Get collection item columns (e.g., EmployeeDepartment columns)
                                $validColumns = $validColumnsCache[$info['entityType']] ?? [];
                                
                                // Only use columns that are in validColumns list (including Id)
                                foreach ($validColumns as $col) {
                                    if ($col === 'Id') {
                                        // Id is already set above
                                        continue;
                                    }
                                    // Collection item columns are prefixed with collection subquery alias
                                    $key = $subqueryAlias . '_' . $col; // e.g., s0_EmployeeID, s0_DepartmentID
                                    if (isset($row[$key])) {
                                        $collectionItemData[$col] = $row[$key];
                                    }
                                }
                                
                                // Create collection item entity (e.g., EmployeeDepartment)
                                $collectionItem = $this->mapRowToEntity($collectionItemData, $relatedEntityReflection);
                                
                                // Parse reference navigation properties from collection subquery
                                // Collection subquery may have JOINed related entities (e.g., Department)
                                // These are needed for ORDER BY or thenIncludes
                                $collectionItemReflection = new ReflectionClass($info['entityType']);
                                // Get all properties including inherited ones
                                $collectionItemProps = $collectionItemReflection->getProperties();
                                log_message('debug', "parseEfCoreStyleResults: Collection item type: {$info['entityType']}, properties count: " . count($collectionItemProps));
                                foreach ($collectionItemProps as $prop) {
                                    $docComment = $prop->getDocComment();
                                    log_message('debug', "parseEfCoreStyleResults: Checking property '{$prop->getName()}', has docComment: " . ($docComment ? 'yes' : 'no'));
                                    if ($docComment) {
                                        log_message('debug', "parseEfCoreStyleResults: Property '{$prop->getName()}' docComment: " . trim($docComment));
                                    }
                                    if (!$docComment) {
                                        continue;
                                    }
                                    
                                    // Check if this is a reference navigation property (not a collection)
                                    // First check if it's a collection (ends with [])
                                    if (preg_match('/\[\]$/', $docComment)) {
                                        log_message('debug', "parseEfCoreStyleResults: Property '{$prop->getName()}' is a collection, skipping");
                                        continue;
                                    }
                                    
                                    // Extract type from @var annotation
                                    // Pattern: @var \App\Entities\General\Department or @var Department
                                    // Updated regex to handle fully qualified class names starting with \
                                    if (preg_match('/@var\s+((?:\\\\?[A-Za-z_][A-Za-z0-9_]*)(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)/', $docComment, $matches)) {
                                        $navPropType = $matches[1];
                                        log_message('debug', "parseEfCoreStyleResults: Found navigation property '{$prop->getName()}' with type '{$navPropType}'");
                                        
                                        // Check if this navigation property's entity is JOINed in collection subquery
                                        // Related entity primary key column is aliased as Id{EntityName}0 (e.g., IdDepartment0)
                                        // But in final SELECT it's prefixed with subquery alias: s0_IdDepartment0
                                        $navPropShortName = (new ReflectionClass($navPropType))->getShortName();
                                        $relatedIdKey = $subqueryAlias . '_Id' . ucfirst($navPropShortName) . '0'; // e.g., s0_IdDepartment0
                                        
                                        log_message('debug', "parseEfCoreStyleResults: Checking navigation property '{$prop->getName()}' (type: {$navPropType}, shortName: {$navPropShortName}, relatedIdKey: {$relatedIdKey})");
                                        log_message('debug', "parseEfCoreStyleResults: relatedIdKey exists: " . (isset($row[$relatedIdKey]) ? 'yes' : 'no') . ", value: " . ($row[$relatedIdKey] ?? 'null'));
                                        
                                        if (isset($row[$relatedIdKey]) && $row[$relatedIdKey] !== null) {
                                            // Related entity exists in collection subquery - parse it
                                            $navPropReflection = new ReflectionClass($navPropType);
                                            $navPropColumns = $this->getEntityColumns($navPropReflection);
                                            $navPropPrimaryKeyColumn = $this->getPrimaryKeyColumnName($navPropReflection);
                                            
                                            // Get primary key property name from column name
                                            $navPropPrimaryKeyProperty = null;
                                            $navPropAllProps = $navPropReflection->getProperties(\ReflectionProperty::IS_PUBLIC);
                                            foreach ($navPropAllProps as $pkProp) {
                                                $pkColName = $this->getColumnNameFromProperty($navPropReflection, $pkProp->getName());
                                                if ($pkColName === $navPropPrimaryKeyColumn) {
                                                    $navPropPrimaryKeyProperty = $pkProp->getName();
                                                    break;
                                                }
                                            }
                                            
                                            // Initialize navPropData with primary key
                                            // mapRowToEntity expects column names as keys, not property names
                                            $navPropData = [];
                                            if ($navPropPrimaryKeyColumn) {
                                                $navPropData[$navPropPrimaryKeyColumn] = $row[$relatedIdKey];
                                            }
                                            
                                            foreach ($navPropColumns as $col) {
                                                // Skip primary key column (already set above)
                                                if ($col === $navPropPrimaryKeyColumn) {
                                                    continue;
                                                }
                                                // Related entity columns are prefixed with subquery alias
                                                // Format: s0_{ColumnName} (e.g., s0_ProjectID, s0_DepartmentName)
                                                $key = $subqueryAlias . '_' . $col;
                                                if (isset($row[$key])) {
                                                    // mapRowToEntity expects column names as keys
                                                    $navPropData[$col] = $row[$key];
                                                }
                                            }
                                            
                                            log_message('debug', "parseEfCoreStyleResults: Created navPropData for '{$prop->getName()}': " . json_encode(array_keys($navPropData)));
                                            
                                            // Create related entity and set it to collection item's navigation property
                                            $navPropEntity = $this->mapRowToEntity($navPropData, $navPropReflection);
                                            $navPropProperty = $collectionItemReflection->getProperty($prop->getName());
                                            $navPropProperty->setAccessible(true);
                                            $navPropProperty->setValue($collectionItem, $navPropEntity);
                                        }
                                    }
                                }
                                
                                // Parse nested collections (thenInclude) for collection item
                                if (!empty($info['thenIncludes'])) {
                                    $collectionItemType = $info['entityType'];
                                    $this->parseNestedCollections($collectionItem, $collectionItemType, $info['thenIncludes'], $row, $subqueryAlias, $info['index']);
                                }
                                
                                $collection[] = $collectionItem;
                            } else {
                                // Collection item Id not found - skip this row
                                continue;
                            }
                        } else {
                            // Has join entity - create join entity and related entity
                            // Create join entity (e.g., UserDepartment)
                            $joinEntityData = [];
                            foreach ($joinColumns as $col) {
                                $key = $subqueryAlias . '_' . $col;
                                if (isset($row[$key])) {
                                    $joinEntityData[$col] = $row[$key];
                                }
                            }
                            
                            // Create related entity (e.g., Department)
                            $relatedEntityData = [];
                            // Related entity Id is aliased as Id0 in subquery, but in final SELECT it's prefixed
                            $relatedIdKey = $subqueryAlias . '_Id0'; // e.g., s0_Id0
                            if (isset($row[$relatedIdKey]) && $row[$relatedIdKey] !== null) {
                                $relatedEntityData['Id'] = $row[$relatedIdKey];
                                
                                // Get actual related entity columns (exclude join entity columns like UserId, DepartmentId)
                                // Get only properties with Column attribute from related entity (not from parent Entity class)
                                // Use cached valid columns
                                $validColumns = $validColumnsCache[$info['entityType']] ?? [];
                                
                                // Only use columns that are in validColumns list (including Id)
                                // IMPORTANT: Clear relatedEntityData and rebuild it using only validColumns
                                $relatedEntityData = ['Id' => $relatedEntityData['Id']]; // Keep only Id
                                foreach ($validColumns as $col) {
                                    if ($col === 'Id') {
                                        // Id is already set above
                                        continue;
                                    }
                                    // Related entity columns are prefixed with collection subquery alias
                                    $key = $subqueryAlias . '_' . $col; // e.g., s0_Name
                                    if (isset($row[$key])) {
                                        $relatedEntityData[$col] = $row[$key];
                                    }
                                }
                                
                                // Create related entity
                                $relatedEntity = $this->mapRowToEntity($relatedEntityData, $relatedEntityReflection);
                                
                                // Create join entity and set related entity
                                $joinEntity = $this->mapRowToEntity($joinEntityData, $joinEntityReflection);
                                
                                // Find related entity navigation property name in join entity
                                $relatedEntityType = $info['entityType'];
                                $relatedEntityShortName = (new ReflectionClass($relatedEntityType))->getShortName();
                                $relatedNavPropertyName = $relatedEntityShortName; // e.g., Department
                                
                                if ($joinEntityReflection->hasProperty($relatedNavPropertyName)) {
                                    $relatedNavProperty = $joinEntityReflection->getProperty($relatedNavPropertyName);
                                    $relatedNavProperty->setAccessible(true);
                                    $relatedNavProperty->setValue($joinEntity, $relatedEntity);
                                }
                                
                                // Parse nested collections (thenInclude) for related entity
                                if (!empty($info['thenIncludes'])) {
                                    $this->parseNestedCollections($relatedEntity, $relatedEntityType, $info['thenIncludes'], $row, $subqueryAlias, $info['index']);
                                }
                                
                                $collection[] = $joinEntity;
                            } else {
                                // Related entity Id not found, but collection join entity exists
                                // Create join entity without related entity
                                $joinEntity = $this->mapRowToEntity($joinEntityData, $joinEntityReflection);
                                $collection[] = $joinEntity;
                            }
                        }
                        
                        // Update collection ID map
                        $mapKey = $entityId . '_' . $navPath;
                        if (!isset($collectionIdMaps[$mapKey])) {
                            $collectionIdMaps[$mapKey] = [];
                        }
                        $collectionIdMaps[$mapKey][$collectionId] = true;
                        // IMPORTANT: Set collection back to entity's navigation property
                        $navProperty->setValue($entity, $collection);
                    }
                }
            }
        }
        
        // Debug: Log collection counts for each entity
        foreach ($entitiesMap as $entityId => $entity) {
            foreach ($collectionNavInfo as $navPath => $info) {
                $navProperty = $entityReflection->getProperty($navPath);
                $navProperty->setAccessible(true);
                $collection = $navProperty->getValue($entity);
            }
        }
        
        return array_values($entitiesMap);
    }
    
    /**
     * Parse nested collections (thenInclude) for a related entity
     */
    private function parseNestedCollections(object $relatedEntity, string $relatedEntityType, array $thenIncludes, array $row, string $parentSubqueryAlias, int $parentIndex): void
    {
        $relatedEntityReflection = new ReflectionClass($relatedEntityType);
        $nestedSubqueryIndex = $parentIndex + 1; // e.g., if parent is s1, nested starts at s1 (which is the nested subquery alias)
        
        foreach ($thenIncludes as $thenInclude) {
            $thenNavInfo = $this->getNavigationInfoForEntity($thenInclude, $relatedEntityType);
            if (!$thenNavInfo) {
                continue;
            }
            
            // Handle reference navigation (many-to-one or one-to-one)
            if (!$thenNavInfo['isCollection']) {
                // Reference navigation: e.g., Department in UserDepartment
                $navProperty = $relatedEntityReflection->getProperty($thenInclude);
                $navProperty->setAccessible(true);
                
                // The related entity data should already be in the row with prefix from parent subquery
                // For example, if parent is s0 (UserDepartments), Department columns are s0_Id0, s0_Name
                // But we need to check if this is a thenInclude for a reference navigation within a collection
                // In this case, the related entity (Department) is already created in parseEfCoreStyleResults
                // and set to the join entity (UserDepartment). So we don't need to do anything here.
                // Actually, wait - the related entity is already set in parseEfCoreStyleResults at line 2737.
                // So for reference navigation thenIncludes, we don't need to do anything because
                // the related entity is already populated when the collection item is created.
                continue;
            }
            
            $navProperty = $relatedEntityReflection->getProperty($thenInclude);
            $navProperty->setAccessible(true);
            $collection = $navProperty->getValue($relatedEntity) ?? [];
            
            // Nested subquery alias (e.g., s1 for AuthorizationOperationClaims within UserAuthorizations)
            // The nested subquery columns are prefixed with parent subquery alias + nested index
            // For example, if parent is s1 (UserAuthorizations), nested is also s1 (AuthorizationOperationClaims)
            // But the columns are: s1_Id1, s1_AuthorizationId0, s1_OperationClaimId, s1_Id00, s1_Name0, s1_Description0
            $nestedSubqueryAlias = $parentSubqueryAlias; // Same alias as parent (s1)
            
            // Check if nested collection item exists
            // Nested join entity Id: s1_Id1 (from nested subquery)
            $nestedCollectionIdKey = $nestedSubqueryAlias . '_Id' . $nestedSubqueryIndex; // e.g., s1_Id1
            $nestedCollectionId = $row[$nestedCollectionIdKey] ?? null;
            
            if ($nestedCollectionId !== null) {
                // Check if this item is already in collection
                $exists = false;
                foreach ($collection as $item) {
                    $itemReflection = new ReflectionClass($item);
                    $idProperty = $itemReflection->getProperty('Id');
                    $idProperty->setAccessible(true);
                    if ($idProperty->getValue($item) == $nestedCollectionId) {
                        $exists = true;
                        break;
                    }
                }
                
                if (!$exists) {
                    // Extract nested collection item data
                    $nestedJoinEntityType = $thenNavInfo['joinEntityType'];
                    $nestedJoinEntityReflection = new ReflectionClass($nestedJoinEntityType);
                    $nestedJoinColumns = $this->getEntityColumns($nestedJoinEntityReflection);
                    $nestedRelatedEntityType = $thenNavInfo['entityType'];
                    $nestedRelatedEntityReflection = new ReflectionClass($nestedRelatedEntityType);
                    
                    // Create nested join entity (e.g., AuthorizationOperationClaim)
                    $nestedJoinEntityData = [];
                    // Nested join entity columns: s1_Id1, s1_AuthorizationId0, s1_OperationClaimId
                    foreach ($nestedJoinColumns as $col) {
                        if ($col === 'Id') {
                            $key = $nestedSubqueryAlias . '_Id' . $nestedSubqueryIndex; // e.g., s1_Id1
                        } elseif (str_ends_with($col, 'Id') && $col !== 'Id') {
                            // Check if this is the FK to parent entity
                            $parentEntityShortName = (new ReflectionClass($relatedEntityType))->getShortName();
                            if ($col === $parentEntityShortName . 'Id') {
                                $key = $nestedSubqueryAlias . '_' . $col . '0'; // e.g., s1_AuthorizationId0
                            } else {
                                $key = $nestedSubqueryAlias . '_' . $col; // e.g., s1_OperationClaimId
                            }
                        } else {
                            $key = $nestedSubqueryAlias . '_' . $col;
                        }
                        if (isset($row[$key])) {
                            $nestedJoinEntityData[$col] = $row[$key];
                        }
                    }
                    
                    // Create nested related entity (e.g., OperationClaim)
                    $nestedRelatedEntityData = [];
                    // Nested related entity Id: s1_Id00
                    $nestedRelatedIdKey = $nestedSubqueryAlias . '_Id00'; // e.g., s1_Id00
                    if (isset($row[$nestedRelatedIdKey]) && $row[$nestedRelatedIdKey] !== null) {
                        $nestedRelatedEntityData['Id'] = $row[$nestedRelatedIdKey];
                        
                        // Get nested related entity columns
                        $nestedRelatedEntityProps = $nestedRelatedEntityReflection->getProperties();
                        foreach ($nestedRelatedEntityProps as $prop) {
                            $docComment = $prop->getDocComment();
                            if ($docComment && (preg_match('/@var\s+[A-Za-z_][A-Za-z0-9_\\\\]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*(\[\])?/', $docComment) || 
                                preg_match('/@var\s+array/', $docComment))) {
                                continue;
                            }
                            
                            $colName = $this->getColumnNameFromProperty($nestedRelatedEntityReflection, $prop->getName());
                            if ($colName && $colName !== 'Id') {
                                // Nested related entity columns: s1_Name0, s1_Description0
                                $key = $nestedSubqueryAlias . '_' . $colName . '0'; // e.g., s1_Name0
                                if (isset($row[$key])) {
                                    $nestedRelatedEntityData[$colName] = $row[$key];
                                }
                            }
                        }
                        
                        // Create nested related entity
                        $nestedRelatedEntity = $this->mapRowToEntity($nestedRelatedEntityData, $nestedRelatedEntityReflection);
                        
                        // Create nested join entity and set nested related entity
                        $nestedJoinEntity = $this->mapRowToEntity($nestedJoinEntityData, $nestedJoinEntityReflection);
                        
                        // Find nested related entity navigation property name in nested join entity
                        $nestedRelatedEntityShortName = (new ReflectionClass($nestedRelatedEntityType))->getShortName();
                        $nestedRelatedNavPropertyName = $nestedRelatedEntityShortName; // e.g., OperationClaim
                        
                        if ($nestedJoinEntityReflection->hasProperty($nestedRelatedNavPropertyName)) {
                            $nestedRelatedNavProperty = $nestedJoinEntityReflection->getProperty($nestedRelatedNavPropertyName);
                            $nestedRelatedNavProperty->setAccessible(true);
                            $nestedRelatedNavProperty->setValue($nestedJoinEntity, $nestedRelatedEntity);
                        }
                        
                        $collection[] = $nestedJoinEntity;
                        $navProperty->setValue($relatedEntity, $collection);
                    }
                }
            }
        }
    }

    /**
     * Map database row to entity
     */
    private function mapRowToEntity(array $row, ReflectionClass $entityReflection): object
    {
        $entity = $entityReflection->newInstance();
        
        foreach ($entityReflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            
            $columnName = $this->getColumnNameFromProperty($entityReflection, $property->getName());
            if (isset($row[$columnName])) {
                $property->setAccessible(true);
                $value = $row[$columnName];
                
                // Check if property has type and if null is allowed
                $isNullable = false;
                $typeName = null;
                if ($property->hasType()) {
                    $type = $property->getType();
                    if ($type instanceof \ReflectionNamedType) {
                        $typeName = $type->getName();
                        $isNullable = $type->allowsNull();
                    }
                }
                
                // If value is null and property is not nullable, skip setting the property
                // This prevents "must not be accessed before initialization" errors
                if ($value === null && !$isNullable) {
                    log_message('debug', "mapRowToEntity: Skipping null value for non-nullable property {$property->getName()} (type: {$typeName})");
                    continue; // Skip this property, leave it uninitialized
                }
                
                // Type conversion
                if ($typeName !== null) {
                    if ($value === null && $isNullable) {
                        // Null value is allowed, keep it as null
                    } elseif ($typeName === 'int' && $value !== null) {
                        $value = (int)$value;
                    } elseif ($typeName === 'float' && $value !== null) {
                        $value = (float)$value;
                    } elseif ($typeName === 'bool' && $value !== null) {
                        $value = (bool)$value;
                    } elseif ($typeName === 'string' && $value !== null) {
                        $value = (string)$value;
                    } elseif (($typeName === 'DateTime' || $typeName === '\\DateTime' || $typeName === 'DateTimeInterface' || $typeName === '\\DateTimeInterface') && $value !== null) {
                        // Convert string to DateTime
                        if (is_string($value)) {
                            try {
                                $value = new \DateTime($value);
                            } catch (\Exception $e) {
                                log_message('warning', "mapRowToEntity: Failed to convert '{$value}' to DateTime for property {$property->getName()}: " . $e->getMessage());
                                // If property is nullable, set to null, otherwise skip
                                if ($isNullable) {
                                    $value = null;
                                } else {
                                    log_message('debug', "mapRowToEntity: Skipping failed DateTime conversion for non-nullable property {$property->getName()}");
                                    continue;
                                }
                            }
                        } elseif ($value instanceof \DateTime) {
                            // Already a DateTime object, keep it
                        } else {
                            log_message('warning', "mapRowToEntity: Unexpected value type for DateTime property {$property->getName()}: " . gettype($value));
                            if ($isNullable) {
                                $value = null;
                            } else {
                                log_message('debug', "mapRowToEntity: Skipping unexpected DateTime value for non-nullable property {$property->getName()}");
                                continue;
                            }
                        }
                    }
                }
                
                $property->setValue($entity, $value);
            }
        }
        
        return $entity;
    }

    /**
     * Find the actual related entity from join entity for collection navigation
     * e.g., UserDepartment -> Department (not UserDepartment itself)
     */
    private function findRelatedEntityFromJoinEntity(string $joinEntityType, string $navigationProperty): ?string
    {
        $joinEntityReflection = new ReflectionClass($joinEntityType);
        
        // Get the main entity type to exclude it
        $mainEntityShortName = (new ReflectionClass($this->entityType))->getShortName();
        
        // Look for navigation properties in join entity that point to the related entity
        // The related entity is the one that is NOT the main entity
        foreach ($joinEntityReflection->getProperties() as $property) {
            $docComment = $property->getDocComment();
            if (!$docComment) {
                continue;
            }
            
            // Check for navigation property (not array, not main entity)
            if (preg_match('/@var\s+([A-Za-z_][A-Za-z0-9_\\\\]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)(\[\])?/', $docComment, $matches)) {
                $entityType = $matches[1];
                $isCollection = !empty($matches[2]);
                
                // Skip if it's a collection or if it's the main entity
                if ($isCollection) {
                    continue;
                }
                
                // Resolve namespace using use statements
                if ($entityType && !str_starts_with($entityType, '\\')) {
                    $resolved = $this->resolveEntityType($entityType, $joinEntityReflection);
                    if ($resolved !== null) {
                        $entityType = $resolved;
                    }
                }
                
                // Check if this is NOT the main entity
                $entityShortName = (new ReflectionClass($entityType))->getShortName();
                if ($entityShortName !== $mainEntityShortName) {
                    log_message('debug', "findRelatedEntityFromJoinEntity: Found related entity {$entityType} (not main entity {$mainEntityShortName})");
                    return $entityType;
                }
            }
        }
        
        return null;
    }
}

