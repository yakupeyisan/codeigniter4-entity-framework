<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Configuration;

/**
 * CollectionNavigationBuilder - Fluent API for collection navigation properties
 * Equivalent to CollectionNavigationBuilder<TEntity, TRelatedEntity> in EF Core
 */
class CollectionNavigationBuilder
{
    private EntityTypeBuilder $entityBuilder;
    private string $navigationProperty;
    private ?string $foreignKey;
    private array $config = [];

    public function __construct(EntityTypeBuilder $entityBuilder, string $navigationProperty, ?string $foreignKey)
    {
        $this->entityBuilder = $entityBuilder;
        $this->navigationProperty = $navigationProperty;
        $this->foreignKey = $foreignKey;
    }

    /**
     * Configure with one (many-to-one)
     */
    public function withOne(string $navigationProperty): self
    {
        $this->config['inverseNavigation'] = $navigationProperty;
        $this->config['relationshipType'] = 'many-to-one';
        return $this;
    }

    /**
     * Configure foreign key property
     */
    public function hasForeignKey(string $foreignKeyProperty): self
    {
        $this->config['foreignKey'] = $foreignKeyProperty;
        return $this;
    }

    /**
     * Configure delete behavior
     */
    public function onDelete(string $behavior): self
    {
        $this->config['deleteBehavior'] = $behavior;
        return $this;
    }

    /**
     * Get configuration
     */
    public function getConfig(): array
    {
        return array_merge($this->config, [
            'navigationProperty' => $this->navigationProperty,
            'foreignKey' => $this->foreignKey,
            'relationshipType' => 'one-to-many'
        ]);
    }

    /**
     * Return to entity builder
     */
    public function entity(): EntityTypeBuilder
    {
        $this->entityBuilder->addConfig('relationships', [
            $this->navigationProperty => $this->getConfig()
        ]);
        return $this->entityBuilder;
    }
}

