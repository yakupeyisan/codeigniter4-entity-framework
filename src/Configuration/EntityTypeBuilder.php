<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Configuration;

/**
 * EntityTypeBuilder - Fluent API configuration builder
 * Equivalent to EntityTypeBuilder<T> in EF Core
 */
class EntityTypeBuilder
{
    private array $config = [];
    private string $entityType;

    public function __construct(string $entityType)
    {
        $this->entityType = $entityType;
    }

    /**
     * Configure primary key
     */
    public function hasKey(string|array $propertyNames): self
    {
        $this->config['keys'][] = is_array($propertyNames) ? $propertyNames : [$propertyNames];
        return $this;
    }

    /**
     * Configure property
     */
    public function property(string $propertyName): PropertyBuilder
    {
        return new PropertyBuilder($this, $propertyName);
    }

    /**
     * Configure relationship
     */
    public function hasOne(string $navigationProperty, ?string $foreignKey = null): ReferenceNavigationBuilder
    {
        return new ReferenceNavigationBuilder($this, $navigationProperty, $foreignKey, 'one');
    }

    /**
     * Configure one-to-many relationship
     */
    public function hasMany(string $navigationProperty, ?string $foreignKey = null): CollectionNavigationBuilder
    {
        return new CollectionNavigationBuilder($this, $navigationProperty, $foreignKey);
    }

    /**
     * Configure many-to-many relationship
     */
    public function hasManyToMany(string $navigationProperty, string $joinEntityType, ?string $leftKey = null, ?string $rightKey = null): ManyToManyNavigationBuilder
    {
        return new ManyToManyNavigationBuilder($this, $navigationProperty, $joinEntityType, $leftKey, $rightKey);
    }

    /**
     * Configure table name
     */
    public function toTable(string $name, ?string $schema = null): self
    {
        $this->config['table'] = ['name' => $name, 'schema' => $schema];
        return $this;
    }

    /**
     * Configure index
     */
    public function hasIndex(string|array $propertyNames, ?string $name = null, bool $isUnique = false): self
    {
        $props = is_array($propertyNames) ? $propertyNames : [$propertyNames];
        $this->config['indexes'][] = [
            'properties' => $props,
            'name' => $name,
            'isUnique' => $isUnique
        ];
        return $this;
    }

    /**
     * Ignore property
     */
    public function ignore(string $propertyName): self
    {
        $this->config['ignoredProperties'][] = $propertyName;
        return $this;
    }

    /**
     * Configure owned type
     */
    public function ownsOne(string $navigationProperty, string $ownedType, ?callable $configureAction = null): OwnedNavigationBuilder
    {
        $builder = new OwnedNavigationBuilder($this, $navigationProperty, $ownedType);
        if ($configureAction) {
            $configureAction($builder);
        }
        $this->config['ownedTypes'][] = [
            'navigationProperty' => $navigationProperty,
            'ownedType' => $ownedType,
            'config' => $builder->getConfig()
        ];
        return $builder;
    }

    /**
     * Configure owned collection
     */
    public function ownsMany(string $navigationProperty, string $ownedType, ?callable $configureAction = null): OwnedNavigationBuilder
    {
        $builder = new OwnedNavigationBuilder($this, $navigationProperty, $ownedType);
        if ($configureAction) {
            $configureAction($builder);
        }
        $this->config['ownedTypes'][] = [
            'navigationProperty' => $navigationProperty,
            'ownedType' => $ownedType,
            'isCollection' => true,
            'config' => $builder->getConfig()
        ];
        return $builder;
    }

    /**
     * Configure query filter (global filter)
     */
    public function hasQueryFilter(callable $filter): self
    {
        $this->config['queryFilter'] = $filter;
        return $this;
    }

    /**
     * Configure soft delete
     */
    public function hasSoftDelete(string $propertyName = 'DeletedAt'): self
    {
        $this->config['softDelete'] = ['propertyName' => $propertyName];
        return $this;
    }

    /**
     * Get configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Add configuration
     */
    public function addConfig(string $key, $value): self
    {
        $this->config[$key] = $value;
        return $this;
    }
}

