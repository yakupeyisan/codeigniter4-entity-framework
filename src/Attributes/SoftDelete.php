<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Attributes;

use Attribute;

/**
 * SoftDelete attribute - Enables soft delete pattern
 * Marks entity as supporting soft delete
 */
#[Attribute(Attribute::TARGET_CLASS)]
class SoftDelete
{
    public function __construct(
        public string $propertyName = 'DeletedAt'
    ) {}
}

