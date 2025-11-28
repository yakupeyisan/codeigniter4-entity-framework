<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Attributes;

use Attribute;

/**
 * DatabaseGenerated attribute - Specifies how database generates values
 * Equivalent to [DatabaseGenerated(DatabaseGeneratedOption.Identity)] in EF Core
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class DatabaseGenerated
{
    public const NONE = 0;
    public const IDENTITY = 1;
    public const COMPUTED = 2;

    public function __construct(
        public int $option = self::IDENTITY
    ) {}
}

