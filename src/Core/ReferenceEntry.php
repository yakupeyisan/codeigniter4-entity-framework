<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Core;

/**
 * ReferenceEntry - Provides access to reference navigation properties
 * Equivalent to ReferenceEntry in EF Core
 */
class ReferenceEntry
{
    private EntityEntry $entityEntry;
    private string $propertyName;

    public function __construct(EntityEntry $entityEntry, string $propertyName)
    {
        $this->entityEntry = $entityEntry;
        $this->propertyName = $propertyName;
    }

    /**
     * Load reference (explicit loading)
     */
    public function load(): void
    {
        // Implementation for explicit loading
    }

    /**
     * Check if reference is loaded
     */
    public function isLoaded(): bool
    {
        // Implementation
        return false;
    }

    /**
     * Get current value
     */
    public function getCurrentValue()
    {
        $entity = $this->entityEntry->getEntity();
        $reflection = new \ReflectionProperty($entity, $this->propertyName);
        $reflection->setAccessible(true);
        return $reflection->getValue($entity);
    }
}

