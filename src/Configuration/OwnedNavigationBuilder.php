<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Configuration;

/**
 * OwnedNavigationBuilder - Fluent API for owned types
 * Equivalent to OwnedNavigationBuilder<TEntity, TRelatedEntity> in EF Core
 */
class OwnedNavigationBuilder
{
    private EntityTypeBuilder $entityBuilder;
    private string $navigationProperty;
    private string $ownedType;
    private array $config = [];

    public function __construct(EntityTypeBuilder $entityBuilder, string $navigationProperty, string $ownedType)
    {
        $this->entityBuilder = $entityBuilder;
        $this->navigationProperty = $navigationProperty;
        $this->ownedType = $ownedType;
    }

    /**
     * Configure property
     */
    public function property(string $propertyName): PropertyBuilder
    {
        return new PropertyBuilder($this, $propertyName);
    }

    /**
     * Configure table splitting
     */
    public function toTable(string $tableName): self
    {
        $this->config['tableName'] = $tableName;
        $this->config['tableSplitting'] = true;
        return $this;
    }

    /**
     * Get configuration
     */
    public function getConfig(): array
    {
        return array_merge($this->config, [
            'navigationProperty' => $this->navigationProperty,
            'ownedType' => $this->ownedType
        ]);
    }

    /**
     * Return to entity builder
     */
    public function entity(): EntityTypeBuilder
    {
        return $this->entityBuilder;
    }
}

