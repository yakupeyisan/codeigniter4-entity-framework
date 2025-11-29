<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Core;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ConnectionInterface;
use Yakupeyisan\CodeIgniter4\EntityFramework\Query\IQueryable;
use Yakupeyisan\CodeIgniter4\EntityFramework\Query\Queryable;
use Yakupeyisan\CodeIgniter4\EntityFramework\Configuration\EntityTypeBuilder;
use ReflectionClass;

/**
 * DbContext - Main database context class
 * Equivalent to DbContext in EF Core
 */
abstract class DbContext
{
    protected BaseConnection $connection;
    protected array $entityConfigurations = [];
    protected array $trackedEntities = [];
    protected bool $isTransactionActive = false;
    protected array $queryFilters = [];
    protected array $changeTracker = [];
    protected bool $lazyLoadingEnabled = true;
    protected ?\Yakupeyisan\CodeIgniter4\EntityFramework\Core\TransactionManager $transactionManager = null;

    public function __construct(?BaseConnection $connection = null)
    {
        if ($connection === null) {
            // CodeIgniter 4 way to get database connection
            $db = \Config\Database::connect();
            $this->connection = $db;
        } else {
            $this->connection = $connection;
        }
        $this->transactionManager = new \Yakupeyisan\CodeIgniter4\EntityFramework\Core\TransactionManager($this->connection);
        $this->onModelCreating();
    }

    /**
     * Override this method to configure entities using Fluent API
     */
    protected function onModelCreating(): void
    {
        // Override in derived classes
    }

    /**
     * Configure entity using Fluent API
     */
    protected function entity(string $entityType): EntityTypeBuilder
    {
        if (!isset($this->entityConfigurations[$entityType])) {
            $this->entityConfigurations[$entityType] = [];
        }
        return new EntityTypeBuilder($entityType);
    }

    /**
     * Get DbSet for entity type (IQueryable)
     */
    public function set(string $entityType): IQueryable
    {
        return new Queryable($this, $entityType, $this->connection);
    }

    /**
     * Compile query for performance optimization
     * Returns a compiled query that can be reused with different parameters
     * 
     * @param callable $queryBuilder Function that builds the query: fn(DbContext $context, ...$params) => IQueryable
     * @param string|null $cacheKey Optional cache key
     * @return callable Compiled query function
     */
    public function compileQuery(callable $queryBuilder, ?string $cacheKey = null): callable
    {
        return \Yakupeyisan\CodeIgniter4\EntityFramework\Query\CompiledQuery::compile($queryBuilder, $cacheKey);
    }

    /**
     * Get connection
     */
    public function getConnection(): BaseConnection
    {
        return $this->connection;
    }

    /**
     * Begin transaction (supports nested transactions with savepoints)
     * 
     * @param string|null $isolationLevel Optional isolation level (READ UNCOMMITTED, READ COMMITTED, REPEATABLE READ, SERIALIZABLE)
     * @return bool
     */
    public function beginTransaction(?string $isolationLevel = null): bool
    {
        $result = $this->transactionManager->beginTransaction($isolationLevel);
        $this->isTransactionActive = $this->transactionManager->isTransactionActive();
        return $result;
    }

    /**
     * Commit transaction
     */
    public function commit(): bool
    {
        $result = $this->transactionManager->commit();
        $this->isTransactionActive = $this->transactionManager->isTransactionActive();
        return $result;
    }

    /**
     * Rollback transaction
     * 
     * @param string|null $savepointName Optional savepoint name to rollback to
     * @return bool
     */
    public function rollback(?string $savepointName = null): bool
    {
        $result = $this->transactionManager->rollback($savepointName);
        $this->isTransactionActive = $this->transactionManager->isTransactionActive();
        return $result;
    }

    /**
     * Create transaction scope (auto-commit on success, auto-rollback on exception)
     * 
     * @param string|null $isolationLevel Optional isolation level
     * @param int|null $timeout Optional timeout in seconds
     * @return TransactionScope
     */
    public function transactionScope(?string $isolationLevel = null, ?int $timeout = null): \Yakupeyisan\CodeIgniter4\EntityFramework\Core\TransactionScope
    {
        return new \Yakupeyisan\CodeIgniter4\EntityFramework\Core\TransactionScope($this, $isolationLevel, $timeout);
    }

