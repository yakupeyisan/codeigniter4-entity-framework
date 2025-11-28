<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Attributes;

use Attribute;

/**
 * Required attribute - Marks property as required (NOT NULL)
 * Equivalent to [Required] in EF Core
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Required
{
    public function __construct() {}
}

