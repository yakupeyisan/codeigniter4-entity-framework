<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Attributes;

use Attribute;

/**
 * Owned attribute - Marks entity as owned type
 * Equivalent to [Owned] in EF Core
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Owned
{
    public function __construct() {}
}

