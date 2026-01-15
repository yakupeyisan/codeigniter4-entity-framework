<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Exceptions;

/**
 * Exception thrown when an entity is not found
 */
class EntityNotFoundException extends EntityFrameworkException
{
    private string $entityType;
    private $id;

    public function __construct(string $entityType, $id, int $code = 0, ?\Throwable $previous = null)
    {
        $message = "Entity of type '{$entityType}' with ID '{$id}' was not found.";
        parent::__construct($message, $code, $previous);
        $this->entityType = $entityType;
        $this->id = $id;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getId()
    {
        return $this->id;
    }
}
