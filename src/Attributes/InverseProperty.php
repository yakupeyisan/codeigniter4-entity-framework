<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Attributes;

use Attribute;

/**
 * InverseProperty attribute - Specifies inverse navigation property
 * Equivalent to [InverseProperty("PropertyName")] in EF Core
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class InverseProperty
{
    public function __construct(
        public string $property
    ) {}
}

