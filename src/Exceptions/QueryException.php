<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Exceptions;

/**
 * Exception thrown when a query execution fails
 */
class QueryException extends EntityFrameworkException
{
    private ?string $sql = null;
    private array $parameters = [];

    public function __construct(string $message, ?string $sql = null, array $parameters = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->sql = $sql;
        $this->parameters = $parameters;
    }

    public function getSql(): ?string
    {
        return $this->sql;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }
}
