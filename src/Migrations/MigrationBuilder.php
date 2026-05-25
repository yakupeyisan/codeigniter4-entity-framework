<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Migrations;

use CodeIgniter\Database\BaseConnection;

/**
 * MigrationBuilder - Fluent API for building migrations
 * Equivalent to MigrationBuilder in EF Core
 */
class MigrationBuilder
{
    private BaseConnection $connection;
    private array $operations = [];

    public function __construct(BaseConnection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Create table
     */
    public function createTable(string $name, callable $columns): self
    {
        $this->operations[] = [
            'type' => 'createTable',
            'name' => $name,
            'columns' => $columns
        ];
        return $this;
    }

    /**
     * Drop table
     */
    public function dropTable(string $name): self
    {
        $this->operations[] = [
            'type' => 'dropTable',
            'name' => $name
        ];
        return $this;
    }

    /**
     * Add column
     */
    public function addColumn(string $table, string $name, string $type, array $options = []): self
    {
        $this->operations[] = [
            'type' => 'addColumn',
            'table' => $table,
            'name' => $name,
            'columnType' => $type,
            'options' => $options
        ];
        return $this;
    }

    /**
     * Drop column
     */
    public function dropColumn(string $table, string $name): self
    {
        $this->operations[] = [
            'type' => 'dropColumn',
            'table' => $table,
            'name' => $name
        ];
        return $this;
    }

    /**
     * Create index
     */
    public function createIndex(string $table, string $name, array $columns, bool $isUnique = false): self
    {
        $this->operations[] = [
            'type' => 'createIndex',
            'table' => $table,
            'name' => $name,
            'columns' => $columns,
            'isUnique' => $isUnique
        ];
        return $this;
    }

    /**
     * Drop index
     */
    public function dropIndex(string $table, string $name): self
    {
        $this->operations[] = [
            'type' => 'dropIndex',
            'table' => $table,
            'name' => $name
        ];
        return $this;
    }

    /**
     * Add foreign key
     */
    public function addForeignKey(string $table, string $name, array $columns, string $referencedTable, array $referencedColumns, string $onDelete = 'CASCADE'): self
    {
        $this->operations[] = [
            'type' => 'addForeignKey',
            'table' => $table,
            'name' => $name,
            'columns' => $columns,
            'referencedTable' => $referencedTable,
            'referencedColumns' => $referencedColumns,
            'onDelete' => $onDelete
        ];
        return $this;
    }

    /**
     * Drop foreign key
     */
    public function dropForeignKey(string $table, string $name): self
    {
        $this->operations[] = [
            'type' => 'dropForeignKey',
            'table' => $table,
            'name' => $name
        ];
        return $this;
    }

    /**
     * Execute raw SQL for views, functions, sequences (from GenerateQuery attributes).
     */
    public function executeSql(string $sql, string $objectType, string $objectName, string $schema = 'dbo'): self
    {
        $this->operations[] = [
            'type' => 'executeSql',
            'sql' => $sql,
            'objectType' => $objectType,
            'objectName' => $objectName,
            'schema' => $schema,
        ];

        return $this;
    }

    /**
     * Execute operations
     */
    public function execute(): void
    {
        foreach ($this->operations as $operation) {
            try {
                $this->executeOperation($operation);
            } catch (\Exception $e) {
                // Log error but continue with other operations
                log_message('error', "Migration operation failed: " . $e->getMessage());
                log_message('error', "Operation: " . json_encode($operation));
                throw $e; // Re-throw to stop migration
            }
        }
    }

    /**
     * Execute single operation
     */
    private function executeOperation(array $operation): void
    {
        $driver = strtolower($this->connection->getPlatform() ?? '');
        $isSqlServer = ($driver === 'sqlsrv' || $driver === 'sqlserver');
        
        switch ($operation['type']) {
            case 'createTable':
                $this->executeCreateTable($operation);
                break;
            case 'dropTable':
                if ($isSqlServer) {
                    $this->connection->query("IF OBJECT_ID('{$operation['name']}', 'U') IS NOT NULL DROP TABLE [{$operation['name']}]");
                } else {
                    $this->connection->query("DROP TABLE IF EXISTS `{$operation['name']}`");
                }
                break;
            case 'addColumn':
                $this->executeAddColumn($operation);
                break;
            case 'dropColumn':
                if ($isSqlServer) {
                    $this->connection->query("ALTER TABLE [{$operation['table']}] DROP COLUMN [{$operation['name']}]");
                } else {
                    $this->connection->query("ALTER TABLE `{$operation['table']}` DROP COLUMN `{$operation['name']}`");
                }
                break;
            case 'createIndex':
                $this->executeCreateIndex($operation);
                break;
            case 'dropIndex':
                if ($isSqlServer) {
                    $this->connection->query("DROP INDEX [{$operation['name']}] ON [{$operation['table']}]");
                } else {
                    $this->connection->query("DROP INDEX `{$operation['name']}` ON `{$operation['table']}`");
                }
                break;
            case 'addForeignKey':
                $this->executeAddForeignKey($operation);
                break;
            case 'dropForeignKey':
                if ($isSqlServer) {
                    $this->connection->query("ALTER TABLE [{$operation['table']}] DROP CONSTRAINT [{$operation['name']}]");
                } else {
                    $this->connection->query("ALTER TABLE `{$operation['table']}` DROP FOREIGN KEY `{$operation['name']}`");
                }
                break;
            case 'executeSql':
                $this->executeExecuteSql($operation);
                break;
        }
    }

    private function executeExecuteSql(array $operation): void
    {
        $driver = strtolower($this->connection->getPlatform() ?? '');
        if ($driver !== 'sqlsrv' && $driver !== 'sqlserver') {
            $this->connection->query($operation['sql']);

            return;
        }

        $executor = new SqlBatchExecutor($this->connection);
        $executor->execute(
            $operation['sql'],
            $operation['objectType'] ?? 'function',
            $operation['objectName'] ?? '',
            true
        );
    }

    /**
     * Execute create table
     */
    private function executeCreateTable(array $operation): void
    {
        $driver = strtolower($this->connection->getPlatform() ?? '');
        
        // SQL Server uses IDENTITY instead of AUTO_INCREMENT
        if ($driver === 'sqlsrv' || $driver === 'sqlserver') {
            $this->executeCreateTableSqlServer($operation);
        } else {
            // MySQL and other databases
            $builder = new \CodeIgniter\Database\Forge($this->connection);
            $fields = [];
            $primaryKeys = [];
            
            if (is_callable($operation['columns'])) {
                $columnBuilder = new ColumnBuilder();
                $operation['columns']($columnBuilder);
                $fields = $columnBuilder->getFields();
                
                // Extract primary keys
                foreach ($fields as $fieldName => $fieldConfig) {
                    if (isset($fieldConfig['primary_key']) && $fieldConfig['primary_key']) {
                        $primaryKeys[] = $fieldName;
                        unset($fields[$fieldName]['primary_key']);
                    }
                }
            }
            
            $builder->addField($fields);
            
            // Add primary key if exists
            if (!empty($primaryKeys)) {
                $builder->addKey($primaryKeys, true);
            }
            
            $builder->createTable($operation['name']);
        }
    }
    
    /**
     * Execute create table for SQL Server
     */
    private function executeCreateTableSqlServer(array $operation): void
    {
        if (!is_callable($operation['columns'])) {
            return;
        }
        
        $columnBuilder = new ColumnBuilder();
        $operation['columns']($columnBuilder);
        $fields = $columnBuilder->getFields();
        
        $columns = [];
        $primaryKeys = [];
        
        foreach ($fields as $fieldName => $fieldConfig) {
            $type = $this->mapSqlServerColumnType($fieldConfig['type'] ?? 'INT');
            $isPrimary = isset($fieldConfig['primary_key']) && $fieldConfig['primary_key'];
            $isAutoIncrement = isset($fieldConfig['auto_increment']) && $fieldConfig['auto_increment'];
            $isNull = isset($fieldConfig['null']) ? $fieldConfig['null'] : true;
            $default = $fieldConfig['default'] ?? null;
            
            $columnDef = "[{$fieldName}] {$type}";
            
            // IDENTITY only on numeric PK columns (never on string/uniqueidentifier)
            if ($isAutoIncrement && $isPrimary && $this->sqlServerTypeSupportsIdentity($type)) {
                $columnDef .= " IDENTITY(1,1) NOT NULL";
            } else {
                if (!$isNull) {
                    $columnDef .= " NOT NULL";
                } else {
                    $columnDef .= " NULL";
                }
            }
            
            if (! empty($fieldConfig['default_newid'])) {
                $columnDef .= ' DEFAULT NEWID()';
            } elseif ($default !== null && $default !== '') {
                $isDateTime = preg_match('/^DATETIME/i', $type) === 1;
                if ($isDateTime && is_string($default) && trim($default) === '') {
                    // Skip invalid empty-string default on DATETIME (SQL Server conversion error).
                } elseif (is_string($default)) {
                    $columnDef .= " DEFAULT '{$default}'";
                } else {
                    $columnDef .= " DEFAULT {$default}";
                }
            }
            
            if ($isPrimary) {
                $primaryKeys[] = $fieldName;
            }
            
            $columns[] = $columnDef;
        }
        
        $tableName = $operation['name'];

        if ($this->sqlServerTableExists($tableName)) {
            log_message('info', "Migration: table [{$tableName}] already exists, skipping CREATE.");

            return;
        }

        $sql = "CREATE TABLE [{$tableName}] (\n    " . implode(",\n    ", $columns);
        
        if (!empty($primaryKeys)) {
            $pkColumns = implode(', ', array_map(fn($col) => "[{$col}]", $primaryKeys));
            $sql .= ",\n    PRIMARY KEY ({$pkColumns})";
        }
        
        $sql .= "\n)";
        
        $this->connection->query($sql);
    }

    /**
     * Execute add column
     */
    private function executeAddColumn(array $operation): void
    {
        $builder = new \CodeIgniter\Database\Forge($this->connection);
        $field = [
            $operation['name'] => [
                'type' => $operation['columnType'],
                ...$operation['options']
            ]
        ];
        $builder->addColumn($operation['table'], $field);
    }

    /**
     * Execute create index
     */
    private function executeCreateIndex(array $operation): void
    {
        $builder = new \CodeIgniter\Database\Forge($this->connection);
        $builder->addKey($operation['columns'], $operation['isUnique'], false, $operation['name'], $operation['table']);
    }

    /**
     * Execute add foreign key
     */
    private function executeAddForeignKey(array $operation): void
    {
        $driver = strtolower($this->connection->getPlatform() ?? '');
        
        // SQL Server uses different syntax
        if ($driver === 'sqlsrv' || $driver === 'sqlserver') {
            $this->executeAddForeignKeySqlServer($operation);
        } else {
            $builder = new \CodeIgniter\Database\Forge($this->connection);
            $builder->addForeignKey(
                $operation['columns'],
                $operation['referencedTable'],
                $operation['referencedColumns'],
                $operation['onDelete'],
                $operation['name']
            );
        }
    }
    
    /**
     * Execute add foreign key for SQL Server
     */
    private function executeAddForeignKeySqlServer(array $operation): void
    {
        $table = $operation['table'];
        $name = $operation['name'];
        $columns = $operation['columns'];
        $referencedTable = $operation['referencedTable'];
        $referencedColumns = $operation['referencedColumns'];
        $onDelete = $operation['onDelete'];

        if ($this->sqlServerForeignKeyExists($name, $table)) {
            log_message('info', "Migration: FK [{$name}] on [{$table}] already exists, skipping.");

            return;
        }

        $onDeleteMap = [
            'CASCADE' => 'CASCADE',
            'SET NULL' => 'SET NULL',
            'RESTRICT' => 'NO ACTION',
            'NO ACTION' => 'NO ACTION',
        ];
        $onDeleteSql = $onDeleteMap[$onDelete] ?? 'NO ACTION';

        $columnList = implode(', ', array_map(fn($col) => "[{$col}]", $columns));
        $referencedColumnList = implode(', ', array_map(fn($col) => "[{$col}]", $referencedColumns));

        $sql = "ALTER TABLE [{$table}] " .
               "ADD CONSTRAINT [{$name}] " .
               "FOREIGN KEY ({$columnList}) " .
               "REFERENCES [{$referencedTable}] ({$referencedColumnList}) " .
               "ON DELETE {$onDeleteSql}";

        try {
            $this->connection->query($sql);
        } catch (\Throwable $e) {
            if ($this->isSqlServerAlreadyExistsError($e) || $this->sqlServerForeignKeyExists($name, $table)) {
                log_message('info', "Migration: FK [{$name}] already exists (caught), skipping.");

                return;
            }

            log_message('error', "Failed to create foreign key [{$name}]: " . $e->getMessage());
            log_message('error', "SQL: {$sql}");

            throw $e;
        }
    }

    private function sqlServerForeignKeyExists(string $name, string $table): bool
    {
        $sql = "SELECT COUNT(*) AS cnt
                FROM sys.foreign_keys fk
                INNER JOIN sys.tables t ON fk.parent_object_id = t.object_id
                WHERE fk.name = ? AND t.name = ?";

        $row = $this->connection->query($sql, [$name, $table])->getRowArray();

        return $this->sqlServerQueryCount($row) > 0;
    }

    private function sqlServerQueryCount(?array $row): int
    {
        if ($row === null) {
            return 0;
        }

        return (int) ($row['cnt'] ?? $row['CNT'] ?? 0);
    }

    private function isSqlServerAlreadyExistsError(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'already exists')
            || str_contains($msg, 'zaten var')
            || str_contains($msg, 'there is already');
    }

