<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Migrations;

/**
 * ColumnBuilder - Fluent API for building columns in migrations
 */
class ColumnBuilder
{
    private array $fields = [];

    /**
     * Add integer column
     */
    public function integer(string $name): self
    {
        $this->fields[$name] = [
            'type' => 'INT',
            'auto_increment' => false,
            'null' => true
        ];
        return $this;
    }
    
    /**
     * Set as primary key
     */
    public function primaryKey(): self
    {
        $lastKey = array_key_last($this->fields);
        if ($lastKey !== null) {
            $this->fields[$lastKey]['primary_key'] = true;
        }
        return $this;
    }
    
    /**
     * Set as auto increment
     */
    public function autoIncrement(): self
    {
        $lastKey = array_key_last($this->fields);
        if ($lastKey !== null) {
            $this->fields[$lastKey]['auto_increment'] = true;
        }
        return $this;
    }
    
    /**
     * Set as not null
     */
    public function notNull(): self
    {
        $lastKey = array_key_last($this->fields);
        if ($lastKey !== null) {
            $this->fields[$lastKey]['null'] = false;
        }
        return $this;
    }
    
    /**
     * Set as nullable
     */
    public function nullable(): self
    {
        $lastKey = array_key_last($this->fields);
        if ($lastKey !== null) {
            $this->fields[$lastKey]['null'] = true;
        }
        return $this;
    }

    /**
     * Add string column
     */
    public function string(string $name, ?int $length = null): self
    {
        $this->fields[$name] = [
            'type' => $length ? "VARCHAR({$length})" : 'VARCHAR(255)',
            'null' => true
        ];
        return $this;
    }

    /**
     * Add text column
     */
    public function text(string $name): self
    {
        $this->fields[$name] = [
            'type' => 'TEXT',
            'null' => true
        ];
        return $this;
    }

    /**
     * Add decimal column
     */
    public function decimal(string $name, int $precision = 18, int $scale = 2): self
    {
        $this->fields[$name] = [
            'type' => "DECIMAL({$precision},{$scale})",
            'null' => true
        ];
        return $this;
    }

    /**
     * Add boolean column
     */
    public function boolean(string $name): self
    {
        $this->fields[$name] = [
            'type' => 'TINYINT(1)',
            'null' => true
        ];
        return $this;
    }

    /**
     * Add date column
     */
    public function date(string $name): self
    {
        $this->fields[$name] = [
            'type' => 'DATE',
            'null' => true
        ];
        return $this;
    }

    /**
     * Add datetime column
     */
    public function datetime(string $name): self
    {
        $this->fields[$name] = [
            'type' => 'DATETIME',
            'null' => true
        ];
        return $this;
    }

    /**
     * Add timestamp column
     */
    public function timestamp(string $name): self
    {
        $this->fields[$name] = [
            'type' => 'TIMESTAMP',
            'null' => true
        ];
        return $this;
    }

    /**
     * Add JSON column
     */
    public function json(string $name): self
    {
        $this->fields[$name] = [
            'type' => 'JSON',
            'null' => true
        ];
        return $this;
    }

    /**
     * Set default value
     */
    public function defaultValue($value): self
    {
        $lastKey = array_key_last($this->fields);
        if ($lastKey !== null) {
            $this->fields[$lastKey]['default'] = $value;
        }
        return $this;
    }

    /**
     * Get fields
     */
    public function getFields(): array
    {
        return $this->fields;
    }
}