    /**
     * Execute code within a transaction scope
     * Automatically commits on success, rolls back on exception
     * 
     * @param callable $callback Code to execute
     * @param string|null $isolationLevel Optional isolation level
     * @param int|null $timeout Optional timeout in seconds
     * @return mixed Return value of callback
     * @throws \Exception Re-throws any exception from callback
     */
    public function executeInTransaction(callable $callback, ?string $isolationLevel = null, ?int $timeout = null)
    {
        $scope = $this->transactionScope($isolationLevel, $timeout);
        
        try {
            $result = $callback($this);
            $scope->complete();
            return $result;
        } catch (\Exception $e) {
            // Scope destructor will automatically rollback
            throw $e;
        }
    }

    /**
     * Get transaction level (0 = no transaction, 1+ = nested transactions)
     */
    public function getTransactionLevel(): int
    {
        return $this->transactionManager->getTransactionLevel();
    }

    /**
     * Get transaction statistics
     */
    public function getTransactionStatistics(): array
    {
        return $this->transactionManager->getStatistics();
    }

    /**
     * Set transaction isolation level
     */
    public function setTransactionIsolationLevel(string $isolationLevel): void
    {
        $this->transactionManager->setIsolationLevel($isolationLevel);
    }

    /**
     * Save changes (equivalent to SaveChanges in EF Core)
     */
    public function saveChanges(): int
    {
        $changesCount = 0;
        
        foreach ($this->changeTracker as $entity) {
            $state = $entity->getEntityState();
            
            switch ($state) {
                case Entity::STATE_ADDED:
                    $changesCount += $this->insertEntity($entity);
                    break;
                case Entity::STATE_MODIFIED:
                    $changesCount += $this->updateEntity($entity);
                    break;
                case Entity::STATE_DELETED:
                    $changesCount += $this->deleteEntity($entity);
                    break;
            }
        }
        
        $this->changeTracker = [];
        return $changesCount;
    }

    /**
     * Add entity to context
     */
    public function add(object $entity): void
    {
        if ($entity instanceof Entity) {
            $entity->markAsAdded();
            $entity->enableTracking();
            $this->changeTracker[] = $entity;
        }
    }

    /**
     * Update entity in context
     */
    public function update(object $entity): void
    {
        if ($entity instanceof Entity) {
            $entity->markAsModified();
            $entity->enableTracking();
            if (!in_array($entity, $this->changeTracker, true)) {
                $this->changeTracker[] = $entity;
            }
        }
    }

    /**
     * Remove entity from context
     */
    public function remove(object $entity): void
    {
        if ($entity instanceof Entity) {
            $entity->markAsDeleted();
            if (!in_array($entity, $this->changeTracker, true)) {
                $this->changeTracker[] = $entity;
            }
        }
    }

    /**
     * Add multiple entities to context (batch add)
     */
    public function addRange(array $entities): void
    {
        foreach ($entities as $entity) {
            $this->add($entity);
        }
    }

    /**
     * Update multiple entities in context (batch update)
     */
    public function updateRange(array $entities): void
    {
        foreach ($entities as $entity) {
            $this->update($entity);
        }
    }

    /**
     * Remove multiple entities from context (batch remove)
     */
    public function removeRange(array $entities): void
    {
        foreach ($entities as $entity) {
            $this->remove($entity);
        }
    }

    /**
     * Batch insert entities directly to database (bypasses change tracker)
     * Optimized with chunking and transactions
     */
    public function batchInsert(string $entityType, array $entities, ?int $batchSize = null): int
    {
        if (empty($entities)) {
            return 0;
        }

        $tableName = $this->getTableName($entityType);
        $reflection = new ReflectionClass($entityType);
        $data = [];

        foreach ($entities as $entity) {
            $row = [];
            foreach ($reflection->getProperties() as $property) {
                if ($property->isStatic()) {
                    continue;
                }

                $property->setAccessible(true);
                $value = $property->getValue($entity);

                // Skip navigation properties
                if (is_object($value) && !($value instanceof \DateTime) && !($value instanceof \DateTimeInterface)) {
                    continue;
                }

                $columnName = $this->propertyToColumnName($reflection, $property->getName());

                // Skip auto-increment primary keys
                if ($this->isAutoIncrementPrimaryKey($reflection, $property->getName())) {
                    continue;
                }

                $row[$columnName] = $value;
            }
            $data[] = $row;
        }

        if (empty($data)) {
            return 0;
        }

        // Use optimized bulk operations
        $bulkOps = new \Yakupeyisan\CodeIgniter4\EntityFramework\Core\BulkOperations($this->connection);
        if ($batchSize !== null) {
            $bulkOps->setBatchSize($batchSize);
        }
        
        return $bulkOps->batchInsert($tableName, $data);
    }

