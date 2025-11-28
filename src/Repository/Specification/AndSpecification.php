<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Repository\Specification;

use Yakupeyisan\CodeIgniter4\EntityFramework\Query\IQueryable;

/**
 * AndSpecification - Combines two specifications with AND
 */
class AndSpecification extends Specification
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
        return $this->right->apply($this->left->apply($query));
    }

    public function isSatisfiedBy(object $entity): bool
    {
        return $this->left->isSatisfiedBy($entity) && $this->right->isSatisfiedBy($entity);
    }
}

