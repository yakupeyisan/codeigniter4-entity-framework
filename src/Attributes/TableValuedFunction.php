<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Attributes;

use Attribute;

/**
 * Maps an entity to a SQL table-valued function for reference includes.
 *
 * When includeArgumentAsParameter is true, include()'s second argument is treated
 * as the TVF parameter (not a SQL WHERE predicate). Empty argument uses defaultArgumentSql.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class TableValuedFunction
{
    public function __construct(
        public string $name,
        public string $schema = 'dbo',
        public bool $includeArgumentAsParameter = true,
        public string $defaultArgumentSql = 'GETDATE()',
    ) {}
}
