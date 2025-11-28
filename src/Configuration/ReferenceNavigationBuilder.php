<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Configuration;

/**
 * ReferenceNavigationBuilder - Fluent API for reference navigation properties
 * Equivalent to ReferenceNavigationBuilder<TEntity, TRelatedEntity> in EF Core
 */
class ReferenceNavigationBuilder
{
    private EntityTypeBuilder $entityBuilder;
    private string $navigationProperty;
    private ?string $foreignKey;
    private string $relationshipType;
    private array $config = [];

    public function __construct(EntityTypeBuilder $entityBuilder, string $navigationProperty, ?string $foreignKey, string $relationshipType)
    {
        $this->entityBuilder = $entityBuilder;
        $this->navigationProperty = $navigationProperty;
        $this->foreignKey = $foreignKey;
        $this->relationshipType = $relationshipType;
    }

    /**
     * Configure with foreign key
     */
    public function withMany(string $navigationProperty): self
    {
        $this->config['inverseNavigation'] = $navigationProperty;
        $this->config['relationshipType'] = 'one-to-many';
        return $this;
    }

    /**
     * Configure with one (one-to-one)
     */
    public function withOne(string $navigationProperty): self
    {
        $this->config['inverseNavigation'] = $navigationProperty;
        $this->config['relationshipType'] = 'one-to-one';
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
     * Configure as required
     */
    public function isRequired(bool $required = true): self
    {
        $this->config['isRequired'] = $required;
        return $this;
    }

    /**
     * Configure delete behavior
     */
    public function onDelete(string $behavior): self
    {
        $this->config['deleteBehavior'] = $behavior; // Cascade, SetNull, Restrict, NoAction
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
            'relationshipType' => $this->relationshipType
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

