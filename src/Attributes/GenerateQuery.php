<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Attributes;

use Attribute;

/**
 * SQL Server programmability (function, view, sequence) deployed via EF migrations.
 * SQL is stored inline on the attribute; migration generator emits executeSql blocks.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class GenerateQuery
{
    public function __construct(
        public string $sql,
        public string $objectType,
        public string $objectName,
        public int $deployOrder = 100,
        public string $schema = 'dbo',
    ) {
    }
}
