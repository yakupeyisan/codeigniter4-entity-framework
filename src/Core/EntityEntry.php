<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Core;

/**
 * EntityEntry - Provides access to change tracking information
 * Equivalent to EntityEntry<TEntity> in EF Core
 */
class EntityEntry
{
    private DbContext $context;
    private object $entity;

    public function __construct(DbContext $context, object $entity)
    {
        $this->context = $context;
        $this->entity = $entity;
    }

    /**
     * Get entity
     */
    public function getEntity(): object
    {
        return $this->entity;
    }

    /**
     * Get entity state
     */
    public function getState(): string
    {
        if ($this->entity instanceof Entity) {
            return $this->entity->getEntityState();
        }
        return Entity::STATE_DETACHED;
    }

    /**
     * Set entity state
     */
    public function setState(string $state): void
    {
        if ($this->entity instanceof Entity) {
            $this->entity->setEntityState($state);
        }
    }

    /**
     * Get property entry
     */
    public function property(string $propertyName): PropertyEntry
    {
        return new PropertyEntry($this, $propertyName);
    }

    /**
     * Get collection entry
     */
    public function collection(string $propertyName): CollectionEntry
    {
        return new CollectionEntry($this, $propertyName);
    }

    /**
     * Get reference entry
     */
    public function reference(string $propertyName): ReferenceEntry
    {
        return new ReferenceEntry($this, $propertyName);
    }

    /**
     * Get context
     */
    public function getContext(): DbContext
    {
        return $this->context;
    }

    /**
     * Reload entity from database
     */
    public function reload(): void
    {
        // Implementation for reloading entity
    }
}

