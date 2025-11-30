<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Repository;

use Yakupeyisan\CodeIgniter4\EntityFramework\Query\IQueryable;

/**
 * IRepository interface - Generic repository pattern
 * Equivalent to IRepository<T> in .NET
 */
interface IRepository
{
    /**
     * Get all entities (IQueryable)
     */
    public function getAll(): IQueryable;

    /**
     * Get all entities without sensitive value masking (IQueryable)
     * Returns unmasked sensitive values
     */
    public function getAllDisableSensitive(): IQueryable;

    /**
     * Get entity by ID
     */
    public function getById(int|string $id): ?object;

    /**
     * Add entity
     */
    public function add(object $entity): void;

    /**
     * Update entity
     */
    public function update(object $entity): void;

    /**
     * Remove entity
     */
    public function remove(object $entity): void;

    /**
     * Remove entity by ID
     */
    public function removeById(int|string $id): void;

    /**
     * Check if entity exists
     */
    public function exists(int|string $id): bool;

    /**
     * Count entities
     */
    public function count(): int;

    /**
     * Add multiple entities (batch add)
     */
    public function addRange(array $entities): void;

    /**
     * Update multiple entities (batch update)
     */
    public function updateRange(array $entities): void;

    /**
     * Remove multiple entities (batch remove)
     */
    public function removeRange(array $entities): void;

    /**
     * Batch insert entities directly to database (bypasses change tracker)
     * Optimized with chunking and transactions
     * 
     * @param array $entities Entities to insert
     * @param int|null $batchSize Optional batch size (default: 1000)
     * @return int Number of inserted entities
     */
    public function batchInsert(array $entities, ?int $batchSize = null): int;

    /**
     * Batch update entities directly to database (bypasses change tracker)
     * Optimized with CASE WHEN statements (MySQL/PostgreSQL) or MERGE (SQL Server)
     * 
     * @param array $entities Entities to update
     * @param int|null $batchSize Optional batch size (default: 1000)
     * @return int Number of updated entities
     */
    public function batchUpdate(array $entities, ?int $batchSize = null): int;

    /**
     * Batch delete entities by IDs directly from database (bypasses change tracker)
     * Optimized with chunking and transactions
     * 
     * @param array $ids Primary key values to delete
     * @param int|null $batchSize Optional batch size (default: 1000)
     * @return int Number of deleted entities
     */
    public function batchDelete(array $ids, ?int $batchSize = null): int;
}

