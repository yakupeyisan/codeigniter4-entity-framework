<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Attributes;

use Attribute;

/**
 * Key attribute - Marks a property as primary key
 * Equivalent to [Key] in EF Core
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Key
{
    public function __construct() {}
}

