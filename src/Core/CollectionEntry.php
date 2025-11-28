<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Core;

/**
 * CollectionEntry - Provides access to collection navigation properties
 * Equivalent to CollectionEntry in EF Core
 */
class CollectionEntry
{
    private EntityEntry $entityEntry;
    private string $propertyName;

    public function __construct(EntityEntry $entityEntry, string $propertyName)
    {
        $this->entityEntry = $entityEntry;
        $this->propertyName = $propertyName;
    }

    /**
     * Load collection (explicit loading)
     */
    public function load(): void
    {
        // Implementation for explicit loading
    }

    /**
     * Check if collection is loaded
     */
    public function isLoaded(): bool
    {
        // Implementation
        return false;
    }

    /**
     * Get current value
     */
    public function getCurrentValue(): array
    {
        $entity = $this->entityEntry->getEntity();
        $reflection = new \ReflectionProperty($entity, $this->propertyName);
        $reflection->setAccessible(true);
        $value = $reflection->getValue($entity);
        return is_array($value) ? $value : [];
    }
}

