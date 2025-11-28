<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Attributes;

use Attribute;

/**
 * JsonColumn attribute - Marks property as JSON column
 * Equivalent to JSON column support in EF Core
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class JsonColumn
{
    public function __construct() {}
}

