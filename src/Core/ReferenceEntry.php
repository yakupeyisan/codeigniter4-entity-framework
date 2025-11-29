<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Core;

/**
 * ReferenceEntry - Provides access to reference navigation properties
 * Equivalent to ReferenceEntry in EF Core
 */
class ReferenceEntry
{
    private EntityEntry $entityEntry;
    private string $propertyName;

    public function __construct(EntityEntry $entityEntry, string $propertyName)
    {
        $this->entityEntry = $entityEntry;
        $this->propertyName = $propertyName;
    }

    /**
     * Load reference (explicit loading)
     */
    public function load(): void
    {
        $entity = $this->entityEntry->getEntity();
        
        if ($entity instanceof Entity) {
            // Check if there's a lazy loading proxy
            $proxyKey = '_proxy_' . $this->propertyName;
            $navigationProperties = $entity->getNavigationProperties();
            
            if (isset($navigationProperties[$proxyKey])) {
                $proxy = $navigationProperties[$proxyKey];
                if ($proxy instanceof \Yakupeyisan\CodeIgniter4\EntityFramework\Core\LazyLoadingProxy) {
                    $proxy->load();
                    return;
                }
            }
            
            // Manual loading if no proxy
            $context = $this->entityEntry->getContext();
            $this->loadManually($context, $entity);
        }
    }

    /**
     * Load reference manually
     */
    private function loadManually(DbContext $context, Entity $entity): void
    {
        // Implementation for manual loading
        // This would query the database to load the navigation property
    }

    /**
     * Check if reference is loaded
     */
    public function isLoaded(): bool
    {
        $entity = $this->entityEntry->getEntity();
        
        if ($entity instanceof Entity) {
            return $entity->isNavigationPropertyLoaded($this->propertyName);
        }
        
        return false;
    }

    /**
     * Get current value
     */
    public function getCurrentValue()
    {
        $entity = $this->entityEntry->getEntity();
        $reflection = new \ReflectionProperty($entity, $this->propertyName);
        $reflection->setAccessible(true);
        return $reflection->getValue($entity);
    }
}

