<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Migrations;

use Yakupeyisan\CodeIgniter4\EntityFramework\Core\PdoAdapter;

/**
 * MigrationBuilder - Fluent API for building migrations
 * Equivalent to MigrationBuilder in EF Core
 */
class MigrationBuilder
{
    private PdoAdapter $connection;
    private array $operations = [];

    public function __construct(PdoAdapter $connection)
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
        }
    }

    /**
     * Execute create table
     */
    private function executeCreateTable(array $operation): void
    {
        $driver = strtolower($this->connection->getPlatform() ?? '');
        
        if ($driver === 'sqlsrv' || $driver === 'sqlserver') {
            $this->executeCreateTableSqlServer($operation);
            return;
        }

        if (!is_callable($operation['columns'])) {
            return;
        }

        $columnBuilder = new ColumnBuilder();
        $operation['columns']($columnBuilder);
        $fields = $columnBuilder->getFields();

        $columns = [];
        $primaryKeys = [];
        foreach ($fields as $fieldName => $fieldConfig) {
            $type = $fieldConfig['type'] ?? 'VARCHAR(255)';
            $isPrimary = isset($fieldConfig['primary_key']) && $fieldConfig['primary_key'];
            $isAutoIncrement = isset($fieldConfig['auto_increment']) && $fieldConfig['auto_increment'];
            $isNull = isset($fieldConfig['null']) ? (bool) $fieldConfig['null'] : true;

            $columnDef = "`{$fieldName}` {$type}";
            if ($isAutoIncrement) {
                $columnDef .= ' AUTO_INCREMENT';
            }
            $columnDef .= $isNull ? ' NULL' : ' NOT NULL';

            if (array_key_exists('default', $fieldConfig) && $fieldConfig['default'] !== null) {
                $default = $fieldConfig['default'];
                $columnDef .= is_numeric($default) ? " DEFAULT {$default}" : " DEFAULT '" . str_replace("'", "''", (string)$default) . "'";
            }

            if ($isPrimary) {
                $primaryKeys[] = $fieldName;
            }
            $columns[] = $columnDef;
        }

        if (!empty($primaryKeys)) {
            $pkSql = implode(', ', array_map(fn($pk) => "`{$pk}`", $primaryKeys));
            $columns[] = "PRIMARY KEY ({$pkSql})";
        }

        $sql = "CREATE TABLE IF NOT EXISTS `{$operation['name']}` (\n    " . implode(",\n    ", $columns) . "\n)";
        $this->connection->query($sql);
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
            $type = $fieldConfig['type'] ?? 'INT';
            $isPrimary = isset($fieldConfig['primary_key']) && $fieldConfig['primary_key'];
            $isAutoIncrement = isset($fieldConfig['auto_increment']) && $fieldConfig['auto_increment'];
            $isNull = isset($fieldConfig['null']) ? $fieldConfig['null'] : true;
            $default = $fieldConfig['default'] ?? null;
            
            $columnDef = "[{$fieldName}] {$type}";
            
            // IDENTITY columns must be NOT NULL in SQL Server
            if ($isAutoIncrement && $isPrimary) {
                $columnDef .= " IDENTITY(1,1) NOT NULL";
            } else {
                if (!$isNull) {
                    $columnDef .= " NOT NULL";
                } else {
                    $columnDef .= " NULL";
                }
            }
            
            if ($default !== null) {
                if (is_string($default)) {
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
        $type = $operation['columnType'];
        $null = ($operation['options']['null'] ?? true) ? 'NULL' : 'NOT NULL';
        $sql = "ALTER TABLE `{$operation['table']}` ADD COLUMN `{$operation['name']}` {$type} {$null}";
        $this->connection->query($sql);
    }

    /**
     * Execute create index
     */
    private function executeCreateIndex(array $operation): void
    {
        $unique = $operation['isUnique'] ? 'UNIQUE ' : '';
        $columns = implode(', ', array_map(fn($c) => "`{$c}`", $operation['columns']));
        $sql = "CREATE {$unique}INDEX `{$operation['name']}` ON `{$operation['table']}` ({$columns})";
        $this->connection->query($sql);
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
            $columns = implode(', ', array_map(fn($c) => "`{$c}`", $operation['columns']));
            $refColumns = implode(', ', array_map(fn($c) => "`{$c}`", $operation['referencedColumns']));
            $sql = "ALTER TABLE `{$operation['table']}` ADD CONSTRAINT `{$operation['name']}` FOREIGN KEY ({$columns}) REFERENCES `{$operation['referencedTable']}` ({$refColumns}) ON DELETE {$operation['onDelete']}";
            $this->connection->query($sql);
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
        
        // Check if foreign key already exists - use more specific query with schema
        $checkSql = "SELECT COUNT(*) as cnt FROM sys.foreign_keys fk 
                     INNER JOIN sys.tables t ON fk.parent_object_id = t.object_id 
                     WHERE fk.name = N'{$name}' AND t.name = N'{$table}'";
        $result = $this->connection->query($checkSql)->getRow();
        if ($result && $result->cnt > 0) {
            log_message('debug', "Foreign key [{$name}] already exists on table [{$table}], skipping...");
            return;
        }
        
        // Map onDelete values to SQL Server syntax
        $onDeleteMap = [
            'CASCADE' => 'CASCADE',
            'SET NULL' => 'SET NULL',
            'RESTRICT' => 'NO ACTION',
            'NO ACTION' => 'NO ACTION'
        ];
        $onDeleteSql = $onDeleteMap[$onDelete] ?? 'NO ACTION';
        
        // Build column lists
        $columnList = implode(', ', array_map(fn($col) => "[{$col}]", $columns));
        $referencedColumnList = implode(', ', array_map(fn($col) => "[{$col}]", $referencedColumns));
        
        $sql = "ALTER TABLE [{$table}] " .
               "ADD CONSTRAINT [{$name}] " .
               "FOREIGN KEY ({$columnList}) " .
               "REFERENCES [{$referencedTable}] ({$referencedColumnList}) " .
               "ON DELETE {$onDeleteSql}";
        
        log_message('debug', "Creating foreign key: {$sql}");
        
        try {
            // Execute the query and check result
            $result = $this->connection->query($sql);
            
            // Check if query was successful
            if ($result === false) {
                $error = $this->connection->error();
                log_message('error', "Query returned false for foreign key [{$name}]: " . json_encode($error));
                throw new \RuntimeException("Failed to execute foreign key creation query");
            }
            
            log_message('debug', "Foreign key [{$name}] query executed successfully");
            
            // Force commit for SQL Server (DDL statements auto-commit, but let's be sure)
            $this->connection->transCommit();
            
            // Wait a bit for SQL Server to commit (if needed)
            usleep(100000); // 100ms
            
            // Verify foreign key was created - use more specific query with current database context
            $dbName = $this->connection->getDatabase();
            $verifySql = "SELECT COUNT(*) as cnt 
                          FROM [{$dbName}].sys.foreign_keys fk 
                          INNER JOIN [{$dbName}].sys.tables t ON fk.parent_object_id = t.object_id 
                          WHERE fk.name = N'{$name}' AND t.name = N'{$table}'";
            $verifyResult = $this->connection->query($verifySql)->getRow();
            
            log_message('debug', "Verification query for [{$name}]: {$verifySql}");
            log_message('debug', "Verification result: " . json_encode($verifyResult));
            log_message('debug', "Current database: {$dbName}");
            
            if ($verifyResult && $verifyResult->cnt > 0) {
                log_message('debug', "Foreign key [{$name}] verified in database [{$dbName}]");
            } else {
                log_message('error', "Foreign key [{$name}] was not found in database [{$dbName}] after creation!");
                
                // Try to get more info about the table
                $tableCheckSql = "SELECT OBJECT_ID(N'{$table}') as table_id, SCHEMA_NAME(schema_id) as schema_name, name 
                                  FROM sys.tables WHERE name = N'{$table}'";
                $tableResult = $this->connection->query($tableCheckSql)->getRow();
                log_message('error', "Table [{$table}] info: " . json_encode($tableResult));
                
                // List all foreign keys on this table
                $allFkSql = "SELECT fk.name, t.name as table_name, SCHEMA_NAME(t.schema_id) as schema_name 
                             FROM sys.foreign_keys fk 
                             INNER JOIN sys.tables t ON fk.parent_object_id = t.object_id 
                             WHERE t.name = N'{$table}'";
                $allFkResult = $this->connection->query($allFkSql)->getResultArray();
                log_message('error', "All foreign keys on table [{$table}]: " . json_encode($allFkResult));
                
                // Also check if the constraint exists with a different name
                $constraintSql = "SELECT name, type_desc FROM sys.objects WHERE parent_object_id = OBJECT_ID(N'{$table}') AND type = 'F'";
                $constraintResult = $this->connection->query($constraintSql)->getResultArray();
                log_message('error', "All constraints (type F) on table [{$table}]: " . json_encode($constraintResult));
            }
        } catch (\Exception $e) {
            log_message('error', "Failed to create foreign key [{$name}]: " . $e->getMessage());
            log_message('error', "SQL: {$sql}");
            log_message('error', "Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }
}

