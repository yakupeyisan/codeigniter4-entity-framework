<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Attributes;

use Attribute;

/**
 * ConcurrencyCheck attribute - Marks property as concurrency token
 * Equivalent to [ConcurrencyCheck] in EF Core
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ConcurrencyCheck
{
    public function __construct() {}
}

