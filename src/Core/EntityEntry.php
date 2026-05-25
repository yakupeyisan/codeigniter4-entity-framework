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
        if (!$this->entity instanceof Entity) {
            return; // Can only reload Entity instances
        }
        
        $entityType = get_class($this->entity);
        $reflection = new \ReflectionClass($entityType);
        
        // Find primary key property
        $primaryKeyProperty = null;
        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Key::class);
            if (!empty($attributes)) {
                $primaryKeyProperty = $property;
                break;
            }
        }
        
        if ($primaryKeyProperty === null) {
            // Try common primary key names
            $commonNames = ['Id', $reflection->getShortName() . 'Id'];
            foreach ($commonNames as $name) {
                if ($reflection->hasProperty($name)) {
                    $primaryKeyProperty = $reflection->getProperty($name);
                    break;
                }
            }
        }
        
        if ($primaryKeyProperty === null) {
            $exceptionClass = class_exists('\Yakupeyisan\CodeIgniter4\EntityFramework\Exceptions\InvalidOperationException') 
                ? '\Yakupeyisan\CodeIgniter4\EntityFramework\Exceptions\InvalidOperationException'
                : \RuntimeException::class;
            throw new $exceptionClass("Cannot reload entity: Primary key not found for {$entityType}");
        }
        
        $id = $primaryKeyProperty->getValue($this->entity);
        
        if ($id === null) {
            $exceptionClass = class_exists('\Yakupeyisan\CodeIgniter4\EntityFramework\Exceptions\InvalidOperationException') 
                ? '\Yakupeyisan\CodeIgniter4\EntityFramework\Exceptions\InvalidOperationException'
                : \RuntimeException::class;
            throw new $exceptionClass("Cannot reload entity: Primary key value is null");
        }
        
        // Get primary key column name
        $primaryKeyName = $this->context->getPrimaryKeyName($entityType);
        
        // Reload from database using raw SQL for primary key
        $reloadedEntity = $this->context->set($entityType)
            ->where("{$primaryKeyName} = ?", [$id])
            ->first();
        
        if ($reloadedEntity === null) {
            $exceptionClass = class_exists('\Yakupeyisan\CodeIgniter4\EntityFramework\Exceptions\EntityNotFoundException') 
                ? '\Yakupeyisan\CodeIgniter4\EntityFramework\Exceptions\EntityNotFoundException'
                : \RuntimeException::class;
            throw new $exceptionClass($entityType, $id);
        }
        
        // Copy all property values from reloaded entity to current entity
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            
            
            // Skip internal tracking properties
            $excludedProperties = [
                'entityState',
                'originalValues',
                'currentValues',
                'navigationProperties',
                'isTracking'
            ];
            
            if (in_array($property->getName(), $excludedProperties)) {
                continue;
            }
            
            if ($property->isInitialized($reloadedEntity)) {
                $value = $property->getValue($reloadedEntity);
                $property->setValue($this->entity, $value);
            }
        }
        
        // Reset entity state
        if ($this->entity instanceof Entity) {
            $this->entity->markAsUnchanged();
            $this->entity->enableTracking();
        }
    }
}

