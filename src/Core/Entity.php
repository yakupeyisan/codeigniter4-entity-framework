<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Core;

/**
 * Base entity class for all entities
 * Provides common functionality like change tracking, audit fields, etc.
 */
abstract class Entity implements \JsonSerializable
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
        log_message('debug', "Marking as modified: " . $this->entityState);
        if ($this->entityState !== self::STATE_ADDED) {
            log_message('debug', "Setting state to modified");
            $this->entityState = self::STATE_MODIFIED;
        }
        log_message('debug', "State after modification: " . $this->entityState);
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
                // Check if property is initialized before accessing it
                // This prevents "must not be accessed before initialization" errors for typed properties
                if ($property->isInitialized($this)) {
                    $values[$property->getName()] = $property->getValue($this);
                } else {
                    // Property is not initialized, use null or default value
                    $values[$property->getName()] = null;
                }
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
        $wasTracking = $this->isTracking;
        $this->isTracking = true;
        
        // Only update originalValues and state if entity was not already being tracked
        // This prevents overwriting changes when enableTracking() is called on an already tracked entity
        if (!$wasTracking) {
            $this->originalValues = $this->getCurrentValues();
            // Only set to unchanged if not already modified/added/deleted
            if ($this->entityState === self::STATE_DETACHED) {
                $this->entityState = self::STATE_UNCHANGED;
            }
        }
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

    /**
     * Magic method to handle property access with lazy loading
     */
    public function __get(string $name)
    {
        // Check if there's a lazy loading proxy for this property
        $proxyKey = '_proxy_' . $name;
        if (isset($this->navigationProperties[$proxyKey])) {
            $proxy = $this->navigationProperties[$proxyKey];
            if ($proxy instanceof \Yakupeyisan\CodeIgniter4\EntityFramework\Core\LazyLoadingProxy) {
                // Load the navigation property
                return $proxy->load();
            }
        }

        // Default behavior - return property value if exists
        if (property_exists($this, $name)) {
            return $this->$name;
        }

        return null;
    }

    /**
     * Check if navigation property is loaded
     */
    public function isNavigationPropertyLoaded(string $name): bool
    {
        $proxyKey = '_proxy_' . $name;
        if (isset($this->navigationProperties[$proxyKey])) {
            $proxy = $this->navigationProperties[$proxyKey];
            if ($proxy instanceof \Yakupeyisan\CodeIgniter4\EntityFramework\Core\LazyLoadingProxy) {
                return $proxy->isLoaded();
            }
        }

        // Check if property has a value (loaded)
        $reflection = new \ReflectionClass($this);
        if ($reflection->hasProperty($name)) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            return $property->getValue($this) !== null;
        }

        return false;
    }

    /**
     * Convert entity to array for API responses
     * Excludes internal tracking properties and formats values properly
     * 
     * @param bool $includeNavigationProperties Whether to include navigation properties (default: true)
     * @return array
     */
    public function toArray(bool $includeNavigationProperties = true): array
    {
        $result = [];
        $reflection = new \ReflectionClass($this);
        
        // Internal properties to exclude
        $excludedProperties = [
            'entityState',
            'originalValues',
            'currentValues',
            'navigationProperties',
            'isTracking'
        ];
        
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            
            $propertyName = $property->getName();
            
            // Skip internal tracking properties
            if (in_array($propertyName, $excludedProperties)) {
                continue;
            }
            
            $property->setAccessible(true);
            
            // Check if property is initialized (for typed properties)
            if (!$property->isInitialized($this)) {
                // For nullable properties, use null; for non-nullable, skip
                if ($property->hasType()) {
                    $type = $property->getType();
                    if ($type instanceof \ReflectionNamedType && $type->allowsNull()) {
                        $result[$propertyName] = null;
                    }
                }
                continue;
            }
            
            $value = $property->getValue($this);
            
            // Handle navigation properties
            if ($includeNavigationProperties) {
                // Check if it's a navigation property (Entity or array of Entities)
                $docComment = $property->getDocComment();
                $isNavigation = false;
                
                if ($docComment) {
                    // Check for entity type in @var
                    if (preg_match('/@var\s+([A-Za-z_][A-Za-z0-9_\\\\]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)(\[\])?/', $docComment, $matches)) {
                        $varType = $matches[1];
                        if (class_exists($varType)) {
                            $varReflection = new \ReflectionClass($varType);
                            if ($varReflection->isSubclassOf(Entity::class)) {
                                $isNavigation = true;
                            }
                        }
                    }
                }
                
                // Also check type hint
                if (!$isNavigation && $property->hasType()) {
                    $type = $property->getType();
                    if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                        $typeName = $type->getName();
                        if (class_exists($typeName)) {
                            $typeReflection = new \ReflectionClass($typeName);
                            if ($typeReflection->isSubclassOf(Entity::class)) {
                                $isNavigation = true;
                            }
                        }
                    }
                }
                
                if ($isNavigation) {
                    // Convert navigation property to array recursively
                    if (is_array($value)) {
                        $result[$propertyName] = array_map(function($item) {
                            return $item instanceof Entity ? $item->toArray() : $item;
                        }, $value);
                    } elseif ($value instanceof Entity) {
                        $result[$propertyName] = $value->toArray();
                    } else {
                        $result[$propertyName] = $value;
                    }
                    continue;
                }
            } else {
                // Skip navigation properties if not including them
                $docComment = $property->getDocComment();
                if ($docComment && preg_match('/@var\s+([A-Za-z_][A-Za-z0-9_\\\\]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)(\[\])?/', $docComment, $matches)) {
                    $varType = $matches[1];
                    if (class_exists($varType)) {
                        $varReflection = new \ReflectionClass($varType);
                        if ($varReflection->isSubclassOf(Entity::class)) {
                            continue; // Skip navigation property
                        }
                    }
                }
            }
            
            // Convert DateTime to string
            if ($value instanceof \DateTime || $value instanceof \DateTimeInterface) {
                $result[$propertyName] = $value->format('Y-m-d H:i:s');
            }
            // Convert arrays recursively
            elseif (is_array($value)) {
                $result[$propertyName] = array_map(function($item) {
                    if ($item instanceof Entity) {
                        return $item->toArray();
                    } elseif ($item instanceof \DateTime || $item instanceof \DateTimeInterface) {
                        return $item->format('Y-m-d H:i:s');
                    }
                    return $item;
                }, $value);
            }
            // Handle proxy objects (lazy loading proxies)
            elseif (is_object($value) && method_exists($value, '__toString')) {
                // Skip proxy objects, they will be loaded when accessed
                $result[$propertyName] = null;
            }
            else {
                $result[$propertyName] = $value;
            }
        }
        
        return $result;
    }

    /**
     * Implement JsonSerializable interface
     * This ensures clean JSON output when entity is json_encode'd
     * 
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

