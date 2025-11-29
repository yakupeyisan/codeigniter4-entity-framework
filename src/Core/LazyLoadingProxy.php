<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Core;

use Yakupeyisan\CodeIgniter4\EntityFramework\Core\DbContext;
use ReflectionClass;
use ReflectionProperty;

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
     */
    public function load()
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
     */
    private function loadReference()
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

        // Load related entity
        $relatedEntity = $this->context->set($this->relatedEntityType)
            ->where(fn($e) => $e->Id === $fkValue)
            ->firstOrDefault();

        return $relatedEntity;
    }

    /**
     * Load collection navigation (one-to-many)
     */
    private function loadCollection()
    {
        if ($this->relatedEntityType === null) {
            return [];
        }

        $entityReflection = new ReflectionClass($this->entity);
        
        // Get entity ID
        if (!$entityReflection->hasProperty('Id')) {
            return [];
        }

        $idProperty = $entityReflection->getProperty('Id');
        $idProperty->setAccessible(true);
        $entityId = $idProperty->getValue($this->entity);

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
     */
    public function getValue()
    {
        if (!$this->isLoaded) {
            $this->load();
        }
        return $this->loadedValue;
    }
}

