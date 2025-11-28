<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Core;

use ReflectionProperty;

/**
 * PropertyEntry - Provides access to property change tracking
 * Equivalent to PropertyEntry in EF Core
 */
class PropertyEntry
{
    private EntityEntry $entityEntry;
    private string $propertyName;

    public function __construct(EntityEntry $entityEntry, string $propertyName)
    {
        $this->entityEntry = $entityEntry;
        $this->propertyName = $propertyName;
    }

    /**
     * Get current value
     */
    public function getCurrentValue()
    {
        $entity = $this->entityEntry->getEntity();
        $reflection = new ReflectionProperty($entity, $this->propertyName);
        $reflection->setAccessible(true);
        return $reflection->getValue($entity);
    }

    /**
     * Get original value
     */
    public function getOriginalValue()
    {
        $entity = $this->entityEntry->getEntity();
        if ($entity instanceof Entity) {
            $original = $entity->getOriginalValues();
            return $original[$this->propertyName] ?? null;
        }
        return null;
    }

    /**
     * Set current value
     */
    public function setCurrentValue($value): void
    {
        $entity = $this->entityEntry->getEntity();
        $reflection = new ReflectionProperty($entity, $this->propertyName);
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $value);
        
        if ($entity instanceof Entity && $entity->isTracking()) {
            $entity->markAsModified();
        }
    }

    /**
     * Check if property is modified
     */
    public function isModified(): bool
    {
        return $this->getCurrentValue() !== $this->getOriginalValue();
    }
}

