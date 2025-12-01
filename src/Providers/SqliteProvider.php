<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Providers;

use CodeIgniter\Database\BaseConnection;

/**
 * SQLite Database Provider
 */
class SqliteProvider implements DatabaseProvider
{
    public function getName(): string
    {
        return 'SQLite';
    }

    public function supports(BaseConnection $connection): bool
    {
        $driver = strtolower($connection->getPlatform() ?? '');
        return $driver === 'sqlite' || $driver === 'sqlite3';
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
        return "EXPLAIN QUERY PLAN " . $sql;
    }

    public function getBatchUpdateSql(string $tableName, array $data, string $primaryKey, array $columns): string
    {
        // SQLite doesn't support CASE WHEN in UPDATE efficiently
        // Use individual UPDATE statements or a transaction
        if (empty($data)) {
            return '';
        }

        $quotedTable = $this->escapeIdentifier($tableName);
        $quotedPk = $this->escapeIdentifier($primaryKey);
        
        // SQLite batch update using CASE WHEN (similar to MySQL/PostgreSQL)
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
        // SQLite creates database file automatically
        return '';
    }

    public function getCheckDatabaseExistsSql(string $databaseName): string
    {
        // SQLite doesn't have database concept like other databases
        // Check if file exists instead
        return "SELECT 1";
    }

    public function getColumnType(string $type, ?int $length = null, ?int $precision = null, ?int $scale = null): string
    {
        // SQLite has dynamic typing, but we provide type hints
        $typeMap = [
            'string' => 'TEXT',
            'int' => 'INTEGER',
            'integer' => 'INTEGER',
            'bigint' => 'INTEGER',
            'float' => 'REAL',
            'double' => 'REAL',
            'decimal' => 'NUMERIC',
            'bool' => 'INTEGER',
            'boolean' => 'INTEGER',
            'datetime' => 'TEXT',
            'date' => 'TEXT',
            'time' => 'TEXT',
            'text' => 'TEXT',
            'blob' => 'BLOB',
        ];

        return $typeMap[strtolower($type)] ?? 'TEXT';
    }

    public function getAutoIncrementKeyword(): string
    {
        return 'AUTOINCREMENT';
    }

    public function getStringConcatOperator(): string
    {
        return '||'; // SQLite uses || for string concatenation
    }

    public function getMaskingSql(string $columnName, string $maskChar = '*', int $visibleStart = 0, int $visibleEnd = 4, ?string $customMask = null): string
    {
        if ($customMask !== null) {
            return str_replace('{column}', $columnName, $customMask);
        }

        // Column name is already escaped (e.g., "main"."FirstName")
        // Don't escape again, use as is
        $quotedCol = $columnName;
        $maskCharEscaped = str_replace("'", "''", $maskChar);
        
        // SQLite masking: substr + printf (for repeating)
        // SQLite doesn't have REPEAT, so we use a workaround
        if ($visibleStart > 0 && $visibleEnd > 0) {
            return "substr({$quotedCol}, 1, {$visibleStart}) || " .
                   "replace(hex(zeroblob(max(0, length({$quotedCol}) - {$visibleStart} - {$visibleEnd}))), '00', '{$maskCharEscaped}') || " .
                   "substr({$quotedCol}, -{$visibleEnd})";
        } elseif ($visibleStart > 0) {
            return "substr({$quotedCol}, 1, {$visibleStart}) || " .
                   "replace(hex(zeroblob(max(0, length({$quotedCol}) - {$visibleStart}))), '00', '{$maskCharEscaped}')";
        } elseif ($visibleEnd > 0) {
            return "replace(hex(zeroblob(max(0, length({$quotedCol}) - {$visibleEnd}))), '00', '{$maskCharEscaped}') || " .
                   "substr({$quotedCol}, -{$visibleEnd})";
        } else {
            return "replace(hex(zeroblob(length({$quotedCol}))), '00', '{$maskCharEscaped}')";
        }
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

