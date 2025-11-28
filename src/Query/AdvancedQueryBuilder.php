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
    private array $wheres = [];
    private $select = null; // callable|null
    private array $includes = [];
    private array $orderBys = [];
    private ?int $skipCount = null;
    private ?int $takeCount = null;
    private $groupBy = null; // callable|null
    private array $joins = [];
    private array $requiredJoins = []; // Navigation property joins for WHERE clauses
    private bool $isNoTracking = false;
    private bool $isTracking = true;
    private ?string $rawSql = null;
    private array $rawSqlParameters = [];
    private bool $useRawSql = false;

    public function __construct(DbContext $context, string $entityType, BaseConnection $connection)
    {
        $this->context = $context;
        $this->entityType = $entityType;
        $this->connection = $connection;
    }

    /**
     * Add WHERE clause
     */
    public function where(callable $predicate): self
    {
        $this->wheres[] = $predicate;
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
            $result = $this->connection->query($sql, $this->rawSqlParameters);
            $row = $result->getRowArray();
            return (int)($row['count'] ?? 0);
        }

        $tableName = $this->context->getTableName($this->entityType);
        $builder = $this->connection->table($tableName);
        
        // First pass: Detect all navigation property paths
        $allNavigationPaths = [];
        foreach ($this->wheres as $where) {
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
        foreach ($this->wheres as $where) {
            $paths = $this->detectNavigationPaths($where);
            if (!empty($paths)) {
                // Navigation property filter - convert to SQL
                $this->applyNavigationWhereToSql($builder, $where, $paths);
            } else {
                // Simple property filter
            $this->applyWhere($builder, $where);
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
     */
    public function toSql(): string
    {
        if ($this->useRawSql) {
            return $this->rawSql;
        }

        $tableName = $this->context->getTableName($this->entityType);
        $builder = $this->connection->table($tableName);
        
        // Apply where clauses
        foreach ($this->wheres as $where) {
            $this->applyWhere($builder, $where);
        }
        
        // Apply order by
        foreach ($this->orderBys as $orderBy) {
            // Note: CodeIgniter doesn't support callable orderBy directly
            // This would need custom implementation
        }
        
        // Apply skip/take
        if ($this->skipCount !== null) {
            $builder->offset($this->skipCount);
        }
        if ($this->takeCount !== null) {
            $builder->limit($this->takeCount);
        }
        
        return $builder->getCompiledSelect(false);
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

        // Fallback to simple query builder for basic queries
        $tableName = $this->context->getTableName($this->entityType);
        $builder = $this->connection->table($tableName);
        
        // Apply WHERE clauses
        foreach ($this->wheres as $where) {
            $this->applyWhere($builder, $where);
        }
        
        // Apply order by
        foreach ($this->orderBys as $orderBy) {
            $this->applyOrderBy($builder, $orderBy);
        }
        
        // Apply skip/take
        if ($this->skipCount !== null) {
            $builder->offset($this->skipCount);
        }
        if ($this->takeCount !== null) {
            $builder->limit($this->takeCount);
        }
        
        $query = $builder->get();
        $results = $query->getResultArray();
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
        foreach ($this->wheres as $where) {
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
        
        // Execute raw SQL
        $query = $this->connection->query($sql);
        $results = $query->getResultArray();
        
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
     * Execute raw SQL
     */
    private function executeRawSql(): array
    {
        $query = $this->connection->query($this->rawSql, $this->rawSqlParameters);
        $results = $query->getResultArray();
        return $this->mapToEntities($results);
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
     */
    private function applyWhere($builder, callable $predicate): void
    {
        // Try to detect navigation property paths in predicate
        $navigationPaths = $this->detectNavigationPaths($predicate);
        
        if (!empty($navigationPaths)) {
            // Add JOINs for navigation properties
            foreach ($navigationPaths as $path) {
                $this->addJoinForNavigationPath($builder, $path);
            }
            
            // Apply WHERE conditions on joined tables
            $this->applyNavigationWhereToSql($builder, $predicate, $navigationPaths);
        } else {
            // Simple property filter - try to apply directly
        // This is a simplified implementation
        }
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
            
            // Resolve namespace
            if ($relatedEntityType && !str_starts_with($relatedEntityType, '\\')) {
                $currentNamespace = $entityReflection->getNamespaceName();
                $fullyQualified = $currentNamespace . '\\' . $relatedEntityType;
                if (class_exists($fullyQualified)) {
                    $relatedEntityType = $fullyQualified;
                } else {
                    $commonNamespaces = ['App\\Models', 'App\\EntityFramework\\Core'];
                    foreach ($commonNamespaces as $ns) {
                        $fullyQualified = $ns . '\\' . $relatedEntityType;
                        if (class_exists($fullyQualified)) {
                            $relatedEntityType = $fullyQualified;
                            break;
                        }
                    }
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
        // Similar to applyWhere, this would need expression parsing
        // For now, placeholder implementation
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
                
                // If not fully qualified, try to resolve from current entity namespace
                if ($relatedEntityType && !str_starts_with($relatedEntityType, '\\')) {
                    // Try to resolve from same namespace as current entity
                    $currentNamespace = $entityReflection->getNamespaceName();
                    $fullyQualified = $currentNamespace . '\\' . $relatedEntityType;
                    if (class_exists($fullyQualified)) {
                        $relatedEntityType = $fullyQualified;
                    } elseif (class_exists($relatedEntityType)) {
                        // Already a valid class name
                    } else {
                        // Try common namespaces
                        $commonNamespaces = ['App\\Models', 'App\\EntityFramework\\Core'];
                        foreach ($commonNamespaces as $ns) {
                            $fullyQualified = $ns . '\\' . $relatedEntityType;
                            if (class_exists($fullyQualified)) {
                                $relatedEntityType = $fullyQualified;
                                break;
                            }
                        }
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
        $query = $builder->get();
        $relatedResults = $query->getResultArray();
        
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
            $query = $builder->get();
            $relatedResults = $query->getResultArray();
            
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
        $query = $builder->get();
        $relatedResults = $query->getResultArray();
        
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
            
            // If not fully qualified, try to resolve from parent entity namespace
            if ($relatedEntityType && !str_starts_with($relatedEntityType, '\\')) {
                $parentNamespace = $parentReflection->getNamespaceName();
                $fullyQualified = $parentNamespace . '\\' . $relatedEntityType;
                if (class_exists($fullyQualified)) {
                    $relatedEntityType = $fullyQualified;
                } elseif (class_exists($relatedEntityType)) {
                    // Already a valid class name
                } else {
                    // Try common namespaces
                    $commonNamespaces = ['App\\Models', 'App\\EntityFramework\\Core'];
                    foreach ($commonNamespaces as $ns) {
                        $fullyQualified = $ns . '\\' . $relatedEntityType;
                        if (class_exists($fullyQualified)) {
                            $relatedEntityType = $fullyQualified;
                            break;
                        }
                    }
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
            
            if ($this->connection->table($tableName)->where('Id', $id)->update($row)) {
                $updated++;
            }
        }
        
        return $updated;
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
    private function getColumnNameFromProperty(ReflectionClass $entityReflection, string $propertyName): string
    {
        if ($entityReflection->hasProperty($propertyName)) {
            $property = $entityReflection->getProperty($propertyName);
            $attributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Column::class);
            
            if (!empty($attributes)) {
                $columnAttr = $attributes[0]->newInstance();
                if ($columnAttr->name !== null) {
                    return $columnAttr->name;
                }
            }
        }
        
        // Fallback: use property name as-is (SQL Server typically uses PascalCase)
        return $propertyName;
    }

    /**
     * Build EF Core style SQL query with subqueries and JOINs
     * Returns complete SQL string similar to C# EF Core
     */
    private function buildEfCoreStyleQuery(): string
    {
        $tableName = $this->context->getTableName($this->entityType);
        $mainAlias = 'u'; // Main entity alias
        $subqueryAlias = 's'; // Subquery alias
        
        // Detect navigation paths for WHERE clauses
        $navigationFilters = [];
        $allNavigationPaths = [];
        foreach ($this->wheres as $index => $where) {
            $paths = $this->detectNavigationPaths($where);
            if (!empty($paths)) {
                $navigationFilters[$index] = $where;
                foreach ($paths as $path) {
                    if (!in_array($path, $allNavigationPaths)) {
                        $allNavigationPaths[] = $path;
                    }
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
        
        // Get all entity columns
        $entityReflection = new ReflectionClass($this->entityType);
        $entityColumns = $this->getEntityColumns($entityReflection);
        
        // Build main subquery SELECT columns
        $mainSelectColumns = [];
        $columnIndex = 0;
        foreach ($entityColumns as $col) {
            $mainSelectColumns[] = "[{$mainAlias}].[{$col}]";
        }
        
        // Add reference navigation columns (from includes and WHERE filters)
        $referenceNavAliases = [];
        $referenceNavIndex = 0;
        foreach ($allNavigationPaths as $navPath) {
            $navInfo = $this->getNavigationInfo($navPath);
            if ($navInfo && !$navInfo['isCollection']) {
                $refAlias = $this->getTableAlias($navPath, $referenceNavIndex);
                $referenceNavAliases[$navPath] = $refAlias;
                $refEntityReflection = new ReflectionClass($navInfo['entityType']);
                $refColumns = $this->getEntityColumns($refEntityReflection);
                
                // First column gets alias Id0, Id1, etc.
                $firstCol = true;
                foreach ($refColumns as $col) {
                    if ($firstCol && $col === 'Id') {
                        $mainSelectColumns[] = "[{$refAlias}].[{$col}] AS [Id{$referenceNavIndex}]";
                        $firstCol = false;
                    } else {
                        $mainSelectColumns[] = "[{$refAlias}].[{$col}]";
                    }
                }
                $referenceNavIndex++;
            }
        }
        
        // Build main subquery FROM and JOINs
        $mainFrom = "FROM [{$tableName}] AS [{$mainAlias}]";
        $mainJoins = [];
        
        // Add JOINs for reference navigations (many-to-one, one-to-one)
        foreach ($allNavigationPaths as $navPath) {
            $navInfo = $this->getNavigationInfo($navPath);
            if ($navInfo && !$navInfo['isCollection']) {
                $refAlias = $referenceNavAliases[$navPath];
                $refTableName = $this->context->getTableName($navInfo['entityType']);
                $joinCondition = $this->buildJoinCondition($mainAlias, $refAlias, $navPath, $navInfo);
                $joinType = $this->getJoinType($navPath, $navInfo);
                $mainJoins[] = "{$joinType} [{$refTableName}] AS [{$refAlias}] ON {$joinCondition}";
            }
        }
        
        // Build WHERE clause
        $whereConditions = [];
        foreach ($this->wheres as $index => $where) {
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
        $offset = $this->skipCount ?? 0;
        $fetch = $this->takeCount ?? 100;
        $offsetFetch = "OFFSET {$offset} ROWS FETCH NEXT {$fetch} ROWS ONLY";
        
        // Build main subquery
        $mainSubquery = "SELECT " . implode(', ', $mainSelectColumns) . "\n"
            . $mainFrom . "\n"
            . (!empty($mainJoins) ? implode("\n", $mainJoins) . "\n" : '')
            . $whereClause . "\n"
            . "ORDER BY (SELECT 1)\n"
            . $offsetFetch;
        
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
            if ($navInfo && $navInfo['isCollection']) {
                // Check for thenIncludes
                $thenIncludes = $include['thenIncludes'] ?? [];
                $subquery = $this->buildCollectionSubquery($navPath, $navInfo, $collectionIndex, $thenIncludes, $nestedSubqueryIndex);
                if ($subquery) {
                    $collectionSubqueries[] = $subquery;
                    // Update nested subquery index if nested subqueries were added
                    if (isset($subquery['nestedSubqueryIndex'])) {
                        $nestedSubqueryIndex = $subquery['nestedSubqueryIndex'];
                    }
                    $collectionIndex++;
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
                    $firstCol = true;
                    foreach ($refColumns as $col) {
                        if ($firstCol && $col === 'Id') {
                            $mainSelectColumns[] = "[{$refAlias}].[{$col}] AS [Id{$referenceNavIndex}]";
                            $firstCol = false;
                        } else {
                            $mainSelectColumns[] = "[{$refAlias}].[{$col}]";
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
                    $joinType = $this->getJoinType($navPath, $navInfo);
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
        // Only include columns that exist in the main subquery (i.e., were added to allNavigationPaths)
        $refIndex = 0;
        foreach ($referenceNavAliases as $navPath => $refAlias) {
            // Only include if this navigation was added to main subquery
            if (!in_array($navPath, $allNavigationPaths)) {
                continue; // Skip if not in main subquery
            }
            
            $navInfo = $this->getNavigationInfo($navPath);
            if (!$navInfo) {
                continue;
            }
            $refEntityReflection = new ReflectionClass($navInfo['entityType']);
            $refColumns = $this->getEntityColumns($refEntityReflection);
            $firstCol = true;
            foreach ($refColumns as $col) {
                if ($firstCol && $col === 'Id') {
                    $finalSelectColumns[] = "[{$subqueryAlias}].[Id{$refIndex}] AS [s_Id{$refIndex}]";
                    $firstCol = false;
                } else {
                    $finalSelectColumns[] = "[{$subqueryAlias}].[{$col}] AS [s_{$col}]";
                }
            }
            $refIndex++;
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
        $finalQuery = "SELECT " . implode(', ', $finalSelectColumns) . "\n"
            . "FROM (\n"
            . "    " . str_replace("\n", "\n    ", $mainSubquery) . "\n"
            . ") AS [{$subqueryAlias}]";
        
        // Add LEFT JOINs for collection subqueries
        foreach ($collectionSubqueries as $idx => $subquery) {
            $collectionSubqueryAlias = 's' . $idx; // Collection subquery alias (s0, s1, s2, etc.)
            $navPath = $subquery['navigation'];
            
            // Get foreign key column name for join condition
            // The FK in join entity (e.g., UserDepartment.UserId) points to main entity (e.g., User.Id)
            $entityReflection = new ReflectionClass($this->entityType);
            $entityShortName = $entityReflection->getShortName();
            $expectedFkName = $entityShortName . 'Id'; // e.g., UserId
            
            // Get join entity reflection to find the actual FK column name
            $joinEntityType = $subquery['joinEntityType'];
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
            
            // Join condition: main subquery Id = collection subquery FK
            // Main subquery alias is 's', collection subquery alias is 's0', 's1', etc.
            $joinCondition = "[{$subqueryAlias}].[Id] = [{$collectionSubqueryAlias}].[{$fkColumn}]";
            $finalQuery .= "\nLEFT JOIN (\n"
                . "    " . str_replace("\n", "\n    ", $subquery['sql']) . "\n"
                . ") AS [{$collectionSubqueryAlias}] ON {$joinCondition}";
        }
        
        // Add ORDER BY
        // Only include columns that exist in the main subquery
        $orderByColumns = [];
        $orderByColumns[] = "[{$subqueryAlias}].[Id]";
        foreach ($referenceNavAliases as $navPath => $refAlias) {
            $refIndex = array_search($navPath, array_keys($referenceNavAliases));
            if ($refIndex !== false) {
                // Only add if this navigation was included in main subquery
                // Check if it's in allNavigationPaths (which means it was added to main subquery)
                if (in_array($navPath, $allNavigationPaths)) {
                    $orderByColumns[] = "[{$subqueryAlias}].[Id{$refIndex}]";
                }
            }
        }
        foreach ($collectionSubqueries as $idx => $subquery) {
            $collectionSubqueryAlias = 's' . $idx;
            $orderByColumns[] = "[{$collectionSubqueryAlias}].[Id]";
        }
        $finalQuery .= "\nORDER BY " . implode(', ', $orderByColumns);
        
        // Log the generated SQL for debugging
        log_message('debug', 'Generated EF Core Style SQL: ' . $finalQuery);
        
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
            
            // Skip protected/private properties that are not database columns
            // (unless they have Column attribute)
            if ($property->isProtected() || $property->isPrivate()) {
                $attributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Column::class);
                if (empty($attributes)) {
                    continue;
                }
            }
            
            // Skip navigation properties (objects/arrays)
            $docComment = $property->getDocComment();
            if ($docComment && (preg_match('/@var\s+[A-Za-z_][A-Za-z0-9_\\\\]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*(\[\])?/', $docComment) || 
                preg_match('/@var\s+array/', $docComment))) {
                continue;
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
     * Get navigation property info
     */
    private function getNavigationInfo(string $navigationProperty): ?array
    {
        $entityReflection = new ReflectionClass($this->entityType);
        if (!$entityReflection->hasProperty($navigationProperty)) {
            return null;
        }
        
        $navProperty = $entityReflection->getProperty($navigationProperty);
        $navProperty->setAccessible(true);
        $docComment = $navProperty->getDocComment();
        
        $relatedEntityType = null;
        $isCollection = false;
        
        if (preg_match('/@var\s+([A-Za-z_][A-Za-z0-9_\\\\]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)(\[\])?/', $docComment, $matches)) {
            $relatedEntityType = $matches[1];
            $isCollection = !empty($matches[2]);
            
            // Resolve namespace
            if ($relatedEntityType && !str_starts_with($relatedEntityType, '\\')) {
                $currentNamespace = $entityReflection->getNamespaceName();
                $fullyQualified = $currentNamespace . '\\' . $relatedEntityType;
                if (class_exists($fullyQualified)) {
                    $relatedEntityType = $fullyQualified;
                } else {
                    $commonNamespaces = ['App\\Models', 'App\\EntityFramework\\Core'];
                    foreach ($commonNamespaces as $ns) {
                        $fullyQualified = $ns . '\\' . $relatedEntityType;
                        if (class_exists($fullyQualified)) {
                            $relatedEntityType = $fullyQualified;
                            break;
                        }
                    }
                }
            }
        }
        
        if ($relatedEntityType === null) {
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
        
        return [
            'entityType' => $relatedEntityType,
            'isCollection' => $isCollection,
            'foreignKey' => $foreignKey,
            'joinEntityType' => $isCollection ? $this->getJoinEntityType($navigationProperty) : null
        ];
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
            
            // Resolve namespace
            if ($joinEntityType && !str_starts_with($joinEntityType, '\\')) {
                $currentNamespace = $entityReflection->getNamespaceName();
                $fullyQualified = $currentNamespace . '\\' . $joinEntityType;
                if (class_exists($fullyQualified)) {
                    return $fullyQualified;
                } else {
                    $commonNamespaces = ['App\\Models', 'App\\EntityFramework\\Core'];
                    foreach ($commonNamespaces as $ns) {
                        $fullyQualified = $ns . '\\' . $joinEntityType;
                        if (class_exists($fullyQualified)) {
                            return $fullyQualified;
                        }
                    }
                }
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
        return $firstLetter . ($index > 0 ? $index : '');
    }

    /**
     * Build JOIN condition
     */
    private function buildJoinCondition(string $mainAlias, string $refAlias, string $navPath, array $navInfo): string
    {
        $entityReflection = new ReflectionClass($this->entityType);
        $foreignKey = $navInfo['foreignKey'];
        
        // Check if FK is in main entity (many-to-one) or related entity (one-to-one)
        if ($entityReflection->hasProperty($foreignKey)) {
            // Many-to-one: FK in main entity
            $fkColumn = $this->getColumnNameFromProperty($entityReflection, $foreignKey);
            $refIdColumn = 'Id';
            return "[{$mainAlias}].[{$fkColumn}] = [{$refAlias}].[{$refIdColumn}]";
        } else {
            // One-to-one: FK in related entity
            $refEntityReflection = new ReflectionClass($navInfo['entityType']);
            $fkColumn = $this->getColumnNameFromProperty($refEntityReflection, $foreignKey);
            $mainIdColumn = 'Id';
            return "[{$refAlias}].[{$fkColumn}] = [{$mainAlias}].[{$mainIdColumn}]";
        }
    }

    /**
     * Get JOIN type (INNER or LEFT)
     */
    private function getJoinType(string $navPath, array $navInfo): string
    {
        $entityReflection = new ReflectionClass($this->entityType);
        $foreignKey = $navInfo['foreignKey'];
        
        // Many-to-one: INNER JOIN (required relationship)
        if ($entityReflection->hasProperty($foreignKey)) {
            return 'INNER JOIN';
        }
        
        // One-to-one: LEFT JOIN (optional relationship)
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
        if (!$navInfo['isCollection'] || !$navInfo['joinEntityType']) {
            return null;
        }
        
        $joinEntityType = $navInfo['joinEntityType'];
        // For collection navigation, entityType is the join entity (e.g., UserDepartment)
        // We need to find the actual related entity (e.g., Department) from the join entity
        $joinEntityReflection = new ReflectionClass($joinEntityType);
        $relatedEntityType = $this->findRelatedEntityFromJoinEntity($joinEntityType, $navPath);
        
        if ($relatedEntityType === null) {
            log_message('error', "buildCollectionSubquery: Could not determine related entity from join entity {$joinEntityType} for navigation {$navPath}");
            return null;
        }
        
        $joinTableName = $this->context->getTableName($joinEntityType);
        $relatedTableName = $this->context->getTableName($relatedEntityType);
        
        $joinAlias = 'u' . ($index + 1);
        $relatedAlias = $this->getTableAlias($navPath, $index);
        
        // Get columns
        $joinEntityReflection = new ReflectionClass($joinEntityType);
        $joinColumns = $this->getEntityColumns($joinEntityReflection);
        $relatedEntityReflection = new ReflectionClass($relatedEntityType);
        $relatedColumns = $this->getEntityColumns($relatedEntityReflection);
        
        log_message('debug', "buildCollectionSubquery: Join entity: {$joinEntityType}, Related entity: {$relatedEntityType}");
        
        // Build SELECT
        $selectColumns = [];
        foreach ($joinColumns as $col) {
            $selectColumns[] = "[{$joinAlias}].[{$col}]";
        }
        $relatedIdx = 0;
        $firstCol = true;
        foreach ($relatedColumns as $col) {
            if ($firstCol && $col === 'Id') {
                $selectColumns[] = "[{$relatedAlias}].[{$col}] AS [Id{$relatedIdx}]";
                $firstCol = false;
            } else {
                $selectColumns[] = "[{$relatedAlias}].[{$col}]";
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
        $joinCondition = "[{$joinAlias}].[{$joinFkColumn}] = [{$relatedAlias}].[Id]";
        
        // Build nested subqueries for thenIncludes
        $nestedSubqueries = [];
        $nestedSubqueryJoins = [];
        $nestedSelectColumns = [];
        $currentNestedIndex = $nestedSubqueryIndex;
        
        foreach ($thenIncludes as $thenInclude) {
            // Get navigation info for thenInclude (from related entity)
            $thenNavInfo = $this->getNavigationInfoForEntity($thenInclude, $relatedEntityType);
            if ($thenNavInfo && $thenNavInfo['isCollection']) {
                // Build nested subquery
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
            }
        }
        
        // Update nestedSubqueryIndex for next collection subquery
        $nestedSubqueryIndex = $currentNestedIndex;
        
        // Combine all SELECT columns
        $allSelectColumns = array_merge($selectColumns, $nestedSelectColumns);
        
        // Build SQL with nested subqueries
        $sql = "SELECT " . implode(', ', $allSelectColumns) . "\n"
            . "FROM [{$joinTableName}] AS [{$joinAlias}]\n"
            . "INNER JOIN [{$relatedTableName}] AS [{$relatedAlias}] ON {$joinCondition}";
        
        // Add nested subquery LEFT JOINs
        if (!empty($nestedSubqueryJoins)) {
            $sql .= "\n" . implode("\n", $nestedSubqueryJoins);
        }
        
        return [
            'navigation' => $navPath,
            'sql' => $sql,
            'joinEntityType' => $joinEntityType,
            'entityType' => $relatedEntityType,
            'nestedSubqueryIndex' => $nestedSubqueryIndex,
            'selectColumns' => $allSelectColumns
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
        $relatedColumns = $this->getEntityColumns($relatedEntityReflection);
        
        // Build SELECT
        $selectColumns = [];
        foreach ($joinColumns as $col) {
            $selectColumns[] = "[{$joinAlias}].[{$col}]";
        }
        $relatedIdx = 0;
        $firstCol = true;
        foreach ($relatedColumns as $col) {
            if ($firstCol && $col === 'Id') {
                $selectColumns[] = "[{$relatedAlias}].[{$col}] AS [Id{$relatedIdx}]";
                $firstCol = false;
            } else {
                $selectColumns[] = "[{$relatedAlias}].[{$col}]";
            }
        }
        
        // Build JOIN condition
        $relatedEntityShortName = (new ReflectionClass($relatedEntityType))->getShortName();
        $expectedFkName = $relatedEntityShortName . 'Id';
        $joinFk = $expectedFkName;
        
        if ($joinEntityReflection->hasProperty($joinFk)) {
            $joinFkColumn = $this->getColumnNameFromProperty($joinEntityReflection, $joinFk);
            $joinCondition = "[{$joinAlias}].[{$joinFkColumn}] = [{$relatedAlias}].[Id]";
        } else {
            return null;
        }
        
        $sql = "SELECT " . implode(', ', $selectColumns) . "\n"
            . "FROM [{$joinTableName}] AS [{$joinAlias}]\n"
            . "INNER JOIN [{$relatedTableName}] AS [{$relatedAlias}] ON {$joinCondition}";
        
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
                
                // Resolve namespace
                if ($entityType && !str_starts_with($entityType, '\\')) {
                    $currentNamespace = $joinEntityReflection->getNamespaceName();
                    $fullyQualified = $currentNamespace . '\\' . $entityType;
                    if (class_exists($fullyQualified)) {
                        $entityType = $fullyQualified;
                    } else {
                        $commonNamespaces = ['App\\Models', 'App\\EntityFramework\\Core'];
                        foreach ($commonNamespaces as $ns) {
                            $fullyQualified = $ns . '\\' . $entityType;
                            if (class_exists($fullyQualified)) {
                                $entityType = $fullyQualified;
                                break;
                            }
                        }
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
        // Simplified: return null for now, can be enhanced later
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
        foreach ($this->wheres as $where) {
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
                // For collection navigations, navInfo['entityType'] is the join entity (e.g., UserDepartment)
                // We need to find the actual related entity (e.g., Department) from the join entity
                $joinEntityType = $navInfo['joinEntityType'];
                $actualRelatedEntityType = $this->findRelatedEntityFromJoinEntity($joinEntityType, $navPath);
                if ($actualRelatedEntityType === null) {
                    // Fallback to navInfo['entityType'] if we can't find it
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
            
            // Get entity ID - it's prefixed with 's_'
            $entityId = $row['s_Id'] ?? null;
            if ($entityId === null) {
                log_message('debug', 'parseEfCoreStyleResults: Entity ID is null, skipping row. Row keys: ' . implode(', ', array_keys($row)));
                continue;
            }
            
            // Create or get entity
            if (!isset($entitiesMap[$entityId])) {
                $entity = $this->mapRowToEntity($entityData, $entityReflection);
                
                // Initialize navigation properties
                foreach ($referenceNavInfo as $navPath => $info) {
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
                    $refData['Id'] = $refId;
                    
                    // Reference navigation columns are prefixed with s_ in final SELECT
                    $refEntityReflection = new ReflectionClass($info['entityType']);
                    $refEntityColumns = $this->getEntityColumns($refEntityReflection);
                    foreach ($refEntityColumns as $col) {
                        if ($col !== 'Id') {
                            // Reference navigation columns are prefixed with s_ in final SELECT
                            $key = 's_' . $col; // e.g., s_Name for Company.Name
                            if (isset($row[$key])) {
                                $refData[$col] = $row[$key];
                            }
                        }
                    }
                    
                    // Create related entity
                    $refEntity = $this->mapRowToEntity($refData, $refEntityReflection);
                    $navProperty->setValue($entity, $refEntity);
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
                $joinEntityReflection = new ReflectionClass($info['joinEntityType']);
                $joinEntityShortName = $joinEntityReflection->getShortName();
                $mainEntityShortName = (new ReflectionClass($this->entityType))->getShortName();
                $expectedFkName = $mainEntityShortName . 'Id'; // e.g., UserId
                
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
                        $relatedEntityShortName = (new ReflectionClass($info['entityType']))->getShortName();
                        $relatedFkName = $relatedEntityShortName . 'Id'; // e.g., DepartmentId
                        
                        if (isset($row[$relatedFkName]) && $row[$relatedFkName] !== null) {
                            $collectionId = $row['Id'] ?? null; // Use main Id as collection item identifier
                        }
                    }
                }
                
                // Check if collection-specific columns exist
                // Collection columns are prefixed with subquery alias (e.g., s0_Id, s0_UserId, s0_DepartmentId)
                // Check if collection join entity Id exists (e.g., s0_Id) - this indicates collection data
                $collectionIdKey = $subqueryAlias . '_Id';
                $collectionId = $row[$collectionIdKey] ?? null;
                
                // Debug: Log collection detection
                if (isset($row[$collectionIdKey])) {
                    log_message('debug', "parseEfCoreStyleResults: Collection {$navPath} (alias {$subqueryAlias}): collectionIdKey={$collectionIdKey}, value=" . ($row[$collectionIdKey] ?? 'null'));
                }
                
                if ($collectionId !== null) {
                    // This row has collection data
                    // Check if this item is already in collection
                    $exists = false;
                    foreach ($collection as $item) {
                        $itemReflection = new ReflectionClass($item);
                        $idProperty = $itemReflection->getProperty('Id');
                        $idProperty->setAccessible(true);
                        if ($idProperty->getValue($item) == $collectionId) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    log_message('debug', "parseEfCoreStyleResults: Collection {$navPath} (alias {$subqueryAlias}): collectionId={$collectionId}, exists=" . ($exists ? 'true' : 'false') . ", currentCollectionSize=" . count($collection));
                    
                    if (!$exists) {
                        // Extract collection item data
                        $joinEntityReflection = new ReflectionClass($info['joinEntityType']);
                        $joinColumns = $this->getEntityColumns($joinEntityReflection);
                        $relatedEntityReflection = new ReflectionClass($info['entityType']);
                        $relatedColumns = $this->getEntityColumns($relatedEntityReflection);
                        
                        // Create join entity (e.g., UserDepartment)
                        $joinEntityData = [];
                        foreach ($joinColumns as $col) {
                            $key = $subqueryAlias . '_' . $col;
                            if (isset($row[$key])) {
                                $joinEntityData[$col] = $row[$key];
                            }
                        }
                        
                        log_message('debug', "parseEfCoreStyleResults: Collection {$navPath}: joinEntityData=" . json_encode($joinEntityData));
                        
                        // Create related entity (e.g., Department)
                        $relatedEntityData = [];
                        // Related entity Id is aliased as Id0 in subquery, but in final SELECT it's prefixed
                        $relatedIdKey = $subqueryAlias . '_Id0'; // e.g., s0_Id0
                        if (isset($row[$relatedIdKey]) && $row[$relatedIdKey] !== null) {
                            $relatedEntityData['Id'] = $row[$relatedIdKey];
                            
                            // Get actual related entity columns (exclude join entity columns like UserId, DepartmentId)
                            // Get only properties with Column attribute from related entity (not from parent Entity class)
                            // Use getEntityColumns which should filter correctly, but also verify property belongs to related entity
                            $relatedEntityColumns = $this->getEntityColumns($relatedEntityReflection);
                            
                            // Double-check: only include columns that actually exist in the related entity
                            // by checking if property exists in related entity class (not inherited)
                            $relatedEntityProps = $relatedEntityReflection->getProperties(\ReflectionProperty::IS_PUBLIC);
                            $validColumns = [];
                            foreach ($relatedEntityProps as $prop) {
                                // Only include properties declared in the related entity class itself
                                if ($prop->getDeclaringClass()->getName() !== $relatedEntityReflection->getName()) {
                                    continue;
                                }
                                
                                // Skip navigation properties
                                $docComment = $prop->getDocComment();
                                if ($docComment && (preg_match('/@var\s+[A-Za-z_][A-Za-z0-9_\\\\]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*(\[\])?/', $docComment) || 
                                    preg_match('/@var\s+array/', $docComment))) {
                                    continue;
                                }
                                
                                // Get column name from property
                                $colName = $this->getColumnNameFromProperty($relatedEntityReflection, $prop->getName());
                                if ($colName) {
                                    $validColumns[] = $colName;
                                }
                            }
                            
                            log_message('debug', "parseEfCoreStyleResults: Collection {$navPath}: validColumns=" . json_encode($validColumns));
                            
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
                            
                            log_message('debug', "parseEfCoreStyleResults: Collection {$navPath}: relatedEntityData=" . json_encode($relatedEntityData));
                            
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
                            // IMPORTANT: Set collection back to entity's navigation property
                            $navProperty->setValue($entity, $collection);
                            log_message('debug', "parseEfCoreStyleResults: Collection {$navPath}: Added join entity with Id={$collectionId}, newCollectionSize=" . count($collection));
                        } else {
                            // Related entity Id not found, but collection join entity exists
                            log_message('debug', "parseEfCoreStyleResults: Collection {$navPath}: relatedIdKey={$relatedIdKey} not found in row, creating join entity without related entity");
                            // Create join entity without related entity
                            $joinEntity = $this->mapRowToEntity($joinEntityData, $joinEntityReflection);
                            $collection[] = $joinEntity;
                            // IMPORTANT: Set collection back to entity's navigation property
                            $navProperty->setValue($entity, $collection);
                            log_message('debug', "parseEfCoreStyleResults: Collection {$navPath}: Added join entity without related entity, newCollectionSize=" . count($collection));
                        }
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
                if (is_array($collection)) {
                    log_message('debug', "parseEfCoreStyleResults: Entity ID {$entityId}, Navigation {$navPath}: " . count($collection) . " items");
                }
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
                
                // Type conversion
                if ($property->hasType()) {
                    $type = $property->getType();
                    if ($type instanceof \ReflectionNamedType) {
                        $typeName = $type->getName();
                        if ($typeName === 'int' && $value !== null) {
                            $value = (int)$value;
                        } elseif ($typeName === 'float' && $value !== null) {
                            $value = (float)$value;
                        } elseif ($typeName === 'bool' && $value !== null) {
                            $value = (bool)$value;
                        } elseif ($typeName === 'string' && $value !== null) {
                            $value = (string)$value;
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
                
                // Resolve namespace
                if ($entityType && !str_starts_with($entityType, '\\')) {
                    $currentNamespace = $joinEntityReflection->getNamespaceName();
                    $fullyQualified = $currentNamespace . '\\' . $entityType;
                    if (class_exists($fullyQualified)) {
                        $entityType = $fullyQualified;
                    } else {
                        $commonNamespaces = ['App\\Models', 'App\\EntityFramework\\Core'];
                        foreach ($commonNamespaces as $ns) {
                            $fullyQualified = $ns . '\\' . $entityType;
                            if (class_exists($fullyQualified)) {
                                $entityType = $fullyQualified;
                                break;
                            }
                        }
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

