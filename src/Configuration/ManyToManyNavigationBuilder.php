<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Configuration;

/**
 * ManyToManyNavigationBuilder - Fluent API for many-to-many relationships
 * Equivalent to ManyToManyNavigationBuilder<TEntity, TRelatedEntity> in EF Core
 */
class ManyToManyNavigationBuilder
{
    private EntityTypeBuilder $entityBuilder;
    private string $navigationProperty;
    private string $joinEntityType;
    private ?string $leftKey;
    private ?string $rightKey;
    private array $config = [];

    public function __construct(EntityTypeBuilder $entityBuilder, string $navigationProperty, string $joinEntityType, ?string $leftKey, ?string $rightKey)
    {
        $this->entityBuilder = $entityBuilder;
        $this->navigationProperty = $navigationProperty;
        $this->joinEntityType = $joinEntityType;
        $this->leftKey = $leftKey;
        $this->rightKey = $rightKey;
    }

    /**
     * Configure using join entity
     */
    public function usingEntity(string $joinEntityType, ?callable $configureLeft = null, ?callable $configureRight = null): self
    {
        $this->config['joinEntityType'] = $joinEntityType;
        if ($configureLeft) {
            $this->config['leftConfig'] = $configureLeft;
        }
        if ($configureRight) {
            $this->config['rightConfig'] = $configureRight;
        }
        return $this;
    }

    /**
     * Configure using skip navigation (implicit join table)
     */
    public function usingSkipNavigation(string $leftNavigation, string $rightNavigation): self
    {
        $this->config['skipNavigation'] = [
            'left' => $leftNavigation,
            'right' => $rightNavigation
        ];
        return $this;
    }

    /**
     * Get configuration
     */
    public function getConfig(): array
    {
        return array_merge($this->config, [
            'navigationProperty' => $this->navigationProperty,
            'joinEntityType' => $this->joinEntityType,
            'leftKey' => $this->leftKey,
            'rightKey' => $this->rightKey,
            'relationshipType' => 'many-to-many'
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

