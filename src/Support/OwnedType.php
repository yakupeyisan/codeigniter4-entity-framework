<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Support;

/**
 * OwnedType - Represents an owned type (complex type)
 * Equivalent to OwnedEntityTypeBuilder in EF Core
 */
class OwnedType
{
    private string $type;
    private array $properties = [];

    public function __construct(string $type)
    {
        $this->type = $type;
    }

    /**
     * Configure property
     */
    public function property(string $name, string $columnName): self
    {
        $this->properties[$name] = $columnName;
        return $this;
    }

    /**
     * Get type
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get properties
     */
    public function getProperties(): array
    {
        return $this->properties;
    }
}

