<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Attributes;

use Attribute;

/**
 * MaxLength attribute - Specifies maximum length
 * Equivalent to [MaxLength(255)] in EF Core
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class MaxLength
{
    public function __construct(
        public int $length
    ) {}
}

