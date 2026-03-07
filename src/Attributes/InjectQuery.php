<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class InjectQuery
{
    /**
     * @param string $expression Raw SQL expression to use in SELECT
     *                           (e.g. "DATEPART(DAY, EventTime)")
     */
    public function __construct(
        public string $expression
    ) {
    }
}

