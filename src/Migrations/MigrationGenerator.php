<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Migrations;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use CodeIgniter\Database\BaseConnection;

/**
 * MigrationGenerator - Generates migration code from ApplicationDbContext
 * Analyzes entity configurations and generates migration code automatically
 */
class MigrationGenerator
{
    private array $entities = [];
    private array $foreignKeys = [];
    private array $indexes = [];
    private string $contextClass;
    private ?BaseConnection $connection;
    private array $existingTables = [];
    private array $existingColumns = [];

    public function __construct(string $contextClass, ?BaseConnection $connection = null)
    {
        $this->contextClass = $contextClass;
        $this->connection = $connection;
        
        // Load existing tables and columns if connection is provided
        if ($this->connection !== null) {
            $this->loadExistingSchema();
        }
    }

    /**
     * Load existing schema from database
     */
    private function loadExistingSchema(): void
    {
        if ($this->connection === null) {
            return;
        }

        try {
            $driver = strtolower($this->connection->getPlatform() ?? '');
            $isSqlServer = ($driver === 'sqlsrv' || $driver === 'sqlserver');

            if ($isSqlServer) {
                $this->loadExistingSchemaSqlServer();
            } else {
                $this->loadExistingSchemaMySql();
            }
        } catch (\Exception $e) {
            error_log("Error loading existing schema: " . $e->getMessage());
            // Continue without existing schema info
        }
    }

