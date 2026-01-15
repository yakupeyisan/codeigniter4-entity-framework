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
    protected array $pendingLazyLoads = []; // Batch lazy loading queue: ['entityType' => ['navigationProperty' => [Entity1, Entity2, ...]]]

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
            log_message('debug', "Change Tracker: " . json_encode($this->changeTracker, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
            log_message('debug', "Entity: " . json_encode($entity, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
            log_message('debug', "State: " . $state);
            log_message('debug', "Changes Count: " . $changesCount);
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
    public function update(object &$entity): void
    {
        log_message('debug', "Update Entity: " . json_encode($entity, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        if ($entity instanceof Entity) {
            log_message('debug', "Marking as modified");
            $entity->markAsModified();
            log_message('debug', "Marked as modified");
            $entity->enableTracking();
            
            // Check if entity is already in change tracker by ID (not by reference)
            $entityId = $this->getEntityId($entity);
            $alreadyTracked = false;
            
            if ($entityId !== null) {
                foreach ($this->changeTracker as $trackedEntity) {
                    if ($trackedEntity instanceof Entity) {
                        $trackedId = $this->getEntityId($trackedEntity);
                        if ($trackedId !== null && $trackedId === $entityId && get_class($trackedEntity) === get_class($entity)) {
                            // Same entity already tracked, update the reference
                            $index = array_search($trackedEntity, $this->changeTracker, true);
                            if ($index !== false) {
                                $this->changeTracker[$index] = $entity;
                            }
                            $alreadyTracked = true;
                            break;
                        }
                    }
                }
            }
            
            if (!$alreadyTracked && !in_array($entity, $this->changeTracker, true)) {
                log_message('debug', "Adding to change tracker");
                $this->changeTracker[] = $entity;
                log_message('debug', "Added to change tracker");
            }
        }
    }
    
    /**
     * Get entity ID (primary key value)
     */
    private function getEntityId(Entity $entity): mixed
    {
        $entityType = get_class($entity);
        $reflection = new \ReflectionClass($entityType);
        
        // Find primary key property
        foreach ($reflection->getProperties() as $property) {
            if ($this->isPrimaryKey($reflection, $property->getName())) {
                $property->setAccessible(true);
                if ($property->isInitialized($entity)) {
                    return $property->getValue($entity);
                }
                return null;
            }
        }
        
        // Try common primary key names
        $commonNames = ['Id', $reflection->getShortName() . 'Id'];
        foreach ($commonNames as $name) {
            if ($reflection->hasProperty($name)) {
                $property = $reflection->getProperty($name);
                $property->setAccessible(true);
                if ($property->isInitialized($entity)) {
                    return $property->getValue($entity);
                }
            }
        }
        
        return null;
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
        
        // Internal properties to exclude (from Entity base class)
        $excludedProperties = [
            'entityState',
            'originalValues',
            'currentValues',
            'navigationProperties',
            'isTracking'
        ];

        foreach ($entities as $entity) {
            $row = [];
            $id = null;

            foreach ($reflection->getProperties() as $property) {
                if ($property->isStatic()) {
                    continue;
                }
                
                $propertyName = $property->getName();
                
                // Skip internal tracking properties
                if (in_array($propertyName, $excludedProperties)) {
                    continue;
                }

                $property->setAccessible(true);
                $value = $property->getValue($entity);

                // Skip navigation properties (objects, arrays, and properties with InverseProperty attribute)
                if (is_object($value) && !($value instanceof \DateTime) && !($value instanceof \DateTimeInterface)) {
                    continue;
                }

                // Skip array properties (navigation properties are usually arrays)
                if (is_array($value)) {
                    continue;
                }

                // Skip properties with InverseProperty attribute (navigation properties)
                $inversePropertyAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\InverseProperty::class);
                if (!empty($inversePropertyAttributes)) {
                    continue;
                }

                $columnName = $this->propertyToColumnName($reflection, $propertyName);

                // Get ID for WHERE clause
                if ($this->isPrimaryKey($reflection, $propertyName)) {
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
    public function batchDelete(string $entityType, array $ids, ?int $batchSize = null): int
    {
        if (empty($ids)) {
            return 0;
        }

        $tableName = $this->getTableName($entityType);
        $primaryKeyName = $this->getPrimaryKeyName($entityType);
        
        // Use BulkOperations for chunking if batchSize is provided
        if ($batchSize !== null && $batchSize > 0) {
            $bulkOps = new \Yakupeyisan\CodeIgniter4\EntityFramework\Core\BulkOperations($this->connection);
            $bulkOps->setBatchSize($batchSize);
            return $bulkOps->batchDelete($tableName, $ids, $primaryKeyName);
        }
        
        // Fallback to simple delete for small batches
        $result = $this->connection->table($tableName)->whereIn($primaryKeyName, $ids)->delete();
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
        
        // Internal properties to exclude (from Entity base class)
        $excludedProperties = [
            'entityState',
            'originalValues',
            'currentValues',
            'navigationProperties',
            'isTracking'
        ];
        
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            
            $propertyName = $property->getName();
            
            // Skip internal tracking properties
            if (in_array($propertyName, $excludedProperties)) {
                continue;
            }
            
            $property->setAccessible(true);
            
            // Check if property is initialized (for typed properties in PHP 7.4+)
            // Skip if not initialized and it's an auto-increment primary key
            if (!$property->isInitialized($entity)) {
                // For identity columns (auto-increment), skip if not initialized
                if ($this->isAutoIncrementPrimaryKey($reflection, $propertyName)) {
                    continue; // Skip identity columns that are not initialized
                }
                // For other properties, set to null if not initialized
                $value = null;
            } else {
                $value = $property->getValue($entity);
            }
            
            // Check if property type is an Entity class (navigation property)
            $propertyType = $property->getType();
            $isNavigationProperty = false;
            
            if ($propertyType instanceof \ReflectionNamedType) {
                $typeName = $propertyType->getName();
                // Check if type is Entity or extends Entity
                if (class_exists($typeName) && is_subclass_of($typeName, \Yakupeyisan\CodeIgniter4\EntityFramework\Core\Entity::class)) {
                    $isNavigationProperty = true;
                }
            } elseif ($propertyType instanceof \ReflectionUnionType) {
                // For union types (e.g., ?Entity), check if any type is Entity
                foreach ($propertyType->getTypes() as $type) {
                    if ($type instanceof \ReflectionNamedType) {
                        $typeName = $type->getName();
                        if (class_exists($typeName) && is_subclass_of($typeName, \Yakupeyisan\CodeIgniter4\EntityFramework\Core\Entity::class)) {
                            $isNavigationProperty = true;
                            break;
                        }
                    }
                }
            }
            
            // Skip navigation properties (objects, arrays, and properties with InverseProperty attribute)
            if (is_object($value) && !($value instanceof \DateTime) && !($value instanceof \DateTimeInterface)) {
                continue;
            }
            
            // Skip array properties (navigation properties are usually arrays)
            if (is_array($value)) {
                continue;
            }
            
            // Skip properties with InverseProperty attribute (navigation properties)
            $inversePropertyAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\InverseProperty::class);
            if (!empty($inversePropertyAttributes)) {
                continue;
            }
            
            // Skip properties whose type is Entity (navigation properties)
            if ($isNavigationProperty) {
                continue;
            }
            
            $columnName = $this->propertyToColumnName($reflection, $propertyName);
            
            // Skip auto-increment primary keys (double check, but should already be skipped above)
            if ($this->isAutoIncrementPrimaryKey($reflection, $propertyName)) {
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
                // Find primary key property dynamically
                $primaryKeyProperty = null;
                foreach ($reflection->getProperties() as $property) {
                    if ($this->isPrimaryKey($reflection, $property->getName())) {
                        $primaryKeyProperty = $property;
                        break;
                    }
                }
                
                if ($primaryKeyProperty !== null) {
                    $primaryKeyProperty->setAccessible(true);
                    $primaryKeyProperty->setValue($entity, $insertId);
                }
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
        
        // Internal properties to exclude (from Entity base class)
        $excludedProperties = [
            'entityState',
            'originalValues',
            'currentValues',
            'navigationProperties',
            'isTracking'
        ];
        
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            
            $propertyName = $property->getName();
            
            // Skip internal tracking properties
            if (in_array($propertyName, $excludedProperties)) {
                continue;
            }
            
            $property->setAccessible(true);
            
            // Check if property is initialized (for typed properties in PHP 7.4+)
            if (!$property->isInitialized($entity)) {
                // For properties that are not initialized, skip them in update
                // (they should not be updated if they were never set)
                continue;
            }
            
            $value = $property->getValue($entity);
            
            // Check if property type is an Entity class (navigation property)
            $propertyType = $property->getType();
            $isNavigationProperty = false;
            
            if ($propertyType instanceof \ReflectionNamedType) {
                $typeName = $propertyType->getName();
                // Check if type is Entity or extends Entity
                if (class_exists($typeName) && is_subclass_of($typeName, \Yakupeyisan\CodeIgniter4\EntityFramework\Core\Entity::class)) {
                    $isNavigationProperty = true;
                }
            } elseif ($propertyType instanceof \ReflectionUnionType) {
                // For union types (e.g., ?Entity), check if any type is Entity
                foreach ($propertyType->getTypes() as $type) {
                    if ($type instanceof \ReflectionNamedType) {
                        $typeName = $type->getName();
                        if (class_exists($typeName) && is_subclass_of($typeName, \Yakupeyisan\CodeIgniter4\EntityFramework\Core\Entity::class)) {
                            $isNavigationProperty = true;
                            break;
                        }
                    }
                }
            }
            
            // Skip navigation properties (objects, arrays, and properties with InverseProperty attribute)
            if (is_object($value) && !($value instanceof \DateTime) && !($value instanceof \DateTimeInterface)) {
                continue;
            }
            
            // Skip array properties (navigation properties are usually arrays)
            if (is_array($value)) {
                continue;
            }
            
            // Skip properties with InverseProperty attribute (navigation properties)
            $inversePropertyAttributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\InverseProperty::class);
            if (!empty($inversePropertyAttributes)) {
                continue;
            }
            
            // Skip properties whose type is Entity (navigation properties)
            if ($isNavigationProperty) {
                continue;
            }
            
            $columnName = $this->propertyToColumnName($reflection, $propertyName);
            
            // Get ID for WHERE clause
            if ($this->isPrimaryKey($reflection, $propertyName)) {
                $id = $value;
                continue; // Don't update primary key
            }
            
            $data[$columnName] = $value;
        }
        
        if (empty($data) || $id === null) {
            return 0;
        }
        
        $primaryKeyName = $this->getPrimaryKeyName($entityType);
        $result = $this->connection->table($tableName)->where($primaryKeyName, $id)->update($data);
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
        $primaryKeyName = $this->getPrimaryKeyName($entityType);
        
        // Find primary key property dynamically
        $idProperty = null;
        foreach ($reflection->getProperties() as $property) {
            if ($this->isPrimaryKey($reflection, $property->getName())) {
                $idProperty = $property;
                break;
            }
        }
        
        if ($idProperty === null) {
            return 0; // No primary key found
        }
        
        $idProperty->setAccessible(true);
        $id = $idProperty->getValue($entity);
        
        if ($id === null) {
            return 0;
        }
        
        $result = $this->connection->table($tableName)->where($primaryKeyName, $id)->delete();
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
     * Get primary key name (column name) for entity type
     */
    public function getPrimaryKeyName(string $entityType): string
    {
        $reflection = new ReflectionClass($entityType);
        
        // Find primary key property
        foreach ($reflection->getProperties() as $property) {
            if ($this->isPrimaryKey($reflection, $property->getName())) {
                return $this->propertyToColumnName($reflection, $property->getName());
            }
        }
        
        // Fallback: try common primary key names
        $commonNames = ['Id', $reflection->getShortName() . 'Id'];
        foreach ($commonNames as $name) {
            if ($reflection->hasProperty($name)) {
                return $this->propertyToColumnName($reflection, $name);
            }
        }
        
        // Last resort: return 'Id'
        return 'Id';
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
     * Call a table-valued function and return IQueryable
     * 
     * @param string $entityType Entity type to map results to
     * @param string $schema Schema name (e.g., 'dbo')
     * @param string $functionName Function name
     * @param array $parameters Function parameters (associative array: parameter name => value)
     * @return IQueryable Queryable instance for the function results
     * 
     * @example
     * // Call fnCafeteriaSummary function
     * $results = $context->fromFunction(
     *     CafeteriaSummary::class,
     *     'dbo',
     *     'fnCafeteriaSummary',
     *     [
     *         'StartDate' => '2024-01-01 00:00:00',
     *         'EndDate' => '2024-12-31 23:59:59'
     *     ]
     * )->toList();
     */
    public function fromFunction(string $entityType, string $schema, string $functionName, array $parameters = []): IQueryable
    {
        $provider = \Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory::getProvider($this->connection);
        $sql = $provider->getFunctionCallSql($schema, $functionName, $parameters);
        
        // Extract parameter values in order for positional binding
        $paramValues = array_values($parameters);
        
        $queryable = new Queryable($this, $entityType, $this->connection);
        return $queryable->fromSqlRaw($sql, $paramValues);
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

    /**
     * Queue lazy load request for batch processing
     * This helps prevent N+1 query problem by batching multiple lazy load requests
     * 
     * @param Entity $entity Entity that needs lazy loading
     * @param string $navigationProperty Navigation property name
     * @param string $relatedEntityType Related entity type
     * @param string|null $foreignKey Foreign key property name
     * @param bool $isCollection Whether this is a collection navigation
     */
    public function queueLazyLoad(Entity $entity, string $navigationProperty, string $relatedEntityType, ?string $foreignKey = null, bool $isCollection = false): void
    {
        if (!$this->lazyLoadingEnabled) {
            return;
        }

        $entityType = get_class($entity);
        if (!isset($this->pendingLazyLoads[$entityType])) {
            $this->pendingLazyLoads[$entityType] = [];
        }
        if (!isset($this->pendingLazyLoads[$entityType][$navigationProperty])) {
            $this->pendingLazyLoads[$entityType][$navigationProperty] = [
                'entities' => [],
                'relatedEntityType' => $relatedEntityType,
                'foreignKey' => $foreignKey,
                'isCollection' => $isCollection
            ];
        }
        
        $this->pendingLazyLoads[$entityType][$navigationProperty]['entities'][] = $entity;
    }

    /**
     * Execute batch lazy loading for queued requests
     * This processes all queued lazy load requests in batches to reduce database queries
     */
    public function executeBatchLazyLoads(): void
    {
        if (empty($this->pendingLazyLoads)) {
            return;
        }

        foreach ($this->pendingLazyLoads as $entityType => $navigationProperties) {
            foreach ($navigationProperties as $navigationProperty => $loadInfo) {
                $entities = $loadInfo['entities'];
                $relatedEntityType = $loadInfo['relatedEntityType'];
                $foreignKey = $loadInfo['foreignKey'];
                $isCollection = $loadInfo['isCollection'];

                if ($isCollection) {
                    $this->batchLoadCollection($entities, $navigationProperty, $relatedEntityType, $foreignKey);
                } else {
                    $this->batchLoadReference($entities, $navigationProperty, $relatedEntityType, $foreignKey);
                }
            }
        }

        // Clear queue after processing
        $this->pendingLazyLoads = [];
    }

    /**
     * Batch load reference navigation properties
     */
    private function batchLoadReference(array $entities, string $navigationProperty, string $relatedEntityType, ?string $foreignKey): void
    {
        if (empty($entities) || $foreignKey === null) {
            return;
        }

        // Collect all foreign key values
        $fkValues = [];
        $entityMap = []; // Map FK value to entities that need it
        
        $reflection = new \ReflectionClass($entities[0]);
        $fkProperty = $reflection->getProperty($foreignKey);
        $fkProperty->setAccessible(true);

        foreach ($entities as $entity) {
            $fkValue = $fkProperty->getValue($entity);
            if ($fkValue !== null) {
                $fkValues[] = $fkValue;
                if (!isset($entityMap[$fkValue])) {
                    $entityMap[$fkValue] = [];
                }
                $entityMap[$fkValue][] = $entity;
            }
        }

        if (empty($fkValues)) {
            return;
        }

        // Load all related entities in one query
        $primaryKeyName = $this->getPrimaryKeyName($relatedEntityType);
        $query = $this->set($relatedEntityType);
        // Build whereIn condition manually
        $uniqueFkValues = array_unique($fkValues);
        $placeholders = implode(',', array_fill(0, count($uniqueFkValues), '?'));
        $relatedEntities = $query->where("{$primaryKeyName} IN ({$placeholders})", $uniqueFkValues)->toList();

        // Map related entities to their primary keys
        $relatedEntityMap = [];
        $relatedReflection = new \ReflectionClass($relatedEntityType);
        $relatedPkName = $this->getPrimaryKeyName($relatedEntityType);
        $relatedPkProperty = $relatedReflection->getProperty($relatedPkName);
        $relatedPkProperty->setAccessible(true);

        foreach ($relatedEntities as $relatedEntity) {
            $pkValue = $relatedPkProperty->getValue($relatedEntity);
            $relatedEntityMap[$pkValue] = $relatedEntity;
        }

        // Set loaded values to entities
        $navReflection = new \ReflectionClass($entities[0]);
        $navProperty = $navReflection->getProperty($navigationProperty);
        $navProperty->setAccessible(true);

        foreach ($entityMap as $fkValue => $entityList) {
            $relatedEntity = $relatedEntityMap[$fkValue] ?? null;
            foreach ($entityList as $entity) {
                $navProperty->setValue($entity, $relatedEntity);
            }
        }
    }

    /**
     * Batch load collection navigation properties
     */
    private function batchLoadCollection(array $entities, string $navigationProperty, string $relatedEntityType, ?string $foreignKey): void
    {
        if (empty($entities)) {
            return;
        }

        // Get entity type and primary key
        $entityType = get_class($entities[0]);
        $entityReflection = new \ReflectionClass($entityType);
        $primaryKeyName = $this->getPrimaryKeyName($entityType);
        
        // Find primary key property
        $pkProperty = null;
        foreach ($entityReflection->getProperties() as $property) {
            $columnName = $this->getColumnNameFromProperty($entityReflection, $property->getName());
            if ($columnName === $primaryKeyName) {
                $pkProperty = $property;
                $pkProperty->setAccessible(true);
                break;
            }
        }

        if ($pkProperty === null) {
            return;
        }

        // Collect all entity IDs
        $entityIds = [];
        $entityIdMap = []; // Map entity ID to entity instance
        
        foreach ($entities as $entity) {
            $entityId = $pkProperty->getValue($entity);
            if ($entityId !== null) {
                $entityIds[] = $entityId;
                $entityIdMap[$entityId] = $entity;
            }
        }

        if (empty($entityIds)) {
            return;
        }

        // Infer foreign key name if not provided
        if ($foreignKey === null) {
            $entityName = $entityReflection->getShortName();
            $foreignKey = $entityName . 'Id';
        }

        // Load all related entities in one query
        $uniqueEntityIds = array_unique($entityIds);
        $placeholders = implode(',', array_fill(0, count($uniqueEntityIds), '?'));
        $relatedEntities = $this->set($relatedEntityType)
            ->where("{$foreignKey} IN ({$placeholders})", $uniqueEntityIds)
            ->toList();

        // Group related entities by foreign key
        $relatedReflection = new \ReflectionClass($relatedEntityType);
        $fkProperty = $relatedReflection->getProperty($foreignKey);
        $fkProperty->setAccessible(true);
        
        $groupedEntities = [];
        foreach ($relatedEntities as $relatedEntity) {
            $fkValue = $fkProperty->getValue($relatedEntity);
            if (!isset($groupedEntities[$fkValue])) {
                $groupedEntities[$fkValue] = [];
            }
            $groupedEntities[$fkValue][] = $relatedEntity;
        }

        // Set loaded collections to entities
        $navReflection = new \ReflectionClass($entities[0]);
        $navProperty = $navReflection->getProperty($navigationProperty);
        $navProperty->setAccessible(true);

        foreach ($entityIdMap as $entityId => $entity) {
            $navProperty->setValue($entity, $groupedEntities[$entityId] ?? []);
        }
    }

    /**
     * Helper to get column name from property (used in batch loading)
     */
    private function getColumnNameFromProperty(\ReflectionClass $reflection, string $propertyName): string
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
        
        return $propertyName;
    }
}

