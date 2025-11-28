<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Configuration;

/**
 * PropertyBuilder - Fluent API for property configuration
 * Equivalent to PropertyBuilder<TProperty> in EF Core
 */
class PropertyBuilder
{
    private EntityTypeBuilder $entityBuilder;
    private string $propertyName;
    private array $config = [];

    public function __construct(EntityTypeBuilder $entityBuilder, string $propertyName)
    {
        $this->entityBuilder = $entityBuilder;
        $this->propertyName = $propertyName;
    }

    /**
     * Configure column name
     */
    public function hasColumnName(string $name): self
    {
        $this->config['columnName'] = $name;
        return $this;
    }

    /**
     * Configure column type
     */
    public function hasColumnType(string $type): self
    {
        $this->config['columnType'] = $type;
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
     * Configure as optional
     */
    public function isOptional(): self
    {
        $this->config['isRequired'] = false;
        return $this;
    }

    /**
     * Configure max length
     */
    public function hasMaxLength(int $length): self
    {
        $this->config['maxLength'] = $length;
        return $this;
    }

    /**
     * Configure precision and scale (for decimal)
     */
    public function hasPrecision(int $precision, int $scale): self
    {
        $this->config['precision'] = $precision;
        $this->config['scale'] = $scale;
        return $this;
    }

    /**
     * Configure default value
     */
    public function hasDefaultValue($value): self
    {
        $this->config['defaultValue'] = $value;
        return $this;
    }

    /**
     * Configure default value SQL
     */
    public function hasDefaultValueSql(string $sql): self
    {
        $this->config['defaultValueSql'] = $sql;
        return $this;
    }

    /**
     * Configure computed column
     */
    public function hasComputedColumnSql(?string $sql = null): self
    {
        $this->config['computedColumnSql'] = $sql;
        return $this;
    }

    /**
     * Configure value generator
     */
    public function valueGeneratedOnAdd(): self
    {
        $this->config['valueGenerated'] = 'OnAdd';
        return $this;
    }

    /**
     * Configure value generated on add or update
     */
    public function valueGeneratedOnAddOrUpdate(): self
    {
        $this->config['valueGenerated'] = 'OnAddOrUpdate';
        return $this;
    }

    /**
     * Configure value never generated
     */
    public function valueGeneratedNever(): self
    {
        $this->config['valueGenerated'] = 'Never';
        return $this;
    }

    /**
     * Configure as concurrency token
     */
    public function isConcurrencyToken(bool $isToken = true): self
    {
        $this->config['isConcurrencyToken'] = $isToken;
        return $this;
    }

    /**
     * Configure value converter
     */
    public function hasConversion(callable $convertToProvider, callable $convertFromProvider): self
    {
        $this->config['valueConverter'] = [
            'toProvider' => $convertToProvider,
            'fromProvider' => $convertFromProvider
        ];
        return $this;
    }

    /**
     * Ignore property
     */
    public function ignore(): self
    {
        $this->config['ignored'] = true;
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
     * Get property name
     */
    public function getPropertyName(): string
    {
        return $this->propertyName;
    }

    /**
     * Return to entity builder
     */
    public function entity(): EntityTypeBuilder
    {
        $this->entityBuilder->addConfig('properties', [
            $this->propertyName => $this->config
        ]);
        return $this->entityBuilder;
    }
}