    private function sqlServerTableExists(string $tableName): bool
    {
        $row = $this->connection->query(
            'SELECT OBJECT_ID(?, \'U\') AS oid',
            [$tableName]
        )->getRowArray();

        return ! empty($row['oid']);
    }

    /**
     * SQL Server: TINYINT(1) / BOOLEAN → BIT; TINYINT(n) → TINYINT (width not allowed).
     */
    private function mapSqlServerColumnType(string $type): string
    {
        $normalized = strtoupper(trim($type));

        if ($normalized === 'TINYINT(1)' || $normalized === 'BOOLEAN' || $normalized === 'BOOL') {
            return 'BIT';
        }

        if (preg_match('/^TINYINT\s*\(\s*\d+\s*\)$/i', $type)) {
            return 'TINYINT';
        }

        if (preg_match('/^TIME(\(\d+\))?$/i', $normalized, $matches)) {
            return isset($matches[1]) ? strtoupper($type) : 'TIME(0)';
        }

        return $type;
    }

    private function sqlServerTypeSupportsIdentity(string $type): bool
    {
        $normalized = strtoupper(preg_replace('/\s+/', '', $type));

        return (bool) preg_match(
            '/^(TINYINT|SMALLINT|INT|INTEGER|BIGINT|DECIMAL|NUMERIC)(\(|$)/',
            $normalized
        );
    }
}

