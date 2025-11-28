<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Support;

/**
 * ValueConverter - Converts values between entity and database
 * Equivalent to ValueConverter in EF Core
 */
class ValueConverter
{
    private $convertToProvider;
    private $convertFromProvider;

    public function __construct(callable $convertToProvider, callable $convertFromProvider)
    {
        $this->convertToProvider = $convertToProvider;
        $this->convertFromProvider = $convertFromProvider;
    }

    /**
     * Convert to provider (database) value
     */
    public function convertToProvider($value)
    {
        return call_user_func($this->convertToProvider, $value);
    }

    /**
     * Convert from provider (database) value
     */
    public function convertFromProvider($value)
    {
        return call_user_func($this->convertFromProvider, $value);
    }
}

