<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Core;

/**
 * Base entity class for all entities
 * Provides common functionality like change tracking, audit fields, etc.
 */
abstract class Entity
{
    /**
     * Entity state for change tracking
     */
    public const STATE_DETACHED = 'detached';
    public const STATE_UNCHANGED = 'unchanged';
    public const STATE_ADDED = 'added';
    public const STATE_DELETED = 'deleted';
    public const STATE_MODIFIED = 'modified';

    protected string $entityState = self::STATE_DETACHED;
    protected array $originalValues = [];
    protected array $currentValues = [];
    protected array $navigationProperties = [];
    protected bool $isTracking = false;

    /**
     * Get entity state
     */
    public function getEntityState(): string
    {
        return $this->entityState;
    }

    /**
     * Set entity state
     */
    public function setEntityState(string $state): void
    {
        $this->entityState = $state;
    }

    /**
     * Mark entity as added
     */
    public function markAsAdded(): void
    {
        $this->entityState = self::STATE_ADDED;
    }

    /**
     * Mark entity as modified
     */
    public function markAsModified(): void
    {
        if ($this->entityState !== self::STATE_ADDED) {
            $this->entityState = self::STATE_MODIFIED;
        }
    }

    /**
     * Mark entity as deleted
     */
    public function markAsDeleted(): void
    {
        $this->entityState = self::STATE_DELETED;
    }

    /**
     * Mark entity as unchanged
     */
    public function markAsUnchanged(): void
    {
        $this->entityState = self::STATE_UNCHANGED;
        $this->originalValues = $this->getCurrentValues();
    }

    /**
     * Get original values for change tracking
     */
    public function getOriginalValues(): array
    {
        return $this->originalValues;
    }

    /**
     * Set original values
     */
    public function setOriginalValues(array $values): void
    {
        $this->originalValues = $values;
    }

    /**
     * Get current property values
     */
    public function getCurrentValues(): array
    {
        $values = [];
        $reflection = new \ReflectionClass($this);
        foreach ($reflection->getProperties() as $property) {
            if (!$property->isStatic()) {
                $property->setAccessible(true);
                $values[$property->getName()] = $property->getValue($this);
            }
        }
        return $values;
    }

    /**
     * Get changed properties
     */
    public function getChangedProperties(): array
    {
        $changed = [];
        $current = $this->getCurrentValues();
        
        foreach ($current as $key => $value) {
            if (!isset($this->originalValues[$key]) || $this->originalValues[$key] !== $value) {
                $changed[$key] = [
                    'original' => $this->originalValues[$key] ?? null,
                    'current' => $value
                ];
            }
        }
        
        return $changed;
    }

    /**
     * Check if entity has changes
     */
    public function hasChanges(): bool
    {
        return !empty($this->getChangedProperties());
    }

    /**
     * Enable tracking
     */
    public function enableTracking(): void
    {
        $this->isTracking = true;
        $this->originalValues = $this->getCurrentValues();
        $this->entityState = self::STATE_UNCHANGED;
    }

    /**
     * Disable tracking
     */
    public function disableTracking(): void
    {
        $this->isTracking = false;
        $this->entityState = self::STATE_DETACHED;
    }

    /**
     * Check if entity is being tracked
     */
    public function isTracking(): bool
    {
        return $this->isTracking;
    }

    /**
     * Get navigation properties
     */
    public function getNavigationProperties(): array
    {
        return $this->navigationProperties;
    }

    /**
     * Set navigation property
     */
    public function setNavigationProperty(string $name, $value): void
    {
        $this->navigationProperties[$name] = $value;
    }

    /**
     * Magic method to handle property access and change tracking
     */
    public function __set(string $name, $value): void
    {
        if ($this->isTracking && $this->entityState === self::STATE_UNCHANGED) {
            $this->markAsModified();
        }
        $this->$name = $value;
    }
}

