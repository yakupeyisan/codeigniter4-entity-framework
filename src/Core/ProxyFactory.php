<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Core;

use ReflectionClass;
use ReflectionProperty;

/**
 * ProxyFactory - Creates lazy loading proxies for entities
 * Equivalent to proxy factory in EF Core
 */
class ProxyFactory
{
    private DbContext $context;

    public function __construct(DbContext $context)
    {
        $this->context = $context;
    }

    /**
     * Create proxy for entity navigation property
     */
    public function createProxy(Entity $entity, string $navigationProperty): ?LazyLoadingProxy
    {
        $entityReflection = new ReflectionClass($entity);
        
        if (!$entityReflection->hasProperty($navigationProperty)) {
            return null;
        }

        $navProperty = $entityReflection->getProperty($navigationProperty);
        $navProperty->setAccessible(true);
        
        // Check if already loaded
        $currentValue = $navProperty->getValue($entity);
        if ($currentValue !== null) {
            return null; // Already loaded
        }

        // Determine if it's a collection or reference
        $type = $navProperty->getType();
        $isCollection = false;
        $relatedEntityType = null;

        if ($type) {
            $typeName = $type->getName();
            
            // Check if it's an array (collection)
            if ($typeName === 'array') {
                $isCollection = true;
            } elseif (class_exists($typeName)) {
                $relatedEntityType = $typeName;
            }
        }

        // Try to infer from doc comment
        $docComment = $navProperty->getDocComment();
        if ($docComment) {
            if (preg_match('/@var\s+(\S+)/', $docComment, $matches)) {
                $typeHint = $matches[1];
                if (strpos($typeHint, '[]') !== false || strpos($typeHint, 'array') !== false) {
                    $isCollection = true;
                    $relatedEntityType = str_replace(['[]', 'array'], '', $typeHint);
                    $relatedEntityType = trim($relatedEntityType);
                } elseif (class_exists($typeHint)) {
                    $relatedEntityType = $typeHint;
                }
            }
        }

        // Get foreign key
        $foreignKey = $this->getForeignKeyForNavigation($entityReflection, $navigationProperty, $isCollection);

        // Get related entity type
        if ($relatedEntityType === null) {
            $relatedEntityType = $this->inferRelatedEntityType($entityReflection, $navigationProperty);
        }

        if ($relatedEntityType === null) {
            return null;
        }

        return new LazyLoadingProxy(
            $this->context,
            $entity,
            $navigationProperty,
            $foreignKey,
            $relatedEntityType,
            $isCollection
        );
    }

    /**
     * Get foreign key for navigation property
     */
    private function getForeignKeyForNavigation(ReflectionClass $entityReflection, string $navigationProperty, bool $isCollection): ?string
    {
        if ($isCollection) {
            // For collection navigation, foreign key is in related entity
            // Convention: EntityName + "Id"
            $entityName = $entityReflection->getShortName();
            return $entityName . 'Id';
        } else {
            // For reference navigation, foreign key is in current entity
            // Convention: NavigationPropertyName + "Id"
            $fkPropertyName = $navigationProperty . 'Id';
            
            if ($entityReflection->hasProperty($fkPropertyName)) {
                return $fkPropertyName;
            }

            // Check ForeignKey attribute
            $navProperty = $entityReflection->getProperty($navigationProperty);
            $attributes = $navProperty->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\ForeignKey::class);
            
            if (!empty($attributes)) {
                $fkAttr = $attributes[0]->newInstance();
                // ForeignKey attribute points to navigation property, not FK property
                // We need to find the actual FK property
                return $fkPropertyName; // Fallback to convention
            }
        }

        return null;
    }

    /**
     * Infer related entity type from navigation property
     */
    private function inferRelatedEntityType(ReflectionClass $entityReflection, string $navigationProperty): ?string
    {
        $navProperty = $entityReflection->getProperty($navigationProperty);
        $type = $navProperty->getType();
        
        if ($type && !$type->isBuiltin()) {
            return $type->getName();
        }

        // Try doc comment
        $docComment = $navProperty->getDocComment();
        if ($docComment) {
            if (preg_match('/@var\s+([A-Za-z0-9_\\\\]+)/', $docComment, $matches)) {
                $typeHint = trim($matches[1]);
                $typeHint = str_replace(['[]', 'array'], '', $typeHint);
                $typeHint = trim($typeHint);
                
                if (class_exists($typeHint)) {
                    return $typeHint;
                }
                
                // Try App\Models namespace
                $fullClassName = 'App\\Models\\' . $typeHint;
                if (class_exists($fullClassName)) {
                    return $fullClassName;
                }
            }
        }

        // Try convention: Navigation property name -> Entity name
        $entityName = ucfirst($navigationProperty);
        if (substr($entityName, -1) === 's') {
            $entityName = substr($entityName, 0, -1);
        }
        
        $possibleClass = 'App\\Models\\' . $entityName;
        if (class_exists($possibleClass)) {
            return $possibleClass;
        }

        return null;
    }

    /**
     * Enable lazy loading for entity
     * Creates proxies for all navigation properties
     */
    public function enableLazyLoading(Entity $entity): void
    {
        $entityReflection = new ReflectionClass($entity);
        
        foreach ($entityReflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $type = $property->getType();
            
            // Check if it's a navigation property (object or array type)
            if ($type && !$type->isBuiltin()) {
                // It's an object - likely a reference navigation
                $proxy = $this->createProxy($entity, $property->getName());
                if ($proxy !== null) {
                    $this->setProxyToEntity($entity, $property->getName(), $proxy);
                }
            } elseif ($type && $type->getName() === 'array') {
                // It's an array - likely a collection navigation
                $proxy = $this->createProxy($entity, $property->getName());
                if ($proxy !== null) {
                    $this->setProxyToEntity($entity, $property->getName(), $proxy);
                }
            }
        }
    }

    /**
     * Set proxy to entity property
     */
    private function setProxyToEntity(Entity $entity, string $propertyName, LazyLoadingProxy $proxy): void
    {
        $reflection = new ReflectionClass($entity);
        if ($reflection->hasProperty($propertyName)) {
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            
            // Check if property already has a value (loaded via Include)
            $currentValue = $property->getValue($entity);
            if ($currentValue !== null) {
                return; // Already loaded, don't set proxy
            }
            
            // Store proxy so __get can access it
            $entity->setNavigationProperty('_proxy_' . $propertyName, $proxy);
        }
    }
}

