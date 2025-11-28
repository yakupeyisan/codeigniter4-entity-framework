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
}

