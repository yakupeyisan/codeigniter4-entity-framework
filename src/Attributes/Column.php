<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Attributes;

use Attribute;

/**
 * Column attribute - Configures column properties
 * Equivalent to [Column("ColumnName", TypeName = "varchar(255)")] in EF Core
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Column
{
    public function __construct(
        public ?string $name = null,
        public ?string $typeName = null,
        public ?int $order = null,
        public ?int $precision = null,
        public ?int $scale = null
    ) {}
}

