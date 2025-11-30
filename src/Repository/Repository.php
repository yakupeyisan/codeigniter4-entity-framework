<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Repository;

use Yakupeyisan\CodeIgniter4\EntityFramework\Core\DbContext;
use Yakupeyisan\CodeIgniter4\EntityFramework\Query\IQueryable;

/**
 * Repository - Generic repository implementation
 * Equivalent to Repository<T> in .NET
 */
class Repository implements IRepository
{
    protected DbContext $context;
    protected string $entityType;

    public function __construct(DbContext $context, string $entityType)
    {
        $this->context = $context;
        $this->entityType = $entityType;
    }

    /**
     * Get all entities
     */
    public function getAll(): IQueryable
    {
        return $this->context->set($this->entityType);
    }

    /**
     * Get all entities without sensitive value masking
     * Returns unmasked sensitive values (bypasses SensitiveValue attribute)
     */
    public function getAllDisableSensitive(): IQueryable
    {
        return $this->context->set($this->entityType)->disableSensitive();
    }

    /**
     * Get entity by ID
     */
    public function getById(int|string $id): ?object
    {
        return $this->context->set($this->entityType)
            ->where(fn($e) => $e->Id === $id)
            ->firstOrDefault();
    }

    /**
     * Add entity
     */
    public function add(object $entity): void
    {
        $this->context->add($entity);
    }

    /**
     * Update entity
     */
    public function update(object $entity): void
    {
        $this->context->update($entity);
    }

    /**
     * Remove entity
     */
    public function remove(object $entity): void
    {
        $this->context->remove($entity);
    }

    /**
     * Remove entity by ID
     */
    public function removeById(int|string $id): void
    {
        $entity = $this->getById($id);
        if ($entity !== null) {
            $this->remove($entity);
        }
    }

    /**
     * Check if entity exists
     */
    public function exists(int|string $id): bool
    {
        return $this->context->set($this->entityType)
            ->where(fn($e) => $e->Id === $id)
            ->any();
    }

    /**
     * Count entities
     */
    public function count(): int
    {
        return $this->context->set($this->entityType)->count();
    }

    /**
     * Add multiple entities (batch add)
     */
    public function addRange(array $entities): void
    {
        foreach ($entities as $entity) {
            $this->add($entity);
        }
    }

    /**
     * Update multiple entities (batch update)
     */
    public function updateRange(array $entities): void
    {
        foreach ($entities as $entity) {
            $this->update($entity);
        }
    }

    /**
     * Remove multiple entities (batch remove)
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
     * 
     * @param array $entities Entities to insert
     * @param int|null $batchSize Optional batch size (default: 1000)
     * @return int Number of inserted entities
     */
    public function batchInsert(array $entities, ?int $batchSize = null): int
    {
        return $this->context->batchInsert($this->entityType, $entities, $batchSize);
    }

    /**
     * Batch update entities directly to database (bypasses change tracker)
     * Optimized with CASE WHEN statements (MySQL/PostgreSQL) or MERGE (SQL Server)
     * 
     * @param array $entities Entities to update
     * @param int|null $batchSize Optional batch size (default: 1000)
     * @return int Number of updated entities
     */
    public function batchUpdate(array $entities, ?int $batchSize = null): int
    {
        return $this->context->batchUpdate($this->entityType, $entities, $batchSize);
    }

    /**
     * Batch delete entities by IDs directly from database (bypasses change tracker)
     * Optimized with chunking and transactions
     * 
     * @param array $ids Primary key values to delete
     * @param int|null $batchSize Optional batch size (default: 1000)
     * @return int Number of deleted entities
     */
    public function batchDelete(array $ids, ?int $batchSize = null): int
    {
        return $this->context->batchDelete($this->entityType, $ids, $batchSize);
    }
}

