<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Providers;

use CodeIgniter\Database\BaseConnection;

/**
 * MySQL Database Provider
 */
class MySqlProvider implements DatabaseProvider
{
    public function getName(): string
    {
        return 'MySQL';
    }

    public function supports(BaseConnection $connection): bool
    {
        $driver = strtolower($connection->getPlatform() ?? '');
        return $driver === 'mysql' || $driver === 'mysqli';
    }

    public function escapeIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function getLimitClause(int $limit, ?int $offset = null): string
    {
        if ($offset !== null) {
            return "LIMIT {$offset}, {$limit}";
        }
        return "LIMIT {$limit}";
    }

    public function getExplainSql(string $sql): string
    {
        return "EXPLAIN " . $sql;
    }

    public function getBatchUpdateSql(string $tableName, array $data, string $primaryKey, array $columns): string
    {
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
        return "CREATE DATABASE IF NOT EXISTS {$dbName}";
    }

    public function getCheckDatabaseExistsSql(string $databaseName): string
    {
        return "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = " . $this->escapeValue($databaseName);
    }

    public function getColumnType(string $type, ?int $length = null, ?int $precision = null, ?int $scale = null): string
    {
        $typeMap = [
            'string' => 'VARCHAR',
            'int' => 'INT',
            'integer' => 'INT',
            'bigint' => 'BIGINT',
            'float' => 'FLOAT',
            'double' => 'DOUBLE',
            'decimal' => 'DECIMAL',
            'bool' => 'TINYINT(1)',
            'boolean' => 'TINYINT(1)',
            'datetime' => 'DATETIME',
            'date' => 'DATE',
            'time' => 'TIME',
            'text' => 'TEXT',
            'blob' => 'BLOB',
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
        return 'AUTO_INCREMENT';
    }

    public function getStringConcatOperator(): string
    {
        return 'CONCAT';
    }

    public function getMaskingSql(string $columnName, string $maskChar = '*', int $visibleStart = 0, int $visibleEnd = 4, ?string $customMask = null): string
    {
        if ($customMask !== null) {
            return str_replace('{column}', $columnName, $customMask);
        }

        $quotedCol = $this->escapeIdentifier($columnName);
        $maskCharEscaped = str_replace("'", "''", $maskChar);
        
        // MySQL masking: CONCAT + REPEAT + SUBSTRING
        if ($visibleStart > 0 && $visibleEnd > 0) {
            return "CONCAT(" .
                   "SUBSTRING({$quotedCol}, 1, {$visibleStart}), " .
                   "REPEAT('{$maskCharEscaped}', GREATEST(0, CHAR_LENGTH({$quotedCol}) - {$visibleStart} - {$visibleEnd})), " .
                   "SUBSTRING({$quotedCol}, CHAR_LENGTH({$quotedCol}) - {$visibleEnd} + 1))";
        } elseif ($visibleStart > 0) {
            return "CONCAT(" .
                   "SUBSTRING({$quotedCol}, 1, {$visibleStart}), " .
                   "REPEAT('{$maskCharEscaped}', GREATEST(0, CHAR_LENGTH({$quotedCol}) - {$visibleStart}))" .
                   ")";
        } elseif ($visibleEnd > 0) {
            return "CONCAT(" .
                   "REPEAT('{$maskCharEscaped}', GREATEST(0, CHAR_LENGTH({$quotedCol}) - {$visibleEnd})), " .
                   "SUBSTRING({$quotedCol}, CHAR_LENGTH({$quotedCol}) - {$visibleEnd} + 1)" .
                   ")";
        } else {
            return "REPEAT('{$maskCharEscaped}', CHAR_LENGTH({$quotedCol}))";
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

