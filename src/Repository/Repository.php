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
}