    /**
     * Batch update entities directly to database (bypasses change tracker)
     * Optimized with CASE WHEN statements (MySQL/PostgreSQL) or MERGE (SQL Server)
     */
    public function batchUpdate(string $entityType, array $entities, ?int $batchSize = null): int
    {
        if (empty($entities)) {
            return 0;
        }

        $tableName = $this->getTableName($entityType);
        $reflection = new ReflectionClass($entityType);
        $primaryKeyName = $this->getPrimaryKeyName($entityType);
        $data = [];

        foreach ($entities as $entity) {
            $row = [];
            $id = null;

            foreach ($reflection->getProperties() as $property) {
                if ($property->isStatic()) {
                    continue;
                }

                $property->setAccessible(true);
                $value = $property->getValue($entity);

                // Skip navigation properties
                if (is_object($value) && !($value instanceof \DateTime) && !($value instanceof \DateTimeInterface)) {
                    continue;
                }

                $columnName = $this->propertyToColumnName($reflection, $property->getName());

                // Get ID for WHERE clause
                if ($this->isPrimaryKey($reflection, $property->getName())) {
                    $id = $value;
                    $row[$primaryKeyName] = $value;
                    continue; // Include PK in row but don't update it
                }

                $row[$columnName] = $value;
            }

            if ($id === null) {
                continue; // Skip entities without ID
            }

            $data[] = $row;
        }

        if (empty($data)) {
            return 0;
        }

        // Use optimized bulk operations
        $bulkOps = new \Yakupeyisan\CodeIgniter4\EntityFramework\Core\BulkOperations($this->connection);
        if ($batchSize !== null) {
            $bulkOps->setBatchSize($batchSize);
        }
        
        // Get column names to update (all except primary key)
        $columns = [];
        if (!empty($data)) {
            $columns = array_keys($data[0]);
            $columns = array_filter($columns, fn($col) => $col !== $primaryKeyName);
        }
        
        return $bulkOps->batchUpdate($tableName, $data, $primaryKeyName, $columns);
    }

    /**
     * Batch delete entities directly from database (bypasses change tracker)
     */
    public function batchDelete(string $entityType, array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $tableName = $this->getTableName($entityType);
        $result = $this->connection->table($tableName)->whereIn('Id', $ids)->delete();
        return $result ? count($ids) : 0;
    }

    /**
     * Attach entity to context
     */
    public function attach(object $entity): void
    {
        if ($entity instanceof Entity) {
            $entity->enableTracking();
            $entity->markAsUnchanged();
        }
    }

    /**
     * Entry method for entity (equivalent to Entry<T> in EF Core)
     */
    public function entry(object $entity): EntityEntry
    {
        return new EntityEntry($this, $entity);
    }

    /**
     * Insert entity
     */
    protected function insertEntity(Entity $entity): int
    {
        $entityType = get_class($entity);
        $tableName = $this->getTableName($entityType);
        
        $reflection = new ReflectionClass($entity);
        $data = [];
        
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            
            $property->setAccessible(true);
            $value = $property->getValue($entity);
            
            // Skip navigation properties
            if (is_object($value) && !($value instanceof \DateTime) && !($value instanceof \DateTimeInterface)) {
                continue;
            }
            
            $columnName = $this->propertyToColumnName($reflection, $property->getName());
            
            // Skip auto-increment primary keys
            if ($this->isAutoIncrementPrimaryKey($reflection, $property->getName())) {
                continue;
            }
            
            $data[$columnName] = $value;
        }
        
        if (empty($data)) {
            return 0;
        }
        
        $result = $this->connection->table($tableName)->insert($data);
        
        if ($result) {
            // Get inserted ID if auto-increment
            $insertId = $this->connection->insertID();
            if ($insertId > 0) {
                $idProperty = $reflection->getProperty('Id');
                $idProperty->setAccessible(true);
                $idProperty->setValue($entity, $insertId);
            }
            return 1;
        }
        
