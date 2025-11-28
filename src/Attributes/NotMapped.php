<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Attributes;

use Attribute;

/**
 * NotMapped attribute - Excludes property from mapping
 * Equivalent to [NotMapped] in EF Core
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
class NotMapped
{
    public function __construct() {}
}

