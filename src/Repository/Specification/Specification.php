<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Repository\Specification;

use Yakupeyisan\CodeIgniter4\EntityFramework\Query\IQueryable;

/**
 * Specification - Base specification implementation
 * Equivalent to Specification<T> in .NET
 */
abstract class Specification implements ISpecification
{
    /**
     * Apply specification to query
     */
    abstract public function apply(IQueryable $query): IQueryable;

    /**
     * Check if specification is satisfied
     */
    abstract public function isSatisfiedBy(object $entity): bool;

    /**
     * Combine with AND
     */
    public function and(ISpecification $specification): ISpecification
    {
        return new AndSpecification($this, $specification);
    }

    /**
     * Combine with OR
     */
    public function or(ISpecification $specification): ISpecification
    {
        return new OrSpecification($this, $specification);
    }

    /**
     * Negate specification
     */
    public function not(): ISpecification
    {
        return new NotSpecification($this);
    }
}

