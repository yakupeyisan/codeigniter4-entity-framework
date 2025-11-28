<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Attributes;

use Attribute;

/**
 * AuditFields attribute - Enables audit fields (CreatedAt, UpdatedAt, etc.)
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AuditFields
{
    public function __construct(
        public bool $createdAt = true,
        public bool $updatedAt = true,
        public bool $deletedAt = true,
        public bool $createdBy = false,
        public bool $updatedBy = false,
        public bool $deletedBy = false
    ) {}
}

