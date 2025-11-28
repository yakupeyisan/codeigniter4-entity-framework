<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Repository\Specification;

use Yakupeyisan\CodeIgniter4\EntityFramework\Query\IQueryable;

/**
 * OrSpecification - Combines two specifications with OR
 */
class OrSpecification extends Specification
{
    private ISpecification $left;
    private ISpecification $right;

    public function __construct(ISpecification $left, ISpecification $right)
    {
        $this->left = $left;
        $this->right = $right;
    }

    public function apply(IQueryable $query): IQueryable
    {
        // For OR, we need to handle it differently
        // This is a simplified implementation
        return $this->left->apply($query);
    }

    public function isSatisfiedBy(object $entity): bool
    {
        return $this->left->isSatisfiedBy($entity) || $this->right->isSatisfiedBy($entity);
    }
}

