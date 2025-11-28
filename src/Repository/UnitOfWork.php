<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Repository;

use Yakupeyisan\CodeIgniter4\EntityFramework\Core\DbContext;

/**
 * UnitOfWork - Unit of Work pattern implementation
 * Equivalent to UnitOfWork in .NET
 */
class UnitOfWork implements IUnitOfWork
{
    protected DbContext $context;
    protected array $repositories = [];

    public function __construct(DbContext $context)
    {
        $this->context = $context;
    }

    /**
     * Get repository for entity type
     */
    public function getRepository(string $entityType): IRepository
    {
        if (!isset($this->repositories[$entityType])) {
            $this->repositories[$entityType] = new Repository($this->context, $entityType);
        }
        return $this->repositories[$entityType];
    }

    /**
     * Save all changes
     */
    public function saveChanges(): int
    {
        return $this->context->saveChanges();
    }

    /**
     * Begin transaction
     */
    public function beginTransaction(): bool
    {
        return $this->context->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit(): bool
    {
        return $this->context->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback(): bool
    {
        return $this->context->rollback();
    }

    /**
     * Discard changes
     */
    public function discardChanges(): void
    {
        // Clear change tracker
        // Implementation depends on DbContext internals
    }
}

