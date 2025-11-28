<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Attributes;

use Attribute;

/**
 * Index attribute - Creates database index
 * Equivalent to [Index("PropertyName", IsUnique = true)] in EF Core
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
class Index
{
    public function __construct(
        public string|array $propertyNames,
        public bool $isUnique = false,
        public ?string $name = null
    ) {}
}

