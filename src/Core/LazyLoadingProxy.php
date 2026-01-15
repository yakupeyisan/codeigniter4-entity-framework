<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Core;

use Yakupeyisan\CodeIgniter4\EntityFramework\Core\DbContext;
use ReflectionClass;
use ReflectionProperty;
use ReflectionNamedType;

/**
 * LazyLoadingProxy - Proxy class for lazy loading navigation properties
 * Equivalent to lazy loading proxies in EF Core
 * Automatically loads navigation properties when accessed
 */
class LazyLoadingProxy
{
    private DbContext $context;
    private Entity $entity;
    private string $navigationProperty;
    private ?string $foreignKey;
    private ?string $relatedEntityType;
    private bool $isCollection;
    private $loadedValue = null;
    private bool $isLoaded = false;

    public function __construct(
        DbContext $context,
        Entity $entity,
        string $navigationProperty,
        ?string $foreignKey = null,
        ?string $relatedEntityType = null,
        bool $isCollection = false
    ) {
        $this->context = $context;
        $this->entity = $entity;
        $this->navigationProperty = $navigationProperty;
        $this->foreignKey = $foreignKey;
        $this->relatedEntityType = $relatedEntityType;
        $this->isCollection = $isCollection;
    }

    /**
     * Load the navigation property value
     * @return mixed Loaded navigation property value (Entity, array of Entities, or null)
     */
    public function load(): mixed
    {
        if ($this->isLoaded) {
            return $this->loadedValue;
        }

        if ($this->isCollection) {
            $this->loadedValue = $this->loadCollection();
        } else {
            $this->loadedValue = $this->loadReference();
        }

        $this->isLoaded = true;
        
        // Set the loaded value to the entity
        $this->setValueToEntity($this->loadedValue);
        
        return $this->loadedValue;
    }

    /**
     * Load reference navigation (many-to-one or one-to-one)
     * @return Entity|null Loaded entity or null
     */
    private function loadReference(): ?Entity
    {
        if ($this->foreignKey === null || $this->relatedEntityType === null) {
            return null;
        }

        $entityReflection = new ReflectionClass($this->entity);
        
        // Get foreign key value from entity
        if (!$entityReflection->hasProperty($this->foreignKey)) {
            return null;
        }

        $fkProperty = $entityReflection->getProperty($this->foreignKey);
        $fkProperty->setAccessible(true);
        $fkValue = $fkProperty->getValue($this->entity);

        if ($fkValue === null) {
            return null;
        }

        // Load related entity using primary key
        $primaryKeyName = $this->context->getPrimaryKeyName($this->relatedEntityType);
        $relatedEntity = $this->context->set($this->relatedEntityType)
            ->where("{$primaryKeyName} = ?", [$fkValue])
            ->firstOrDefault();

        return $relatedEntity;
    }

    /**
     * Load collection navigation (one-to-many)
     * @return array Array of related entities
     */
    private function loadCollection(): array
    {
        if ($this->relatedEntityType === null) {
            return [];
        }

        $entityReflection = new ReflectionClass($this->entity);
        $entityType = get_class($this->entity);
        
        // Get entity ID dynamically using context's getPrimaryKeyName
        $primaryKeyName = $this->context->getPrimaryKeyName($entityType);
        $entityId = null;
        
        // Find primary key property by name
        foreach ($entityReflection->getProperties() as $property) {
            $propertyName = $property->getName();
            // Check if this property matches the primary key name (considering Column attribute)
            $columnName = $this->getColumnNameFromProperty($entityReflection, $propertyName);
            if ($columnName === $primaryKeyName) {
                $property->setAccessible(true);
                if ($property->isInitialized($this->entity)) {
                    $entityId = $property->getValue($this->entity);
                    break;
                }
            }
        }
        
        // Fallback: try Key attribute
        if ($entityId === null) {
            foreach ($entityReflection->getProperties() as $property) {
                $attributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Key::class);
                if (!empty($attributes)) {
                    $property->setAccessible(true);
                    if ($property->isInitialized($this->entity)) {
                        $entityId = $property->getValue($this->entity);
                        break;
                    }
                }
            }
        }
        
        // Last fallback: try common names
        if ($entityId === null) {
            $commonNames = ['Id', $entityReflection->getShortName() . 'Id'];
            foreach ($commonNames as $name) {
                if ($entityReflection->hasProperty($name)) {
                    $idProperty = $entityReflection->getProperty($name);
                    $idProperty->setAccessible(true);
                    if ($idProperty->isInitialized($this->entity)) {
                        $entityId = $idProperty->getValue($this->entity);
                        break;
                    }
                }
            }
        }

        if ($entityId === null) {
            return [];
        }

        // Infer foreign key name (convention: EntityName + "Id")
        $entityName = $entityReflection->getShortName();
        $inferredFk = $entityName . 'Id';

        // Try to find foreign key property in related entity
        $relatedReflection = new ReflectionClass($this->relatedEntityType);
        $fkPropertyName = $this->foreignKey ?? $inferredFk;

        // Load related entities
        $relatedEntities = $this->context->set($this->relatedEntityType)
            ->where(fn($e) => $e->$fkPropertyName === $entityId)
            ->toList();

        return $relatedEntities;
    }
    
    /**
     * Get column name from property (helper method)
     */
    private function getColumnNameFromProperty(ReflectionClass $reflection, string $propertyName): string
    {
        if ($reflection->hasProperty($propertyName)) {
            $property = $reflection->getProperty($propertyName);
            $attributes = $property->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Column::class);
            
            if (!empty($attributes)) {
                $columnAttr = $attributes[0]->newInstance();
                if ($columnAttr->name !== null) {
                    return $columnAttr->name;
                }
            }
        }
        
        return $propertyName;
    }

    /**
     * Set loaded value to entity property
     */
    private function setValueToEntity($value): void
    {
        $reflection = new ReflectionClass($this->entity);
        if ($reflection->hasProperty($this->navigationProperty)) {
            $property = $reflection->getProperty($this->navigationProperty);
            $property->setAccessible(true);
            $property->setValue($this->entity, $value);
        }
    }

    /**
     * Check if navigation property is loaded
     */
    public function isLoaded(): bool
    {
        return $this->isLoaded;
    }

    /**
     * Get the loaded value
     * @return mixed Loaded value
     */
    public function getValue(): mixed
    {
        if (!$this->isLoaded) {
            $this->load();
        }
        return $this->loadedValue;
    }
}