    /**
     * Load existing schema for MySQL
     */
    private function loadExistingSchemaMySql(): void
    {
        // Get database name
        $database = $this->getDatabaseName();
        if (empty($database)) {
            return;
        }
        
        // Get all tables
        $tablesQuery = $this->connection->query("
            SELECT TABLE_NAME 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
        ", [$database]);
        
        $tables = $tablesQuery->getResultArray();
        foreach ($tables as $table) {
            $tableName = $table['TABLE_NAME'];
            $this->existingTables[$tableName] = true;
            
            // Get columns for this table
            $columnsQuery = $this->connection->query("
                SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_KEY, EXTRA
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                ORDER BY ORDINAL_POSITION
            ", [$database, $tableName]);
            
            $columns = $columnsQuery->getResultArray();
            foreach ($columns as $column) {
                $this->existingColumns[$tableName][$column['COLUMN_NAME']] = $column;
            }
        }
    }

    /**
     * Load existing schema for SQL Server
     */
    private function loadExistingSchemaSqlServer(): void
    {
        // Get all tables
        $tablesQuery = $this->connection->query("
            SELECT TABLE_NAME 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_TYPE = 'BASE TABLE'
        ");
        
        $tables = $tablesQuery->getResultArray();
        foreach ($tables as $table) {
            $tableName = $table['TABLE_NAME'];
            $this->existingTables[$tableName] = true;
            
            // Get columns for this table
            $columnsQuery = $this->connection->query("
                SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_NAME = ?
                ORDER BY ORDINAL_POSITION
            ", [$tableName]);
            
            $columns = $columnsQuery->getResultArray();
            foreach ($columns as $column) {
                $this->existingColumns[$tableName][$column['COLUMN_NAME']] = $column;
            }
        }
    }

    /**
     * Get database name from connection
     */
    private function getDatabaseName(): ?string
    {
        if ($this->connection === null) {
            return null;
        }
        
        // Try getDatabase() method first
        if (method_exists($this->connection, 'getDatabase')) {
            return $this->connection->getDatabase();
        }
        
        // Fallback to config
        try {
            $dbConfig = new \Config\Database();
            $defaultConfig = $dbConfig->default;
            return $defaultConfig['database'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if table exists
     */
    private function tableExists(string $tableName): bool
    {
        if ($this->connection === null) {
            return false;
        }
        
        return isset($this->existingTables[$tableName]) || $this->connection->tableExists($tableName);
    }

    /**
     * Check if column exists in table
     */
    private function columnExists(string $tableName, string $columnName): bool
    {
        if ($this->connection === null) {
            return false;
        }
        
        return isset($this->existingColumns[$tableName][$columnName]);
    }

    /**
     * Generate migration code for all entities in ApplicationDbContext
     */
    public function generateMigrationCode(): array
    {
        try {
            $this->analyzeContext();
            
            if (empty($this->entities)) {
                error_log("No entities found after analysis");
                return ['up' => '', 'down' => ''];
            }
            
            $upCode = $this->generateUpCode();
            $downCode = $this->generateDownCode();
            
            if (empty(trim($upCode)) || empty(trim($downCode))) {
                error_log("Generated code is empty. Up: " . strlen($upCode) . ", Down: " . strlen($downCode));
                return ['up' => '', 'down' => ''];
            }
            
            return [
                'up' => $upCode,
                'down' => $downCode
            ];
        } catch (\Exception $e) {
            error_log("Error in generateMigrationCode: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return ['up' => '', 'down' => ''];
        }
    }

    /**
     * Analyze ApplicationDbContext to find all entities
     */
    private function analyzeContext(): void
    {
        try {
            if (!class_exists($this->contextClass)) {
                error_log("Context class does not exist: {$this->contextClass}");
                return;
            }

            $reflection = new ReflectionClass($this->contextClass);
            
            // First, get use statements to resolve class names
            $useStatements = $this->getUseStatements($reflection);
            error_log("Found " . count($useStatements) . " use statements");
            
            // Find all public methods that look like DbSet methods (Users, Companies, etc.)
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
            error_log("Found " . count($methods) . " public methods");
            
            $entityClassesFound = [];
            
            foreach ($methods as $method) {
                $methodName = $method->getName();
                
                // Skip constructor and other non-DbSet methods
                if ($methodName === '__construct' || 
                    $methodName === 'onModelCreating' ||
                    $methodName === 'set' ||
                    strpos($methodName, 'set') === 0 ||
                    $methodName === 'getEntityConfiguration' ||
                    $methodName === 'setQueryFilter' ||
                    $methodName === 'getQueryFilter' ||
                    $methodName === 'getTableName' ||
                    $methodName === 'entity' ||
                    $methodName === 'saveChanges' ||
                    $methodName === 'fromSqlRaw' ||
                    $methodName === 'executeSqlRaw' ||
                    $methodName === 'getConnection' ||
                    $methodName === 'beginTransaction' ||
                    $methodName === 'commit' ||
                    $methodName === 'rollback' ||
                    $methodName === 'add' ||
                    $methodName === 'update' ||
                    $methodName === 'remove' ||
                    $methodName === 'attach' ||
                    $methodName === 'entry') {
                    continue;
                }
                
                // Try to get entity class from method body
                try {
                    $methodBody = $this->getMethodBody($method);
                    $entityClass = null;
                    
                    if (!empty($methodBody)) {
                        // Try pattern: $this->set(User::class) or $this->set(\App\Models\User::class)
                        if (preg_match('/return\s+\$this->set\(([^\)]+)\)/', $methodBody, $matches)) {
                            $classExpression = trim($matches[1]);
                            
                            // Remove ::class suffix if present
                            $classExpression = preg_replace('/::class\s*$/', '', $classExpression);
                            $classExpression = trim($classExpression);
                            
                            // Resolve class name using use statements
                            $entityClass = $this->resolveClassName($classExpression, $useStatements);
                            error_log("Method {$methodName}: Found entity class {$entityClass} from method body");
                        }
                    }
                    
                    // If we found an entity class, analyze it
                    if ($entityClass && class_exists($entityClass)) {
                        error_log("Analyzing entity: {$entityClass}");
                        $this->analyzeEntity($entityClass);
                        $entityClassesFound[] = $entityClass;
                    } else {
                        // If we can't parse, try to infer from method name
                        // This is a fallback - not as reliable
                        $entityClass = $this->inferEntityClassFromMethodName($methodName, $useStatements);
                        if ($entityClass && class_exists($entityClass)) {
                            error_log("Method {$methodName}: Inferred entity class {$entityClass} from method name");
                            $this->analyzeEntity($entityClass);
                            $entityClassesFound[] = $entityClass;
                        } else {
                            error_log("Method {$methodName}: Could not find entity class");
                        }
                    }
                } catch (\Exception $e) {
                    error_log("Error parsing method {$methodName}: " . $e->getMessage());
                    // If we can't parse, try to infer from method name
                    $entityClass = $this->inferEntityClassFromMethodName($methodName, $useStatements);
                    if ($entityClass && class_exists($entityClass)) {
                        $this->analyzeEntity($entityClass);
                        $entityClassesFound[] = $entityClass;
                    }
                }
            }
            
            error_log("Found " . count($entityClassesFound) . " entity classes from DbSet methods: " . implode(', ', $entityClassesFound));
            
            // Also analyze onModelCreating to get entities configured there
            $this->analyzeOnModelCreating($reflection, $useStatements);
            
            error_log("Total entities after analysis: " . count($this->entities));
        } catch (\Exception $e) {
            error_log("Error analyzing ApplicationDbContext: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
        }
        
        // Also analyze onModelCreating to get Fluent API configurations
        $this->analyzeFluentApi();
    }
    
    /**
     * Get use statements from class file
     */
    private function getUseStatements(ReflectionClass $reflection): array
    {
        $useStatements = [];
        $fileName = $reflection->getFileName();
        
        if ($fileName && file_exists($fileName)) {
            $lines = file($fileName);
            if ($lines) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    // Match: use App\Models\User;
                    if (preg_match('/^use\s+([^;]+);/', $line, $matches)) {
                        $fullClassName = trim($matches[1]);
                        // Extract class name (last part after \)
                        $parts = explode('\\', $fullClassName);
                        $className = end($parts);
                        $useStatements[$className] = $fullClassName;
                    }
                }
            }
        }
        
        return $useStatements;
    }
    
    /**
     * Resolve class name using use statements
     */
    private function resolveClassName(string $classExpression, array $useStatements): ?string
    {
        // Remove leading backslash if present
        $classExpression = ltrim($classExpression, '\\');
        
        // If it's already a fully qualified name, use it
        if (strpos($classExpression, '\\') !== false) {
            return $classExpression;
        }
        
        // Try to resolve from use statements
        if (isset($useStatements[$classExpression])) {
            return $useStatements[$classExpression];
        }
        
        // Default to App\Models namespace
        return 'App\\Models\\' . $classExpression;
    }
    
    /**
     * Analyze onModelCreating method to find entities
     */
    private function analyzeOnModelCreating(ReflectionClass $reflection, array $useStatements): void
    {
        try {
            if (!$reflection->hasMethod('onModelCreating')) {
                return;
            }
            
            $method = $reflection->getMethod('onModelCreating');
            $methodBody = $this->getMethodBody($method);
            
            if (empty($methodBody)) {
                return;
            }
            
            // Find all $this->entity(...) calls
            // Pattern: $this->entity(User::class) or $this->entity(\App\Models\User::class)
            if (preg_match_all('/\$this->entity\(([^\)]+)\)/', $methodBody, $matches)) {
                foreach ($matches[1] as $classExpression) {
                    $classExpression = trim($classExpression);
                    
                    // Remove ::class suffix if present
                    $classExpression = preg_replace('/::class\s*$/', '', $classExpression);
                    $classExpression = trim($classExpression);
                    
                    // Resolve class name
                    $entityClass = $this->resolveClassName($classExpression, $useStatements);
                    
                    if ($entityClass && class_exists($entityClass)) {
                        $this->analyzeEntity($entityClass);
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("Error analyzing onModelCreating: " . $e->getMessage());
        }
    }

    /**
     * Get method body as string (simplified - reads from file)
     */
    private function getMethodBody(ReflectionMethod $method): string
    {
        try {
            $fileName = $method->getFileName();
            $startLine = $method->getStartLine();
            $endLine = $method->getEndLine();
            
            if ($fileName && $startLine && $endLine && file_exists($fileName)) {
                $lines = file($fileName);
                if ($lines && count($lines) >= $endLine) {
                    $methodLines = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
                    return implode('', $methodLines);
                }
            }
        } catch (\Exception $e) {
            // If we can't read the file, return empty
        }
        
        return '';
    }

    /**
     * Infer entity class from method name (fallback)
     */
    private function inferEntityClassFromMethodName(string $methodName, array $useStatements): ?string
    {
        // Convert method name to class name
        // Users -> User, Companies -> Company, etc.
        $className = rtrim($methodName, 's');
        
        // Try to resolve from use statements first
        if (isset($useStatements[$className])) {
            return $useStatements[$className];
        }
        
        // Try App\Models namespace
        $fullClassName = 'App\\Models\\' . $className;
        if (class_exists($fullClassName)) {
            return $fullClassName;
        }
        
        return null;
    }

    /**
     * Analyze entity class using Reflection and Attributes
     */
    private function analyzeEntity(string $entityClass): void
    {
        if (!class_exists($entityClass)) {
            error_log("Entity class does not exist: {$entityClass}");
            return;
        }
        try {
            $reflection = new ReflectionClass($entityClass);
            
            // Get table name from Table attribute
            $tableName = $this->getTableName($reflection);
            if (!$tableName) {
                error_log("No table name found for entity: {$entityClass}");
                return; // Skip if no table name
            }
            
            error_log("Analyzing entity {$entityClass} with table name: {$tableName}");
            $entityInfo = [
                'class' => $entityClass,
                'table' => $tableName,
                'columns' => [],
                'primaryKey' => null,
                'foreignKeys' => [],
                'indexes' => []
            ];

            // Analyze properties
            $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);
            
            foreach ($properties as $property) {
                // Skip navigation properties (they are objects or arrays)
                if ($this->isNavigationProperty($property)) {
                    continue;
                }
                try {
                    $columnInfo = $this->analyzeProperty($property);
                    if ($columnInfo) {
                        $entityInfo['columns'][] = $columnInfo;
                        
                        // Check if it's a primary key
                        if ($columnInfo['isPrimaryKey']) {
                            $entityInfo['primaryKey'] = $columnInfo['name'];
                        }
                        
                        // Check if it's a foreign key
                        if ($columnInfo['isForeignKey']) {
                            $entityInfo['foreignKeys'][] = [
                                'column' => $columnInfo['name'],
                                'referencedTable' => $columnInfo['referencedTable'] ?? null,
                                'referencedColumn' => $columnInfo['referencedColumn'] ?? 'Id',
                                'onDelete' => $columnInfo['onDelete'] ?? 'CASCADE'
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    // Skip this property if analysis fails
                    continue;
                }
            }

            // Get indexes from Index attributes
            try {
                $indexes = $this->getIndexes($reflection);
                $entityInfo['indexes'] = $indexes;
            } catch (\Exception $e) {
                $entityInfo['indexes'] = [];
            }

            // Get audit fields
            try {
                $auditFields = $this->getAuditFields($reflection);
                foreach ($auditFields as $field) {
                    $entityInfo['columns'][] = $field;
                }
            } catch (\Exception $e) {
                // Skip audit fields if analysis fails
            }

            $this->entities[$tableName] = $entityInfo;
            error_log("Successfully analyzed entity {$entityClass}: " . count($entityInfo['columns']) . " columns, " . count($entityInfo['foreignKeys']) . " foreign keys, " . count($entityInfo['indexes']) . " indexes");
        } catch (\Exception $e) {
            error_log("Failed to analyze entity {$entityClass}: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
        } catch (\Throwable $e) {
            error_log("Failed to analyze entity {$entityClass} (Throwable): " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Get table name from Table attribute
     */
    private function getTableName(ReflectionClass $reflection): ?string
    {
        try {
            $attributes = $reflection->getAttributes(\Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Table::class);
            if (!empty($attributes)) {
                $attribute = $attributes[0];
                
                // First try to get from constructor arguments (most reliable)
                $args = $attribute->getArguments();
                if (!empty($args) && isset($args[0]) && is_string($args[0])) {
                    return $args[0];
                }
                
                // Try to instantiate and get property
                try {
                    $tableAttr = $attribute->newInstance();
                    if (isset($tableAttr->name) && is_string($tableAttr->name)) {
                        return $tableAttr->name;
                    }
                } catch (\Exception $e) {
                    // If instantiation fails, try with arguments
                    if (!empty($args) && isset($args[0])) {
                        return $args[0];
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("Error getting table name for {$reflection->getName()}: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Check if property is a navigation property
     */
    private function isNavigationProperty(ReflectionProperty $property): bool
    {
        $docComment = $property->getDocComment();
        if ($docComment && (strpos($docComment, '@var') !== false || strpos($docComment, 'InverseProperty') !== false)) {
            return true;
        }
        
        $type = $property->getType();
        if ($type && !$type->isBuiltin()) {
            return true;
        }
        
        return false;
    }

    /**
     * Analyze property to get column information
     */
    private function analyzeProperty(ReflectionProperty $property): ?array
    {
        $columnInfo = [
            'name' => $property->getName(),
            'type' => 'string',
            'maxLength' => null,
            'isRequired' => false,
            'isPrimaryKey' => false,
            'isAutoIncrement' => false,
            'isForeignKey' => false,
            'referencedTable' => null,
            'referencedColumn' => 'Id',
            'onDelete' => 'CASCADE',
            'isNullable' => true
        ];

        // Get type from property type hint
        $type = $property->getType();
        if ($type) {
            $typeName = $type->getName();
            if ($typeName === 'int') {
                $columnInfo['type'] = 'integer';
            } elseif ($typeName === 'string') {
                $columnInfo['type'] = 'string';
            } elseif ($typeName === 'float' || $typeName === 'double') {
                $columnInfo['type'] = 'float';
            } elseif ($typeName === 'bool') {
                $columnInfo['type'] = 'boolean';
            }
            
            $columnInfo['isNullable'] = $type->allowsNull();
        }

        // Analyze attributes
        $attributes = $property->getAttributes();
        foreach ($attributes as $attribute) {
            $attrName = $attribute->getName();
            $args = $attribute->getArguments();
            
            switch ($attrName) {
                case 'Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Key':
                    $columnInfo['isPrimaryKey'] = true;
                    break;
                    
                case 'Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\DatabaseGenerated':
                    $option = $args[0] ?? $args['option'] ?? \Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\DatabaseGenerated::IDENTITY;
                    if ($option === \Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\DatabaseGenerated::IDENTITY) {
                        $columnInfo['isAutoIncrement'] = true;
                    }
                    break;
                    
                case 'Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Column':
                    if (isset($args[0])) {
                        $columnInfo['name'] = $args[0];
                    }
                    if (isset($args[1])) {
                        // Parse column type from VARCHAR(255) format
                        $columnType = $args[1];
                        if (preg_match('/VARCHAR\((\d+)\)/', $columnType, $matches)) {
                            $columnInfo['type'] = 'string';
                            $columnInfo['maxLength'] = (int)$matches[1];
                        } elseif (preg_match('/INT/', $columnType)) {
                            $columnInfo['type'] = 'integer';
                        } elseif (preg_match('/DATETIME/', $columnType)) {
                            $columnInfo['type'] = 'datetime';
                        }
                    }
                    break;
                    
                case 'Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Required':
                    $columnInfo['isRequired'] = true;
                    $columnInfo['isNullable'] = false;
                    break;
                    
                case 'Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\MaxLength':
                    $columnInfo['maxLength'] = $args[0] ?? null;
                    break;
                    
                case 'Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\ForeignKey':
                    $columnInfo['isForeignKey'] = true;
                    // Try to infer referenced table from navigation property name
                    $navProp = $args[0] ?? null;
                    if ($navProp) {
                        $columnInfo['referencedTable'] = $this->inferTableNameFromNavigation($navProp);
                    }
                    break;
            }
        }

        return $columnInfo;
    }

    /**
     * Infer table name from navigation property name
     */
    private function inferTableNameFromNavigation(string $navigationProperty): ?string
    {
        // Simple inference: Company -> Companies, User -> Users
        // This is a basic implementation, can be improved
        $className = ucfirst($navigationProperty);
        if (substr($className, -1) === 'y') {
            return substr($className, 0, -1) . 'ies';
        } elseif (substr($className, -1) === 's') {
            return $className;
        } else {
            return $className . 's';
        }
    }

    /**
     * Get indexes from Index attributes
     */
    private function getIndexes(ReflectionClass $reflection): array
    {
        $indexes = [];
        $attributes = $reflection->getAttributes();
        
        foreach ($attributes as $attribute) {
            if ($attribute->getName() === 'Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\Index') {
                $args = $attribute->getArguments();
                $columns = is_array($args[0]) ? $args[0] : [$args[0]];
                $isUnique = $args['isUnique'] ?? $args[1] ?? false;
                
                $indexName = 'IX_' . $this->getTableName($reflection) . '_' . implode('_', $columns);
                $indexes[] = [
                    'name' => $indexName,
                    'columns' => $columns,
                    'isUnique' => $isUnique
                ];
            }
        }
        
        return $indexes;
    }

    /**
     * Get audit fields (CreatedAt, UpdatedAt, DeletedAt)
     */
    private function getAuditFields(ReflectionClass $reflection): array
    {
        $auditFields = [];
        $attributes = $reflection->getAttributes();
        
        foreach ($attributes as $attribute) {
            if ($attribute->getName() === 'Yakupeyisan\CodeIgniter4\EntityFramework\Attributes\AuditFields') {
                $args = $attribute->getArguments();
                
                if ($args['createdAt'] ?? $args[0] ?? false) {
                    $auditFields[] = [
                        'name' => 'CreatedAt',
                        'type' => 'datetime',
                        'isRequired' => false,
                        'isNullable' => true,
                        'isPrimaryKey' => false,
                        'isAutoIncrement' => false,
                        'isForeignKey' => false
                    ];
                }
                
                if ($args['updatedAt'] ?? $args[1] ?? false) {
                    $auditFields[] = [
                        'name' => 'UpdatedAt',
                        'type' => 'datetime',
                        'isRequired' => false,
                        'isNullable' => true,
                        'isPrimaryKey' => false,
                        'isAutoIncrement' => false,
                        'isForeignKey' => false
                    ];
                }
                
                if ($args['deletedAt'] ?? $args[2] ?? false) {
                    $auditFields[] = [
                        'name' => 'DeletedAt',
                        'type' => 'datetime',
                        'isRequired' => false,
                        'isNullable' => true,
                        'isPrimaryKey' => false,
                        'isAutoIncrement' => false,
                        'isForeignKey' => false
                    ];
                }
            }
        }
        
        return $auditFields;
    }

    /**
     * Analyze Fluent API configuration from onModelCreating
     * This is a simplified version - full implementation would require parsing the Fluent API calls
     */
    private function analyzeFluentApi(): void
    {
        // For now, we rely on attributes
        // Full Fluent API parsing would require more complex reflection/parsing
        // This can be enhanced later
    }

    /**
     * Generate up() method code
     */
    private function generateUpCode(): string
    {
        if (empty($this->entities)) {
            return '';
        }
        
        $code = "";
        
        // Sort entities by dependencies (tables without foreign keys first)
        $sortedEntities = $this->sortEntitiesByDependencies();
        
        foreach ($sortedEntities as $tableName => $entityInfo) {
            // Only generate code for new tables or tables with changes
            if (!$this->tableExists($tableName)) {
                // New table - create it
                $code .= $this->generateTableCreationCode($tableName, $entityInfo);
            } else {
                // Existing table - check for new columns, indexes, foreign keys
                $code .= $this->generateTableAlterationCode($tableName, $entityInfo);
            }
        }
        
        return $code;
    }

    /**
     * Sort entities by dependencies (tables referenced by foreign keys come first)
     */
    private function sortEntitiesByDependencies(): array
    {
        $sorted = [];
        $processed = [];
        
        // First pass: add tables without foreign keys
        foreach ($this->entities as $tableName => $entityInfo) {
            if (empty($entityInfo['foreignKeys'])) {
                $sorted[$tableName] = $entityInfo;
                $processed[$tableName] = true;
            }
        }
        
        // Second pass: add tables with foreign keys (after their dependencies)
        $maxIterations = count($this->entities);
        $iteration = 0;
        
        while (count($processed) < count($this->entities) && $iteration < $maxIterations) {
            foreach ($this->entities as $tableName => $entityInfo) {
                if (isset($processed[$tableName])) {
                    continue;
                }
                
                $canAdd = true;
                foreach ($entityInfo['foreignKeys'] as $fk) {
                    $refTable = $fk['referencedTable'];
                    if ($refTable && !isset($processed[$refTable])) {
                        $canAdd = false;
                        break;
                    }
                }
                
                if ($canAdd) {
                    $sorted[$tableName] = $entityInfo;
                    $processed[$tableName] = true;
                }
            }
            $iteration++;
        }
        
        // Add any remaining entities
        foreach ($this->entities as $tableName => $entityInfo) {
            if (!isset($processed[$tableName])) {
                $sorted[$tableName] = $entityInfo;
            }
        }
        
        return $sorted;
    }

    /**
     * Generate table creation code
     */
    private function generateTableCreationCode(string $tableName, array $entityInfo): string
    {
        $code = "// {$tableName} table\n";
        $code .= "\$builder->createTable('{$tableName}', function(ColumnBuilder \$columns) {\n";
        
        foreach ($entityInfo['columns'] as $column) {
            $code .= $this->generateColumnCode($column);
        }
        
        $code .= "});\n";
        
        // Add indexes
        foreach ($entityInfo['indexes'] as $index) {
            $columnsStr = "['" . implode("', '", $index['columns']) . "']";
            $uniqueStr = $index['isUnique'] ? 'true' : 'false';
            $code .= "\$builder->createIndex('{$tableName}', '{$index['name']}', {$columnsStr}, {$uniqueStr});\n";
        }
        
        // Add foreign keys
        foreach ($entityInfo['foreignKeys'] as $fk) {
            if ($fk['referencedTable']) {
                $code .= "\$builder->addForeignKey(\n";
                $code .= "    '{$tableName}',\n";
                $code .= "    'FK_{$tableName}_{$fk['referencedTable']}',\n";
                $code .= "    ['{$fk['column']}'],\n";
                $code .= "    '{$fk['referencedTable']}',\n";
                $code .= "    ['{$fk['referencedColumn']}'],\n";
                $code .= "    '{$fk['onDelete']}'\n";
                $code .= ");\n";
            }
        }
        
        $code .= "\n";
        
        return $code;
    }

    /**
     * Generate table alteration code (for existing tables)
     */
    private function generateTableAlterationCode(string $tableName, array $entityInfo): string
    {
        $code = "";
        $hasChanges = false;
        
        // Check for new columns
        foreach ($entityInfo['columns'] as $column) {
            if (!$this->columnExists($tableName, $column['name'])) {
                if (!$hasChanges) {
                    $code .= "// {$tableName} table alterations\n";
                    $hasChanges = true;
                }
                
                // Determine column type for addColumn
                $columnType = 'VARCHAR(255)';
                $options = [];
                
                switch ($column['type']) {
                    case 'integer':
                        $columnType = 'INT';
                        break;
                    case 'string':
                        $maxLength = $column['maxLength'] ?? 255;
                        $columnType = "VARCHAR({$maxLength})";
                        break;
                    case 'datetime':
                        $columnType = 'DATETIME';
                        break;
                    case 'float':
                        $columnType = 'FLOAT';
                        break;
                    case 'boolean':
                        $columnType = 'TINYINT(1)';
                        break;
                }
                
                if ($column['isPrimaryKey']) {
                    $options['primary_key'] = true;
                }
                
                if ($column['isAutoIncrement']) {
                    $options['auto_increment'] = true;
                }
                
                if ($column['isRequired'] && !$column['isNullable']) {
                    $options['null'] = false;
                } else {
                    $options['null'] = true;
                }
                
                $optionsStr = var_export($options, true);
                $code .= "\$builder->addColumn('{$tableName}', '{$column['name']}', '{$columnType}', {$optionsStr});\n";
            }
        }
        
        // Check for new indexes (simplified - we don't check existing indexes)
        foreach ($entityInfo['indexes'] as $index) {
            // For simplicity, we'll add all indexes (duplicate index creation will be handled by database)
            if (!$hasChanges) {
                $code .= "// {$tableName} table alterations\n";
                $hasChanges = true;
            }
            $columnsStr = "['" . implode("', '", $index['columns']) . "']";
            $uniqueStr = $index['isUnique'] ? 'true' : 'false';
            $code .= "\$builder->createIndex('{$tableName}', '{$index['name']}', {$columnsStr}, {$uniqueStr});\n";
        }
        
        // Check for new foreign keys (simplified - we don't check existing foreign keys)
        foreach ($entityInfo['foreignKeys'] as $fk) {
            if ($fk['referencedTable']) {
                // For simplicity, we'll add all foreign keys (duplicate FK creation will be handled by database)
                if (!$hasChanges) {
                    $code .= "// {$tableName} table alterations\n";
                    $hasChanges = true;
                }
                $code .= "\$builder->addForeignKey(\n";
                $code .= "    '{$tableName}',\n";
                $code .= "    'FK_{$tableName}_{$fk['referencedTable']}',\n";
                $code .= "    ['{$fk['column']}'],\n";
                $code .= "    '{$fk['referencedTable']}',\n";
                $code .= "    ['{$fk['referencedColumn']}'],\n";
                $code .= "    '{$fk['onDelete']}'\n";
                $code .= ");\n";
            }
        }
        
        if ($hasChanges) {
            $code .= "\n";
        }
        
        return $code;
    }

    /**
     * Generate column code
     */
    private function generateColumnCode(array $column): string
    {
        $code = "    \$columns->";
        
        switch ($column['type']) {
            case 'integer':
                $code .= "integer('{$column['name']}')";
                break;
            case 'string':
                $maxLength = $column['maxLength'] ?? 255;
                $code .= "string('{$column['name']}', {$maxLength})";
                break;
            case 'datetime':
                $code .= "datetime('{$column['name']}')";
                break;
            case 'float':
                $code .= "float('{$column['name']}')";
                break;
            case 'boolean':
                $code .= "boolean('{$column['name']}')";
                break;
            default:
                $code .= "string('{$column['name']}', 255)";
        }
        
        if ($column['isPrimaryKey']) {
            $code .= "->primaryKey()";
        }
        
        if ($column['isAutoIncrement']) {
            $code .= "->autoIncrement()";
        }
        
        // Primary key'ler her zaman not null olmalı
        if ($column['isPrimaryKey']) {
            $code .= "->notNull()";
        } elseif ($column['isRequired'] && !$column['isNullable']) {
            $code .= "->notNull()";
        } else {
            $code .= "->nullable()";
        }
        
        $code .= ";\n";
        
        return $code;
    }

    /**
     * Generate down() method code
     */
    private function generateDownCode(): string
    {
        if (empty($this->entities)) {
            return '';
        }
        
        $code = "";
        
        // Reverse order (drop tables in reverse dependency order)
        $sortedEntities = array_reverse($this->sortEntitiesByDependencies(), true);
        
        $code .= "// Rollback changes (drop new tables, remove alterations)\n";
        foreach ($sortedEntities as $tableName => $entityInfo) {
            if (!$this->tableExists($tableName)) {
                // New table - drop it
                $code .= "\$builder->dropTable('{$tableName}');\n";
            } else {
                // Existing table - rollback alterations (remove new columns, indexes, foreign keys)
                $code .= $this->generateTableRollbackCode($tableName, $entityInfo);
            }
        }
        
        return $code;
    }

    /**
     * Generate table rollback code (for existing tables)
     */
    private function generateTableRollbackCode(string $tableName, array $entityInfo): string
    {
        $code = "";
        $hasChanges = false;
        
        // Rollback new columns (in reverse order)
        $newColumns = [];
        foreach ($entityInfo['columns'] as $column) {
            if (!$this->columnExists($tableName, $column['name'])) {
                $newColumns[] = $column;
            }
        }
        
        if (!empty($newColumns)) {
            $code .= "// Rollback {$tableName} table alterations\n";
            $hasChanges = true;
            
            // Remove foreign keys first (if they were added)
            foreach (array_reverse($entityInfo['foreignKeys']) as $fk) {
                if ($fk['referencedTable']) {
                    $code .= "\$builder->dropForeignKey('{$tableName}', 'FK_{$tableName}_{$fk['referencedTable']}');\n";
                }
            }
            
            // Remove indexes
            foreach (array_reverse($entityInfo['indexes']) as $index) {
                $code .= "\$builder->dropIndex('{$tableName}', '{$index['name']}');\n";
            }
            
            // Remove columns
            foreach (array_reverse($newColumns) as $column) {
                $code .= "\$builder->dropColumn('{$tableName}', '{$column['name']}');\n";
            }
        }
        
        if ($hasChanges) {
            $code .= "\n";
        }
        
        return $code;
    }
}

