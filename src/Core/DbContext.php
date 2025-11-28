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

    public function __construct(?BaseConnection $connection = null)
    {
        if ($connection === null) {
            // CodeIgniter 4 way to get database connection
            $db = \Config\Database::connect();
            $this->connection = $db;
        } else {
            $this->connection = $connection;
        }
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
     * Get connection
     */
    public function getConnection(): BaseConnection
    {
        return $this->connection;
    }

    /**
     * Begin transaction
     */
    public function beginTransaction(): bool
    {
        $this->isTransactionActive = true;
        return $this->connection->transStart();
    }

    /**
     * Commit transaction
     */
    public function commit(): bool
    {
        $result = $this->connection->transComplete();
        $this->isTransactionActive = false;
        return $result;
    }

    /**
     * Rollback transaction
     */
    public function rollback(): bool
    {
        $result = $this->connection->transRollback();
        $this->isTransactionActive = false;
        return $result;
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
        // Implementation will be handled by AdvancedQueryBuilder
        return 1;
    }

    /**
     * Update entity
     */
    protected function updateEntity(Entity $entity): int
    {
        // Implementation will be handled by AdvancedQueryBuilder
        return 1;
    }

    /**
     * Delete entity
     */
    protected function deleteEntity(Entity $entity): int
    {
        // Implementation will be handled by AdvancedQueryBuilder
        return 1;
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
}

