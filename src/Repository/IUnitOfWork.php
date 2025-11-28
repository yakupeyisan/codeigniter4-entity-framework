<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Repository;

/**
 * IUnitOfWork interface - Unit of Work pattern
 * Equivalent to IUnitOfWork in .NET
 */
interface IUnitOfWork
{
    /**
     * Get repository for entity type
     */
    public function getRepository(string $entityType): IRepository;

    /**
     * Save all changes
     */
    public function saveChanges(): int;

    /**
     * Begin transaction
     */
    public function beginTransaction(): bool;

    /**
     * Commit transaction
     */
    public function commit(): bool;

    /**
     * Rollback transaction
     */
    public function rollback(): bool;

    /**
     * Discard changes
     */
    public function discardChanges(): void;
}

