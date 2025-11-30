<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Attributes;

use Attribute;

/**
 * SensitiveValue attribute - Masks sensitive data in SQL queries
 * 
 * When applied to a property, the column will be masked in SQL queries
 * unless disableSensitive() is called on the query.
 * 
 * Example:
 * #[SensitiveValue(maskChar: '*', visibleStart: 0, visibleEnd: 4)]
 * public string $CreditCard;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class SensitiveValue
{
    /**
     * @param string $maskChar Character used for masking (default: '*')
     * @param int $visibleStart Number of characters to show from start (default: 0)
     * @param int $visibleEnd Number of characters to show from end (default: 4)
     * @param string|null $customMask SQL expression for custom masking (overrides other options)
     */
    public function __construct(
        public string $maskChar = '*',
        public int $visibleStart = 0,
        public int $visibleEnd = 4,
        public ?string $customMask = null
    ) {}
}