        return 0;
    }

    /**
     * Update entity
     */
    protected function updateEntity(Entity $entity): int
    {
        $entityType = get_class($entity);
        $tableName = $this->getTableName($entityType);
        
        $reflection = new ReflectionClass($entity);
        $data = [];
        $id = null;
        
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            
            $property->setAccessible(true);
            $value = $property->getValue($entity);
            
            // Skip navigation properties
            if (is_object($value) && !($value instanceof \DateTime) && !($value instanceof \DateTimeInterface)) {
                continue;
            }
            
            $columnName = $this->propertyToColumnName($reflection, $property->getName());
            
            // Get ID for WHERE clause
            if ($this->isPrimaryKey($reflection, $property->getName())) {
                $id = $value;
                continue; // Don't update primary key
            }
            
            $data[$columnName] = $value;
        }
        
        if (empty($data) || $id === null) {
            return 0;
        }
        
        $result = $this->connection->table($tableName)->where('Id', $id)->update($data);
        return $result ? 1 : 0;
    }

    /**
     * Delete entity
     */
    protected function deleteEntity(Entity $entity): int
    {
        $entityType = get_class($entity);
        $tableName = $this->getTableName($entityType);
        
        $reflection = new ReflectionClass($entity);
        $idProperty = $reflection->getProperty('Id');
        $idProperty->setAccessible(true);
        $id = $idProperty->getValue($entity);
        
        if ($id === null) {
            return 0;
        }
        
        $result = $this->connection->table($tableName)->where('Id', $id)->delete();
        return $result ? 1 : 0;
    }

    /**
     * Convert property name to column name
     */
    private function propertyToColumnName(ReflectionClass $reflection, string $propertyName): string
    {
        if ($reflection->hasProperty($propertyName)) {
            $property = $reflection->getProperty($propertyName);
            $attributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Column::class);
            
            if (!empty($attributes)) {
                $columnAttr = $attributes[0]->newInstance();
                if ($columnAttr->name !== null) {
                    return $columnAttr->name;
                }
            }
        }
        
        // Default: convert camelCase to PascalCase (keep as is for database)
        return $propertyName;
    }

    /**
     * Check if property is primary key
     */
    private function isPrimaryKey(ReflectionClass $reflection, string $propertyName): bool
    {
        if ($reflection->hasProperty($propertyName)) {
            $property = $reflection->getProperty($propertyName);
            $attributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Key::class);
            return !empty($attributes);
        }
        return false;
    }

    /**
     * Check if property is auto-increment primary key
     */
    private function isAutoIncrementPrimaryKey(ReflectionClass $reflection, string $propertyName): bool
    {
        if (!$this->isPrimaryKey($reflection, $propertyName)) {
            return false;
        }
        
        if ($reflection->hasProperty($propertyName)) {
            $property = $reflection->getProperty($propertyName);
            $attributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\DatabaseGenerated::class);
            
            if (!empty($attributes)) {
                $dbGenAttr = $attributes[0]->newInstance();
                return $dbGenAttr->option === \Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\DatabaseGenerated::IDENTITY;
            }
        }
        
        return false;
    }

    /**
     * Execute raw SQL
     */
    public function executeSqlRaw(string $sql, array $parameters = []): bool
    {
        return $this->connection->query($sql, $parameters);
    }

    /**
     * Execute raw SQL and return results
     */
    public function fromSqlRaw(string $sql, array $parameters = []): array
    {
        $query = $this->connection->query($sql, $parameters);
        return $query->getResultArray();
    }

    /**
     * Get entity configuration
     */
    public function getEntityConfiguration(string $entityType): array
    {
        return $this->entityConfigurations[$entityType] ?? [];
    }

    /**
     * Set query filter for entity type
     */
    public function setQueryFilter(string $entityType, callable $filter): void
    {
        $this->queryFilters[$entityType] = $filter;
    }

    /**
     * Get query filter for entity type
     */
    public function getQueryFilter(string $entityType): ?callable
    {
        return $this->queryFilters[$entityType] ?? null;
    }

    /**
     * Get table name for entity
     */
    public function getTableName(string $entityType): string
    {
        $reflection = new ReflectionClass($entityType);
        $attributes = $reflection->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Table::class);
        
        if (!empty($attributes)) {
            $table = $attributes[0]->newInstance();
            return $table->name;
        }
        
        // Default: pluralize class name
        $className = $reflection->getShortName();
        return strtolower($className) . 's';
    }

    /**
     * Enable lazy loading (default: enabled)
     */
    public function enableLazyLoading(): void
    {
        $this->lazyLoadingEnabled = true;
    }

    /**
     * Disable lazy loading
     */
    public function disableLazyLoading(): void
    {
        $this->lazyLoadingEnabled = false;
    }

    /**
     * Check if lazy loading is enabled
     */
    public function isLazyLoadingEnabled(): bool
    {
        return $this->lazyLoadingEnabled;
    }
}

