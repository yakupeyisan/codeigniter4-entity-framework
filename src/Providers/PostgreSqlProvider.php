<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Providers;

use CodeIgniter\Database\BaseConnection;

/**
 * PostgreSQL Database Provider
 */
class PostgreSqlProvider implements DatabaseProvider
{
    public function getName(): string
    {
        return 'PostgreSQL';
    }

    public function supports(BaseConnection $connection): bool
    {
        $driver = strtolower($connection->getPlatform() ?? '');
        return $driver === 'postgre' || $driver === 'pgsql';
    }

    public function escapeIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function getLimitClause(int $limit, ?int $offset = null): string
    {
        if ($offset !== null) {
            return "LIMIT {$limit} OFFSET {$offset}";
        }
        return "LIMIT {$limit}";
    }

    public function getExplainSql(string $sql): string
    {
        return "EXPLAIN ANALYZE " . $sql;
    }

    public function getBatchUpdateSql(string $tableName, array $data, string $primaryKey, array $columns): string
    {
        // PostgreSQL uses similar CASE WHEN approach as MySQL
        if (empty($data)) {
            return '';
        }

        $quotedTable = $this->escapeIdentifier($tableName);
        $quotedPk = $this->escapeIdentifier($primaryKey);
        
        $ids = [];
        $cases = [];
        
        foreach ($columns as $column) {
            $cases[$column] = [];
        }

        foreach ($data as $row) {
            $id = $row[$primaryKey] ?? null;
            if ($id === null) {
                continue;
            }
            
            $ids[] = $this->escapeValue($id);
            
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                $escapedValue = $value === null ? 'NULL' : $this->escapeValue($value);
                $cases[$column][] = "WHEN {$quotedPk} = {$this->escapeValue($id)} THEN {$escapedValue}";
            }
        }

        if (empty($ids)) {
            return '';
        }

        $idsList = implode(',', $ids);
        $setClauses = [];
        
        foreach ($columns as $column) {
            $quotedCol = $this->escapeIdentifier($column);
            $caseWhen = implode(' ', $cases[$column]);
            $setClauses[] = "{$quotedCol} = CASE {$caseWhen} ELSE {$quotedCol} END";
        }

        $setClause = implode(', ', $setClauses);
        return "UPDATE {$quotedTable} SET {$setClause} WHERE {$quotedPk} IN ({$idsList})";
    }

    public function getCreateDatabaseSql(string $databaseName): string
    {
        $dbName = $this->escapeIdentifier($databaseName);
        return "CREATE DATABASE {$dbName}";
    }

    public function getCheckDatabaseExistsSql(string $databaseName): string
    {
        return "SELECT datname FROM pg_database WHERE datname = " . $this->escapeValue($databaseName);
    }

    public function getColumnType(string $type, ?int $length = null, ?int $precision = null, ?int $scale = null): string
    {
        $typeMap = [
            'string' => 'VARCHAR',
            'int' => 'INTEGER',
            'integer' => 'INTEGER',
            'bigint' => 'BIGINT',
            'float' => 'REAL',
            'double' => 'DOUBLE PRECISION',
            'decimal' => 'DECIMAL',
            'bool' => 'BOOLEAN',
            'boolean' => 'BOOLEAN',
            'datetime' => 'TIMESTAMP',
            'date' => 'DATE',
            'time' => 'TIME',
            'text' => 'TEXT',
            'blob' => 'BYTEA',
        ];

        $sqlType = $typeMap[strtolower($type)] ?? strtoupper($type);

        if ($length !== null && in_array(strtolower($type), ['string', 'varchar'])) {
            return "{$sqlType}({$length})";
        }

        if ($precision !== null && $scale !== null && in_array(strtolower($type), ['decimal', 'numeric'])) {
            return "{$sqlType}({$precision}, {$scale})";
        }

        return $sqlType;
    }

    public function getAutoIncrementKeyword(): string
    {
        return 'SERIAL'; // Or use GENERATED ALWAYS AS IDENTITY for newer versions
    }

    public function getStringConcatOperator(): string
    {
        return '||'; // PostgreSQL uses || for string concatenation
    }

    private function escapeValue($value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_numeric($value)) {
            return (string)$value;
        }
        return "'" . str_replace("'", "''", $value) . "'";
    }
}

