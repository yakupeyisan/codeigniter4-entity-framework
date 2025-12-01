<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Providers;

use CodeIgniter\Database\BaseConnection;

/**
 * SQL Server Database Provider
 */
class SqlServerProvider implements DatabaseProvider
{
    public function getName(): string
    {
        return 'SQL Server';
    }

    public function supports(BaseConnection $connection): bool
    {
        $driver = strtolower($connection->getPlatform() ?? '');
        return $driver === 'sqlsrv' || $driver === 'sqlserver';
    }

    public function escapeIdentifier(string $identifier): string
    {
        return '[' . str_replace(']', ']]', $identifier) . ']';
    }

    public function getLimitClause(int $limit, ?int $offset = null): string
    {
        if ($offset !== null) {
            return "OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        }
        return "TOP {$limit}";
    }

    public function getExplainSql(string $sql): string
    {
        return "SET SHOWPLAN_ALL ON;\n" . $sql . "\nSET SHOWPLAN_ALL OFF;";
    }

    public function getBatchUpdateSql(string $tableName, array $data, string $primaryKey, array $columns): string
    {
        if (empty($data)) {
            return '';
        }

        $quotedTable = $this->escapeIdentifier($tableName);
        $quotedPk = $this->escapeIdentifier($primaryKey);
        
        // Build VALUES clause
        $values = [];
        foreach ($data as $row) {
            $id = $row[$primaryKey] ?? null;
            if ($id === null) {
                continue;
            }
            
            $rowValues = [$this->escapeValue($id)];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                $rowValues[] = $value === null ? 'NULL' : $this->escapeValue($value);
            }
            $values[] = '(' . implode(', ', $rowValues) . ')';
        }

        if (empty($values)) {
            return '';
        }

        // Build UPDATE SET clause
        $updateSet = [];
        foreach ($columns as $column) {
            $quotedCol = $this->escapeIdentifier($column);
            $updateSet[] = "{$quotedCol} = source.{$quotedCol}";
        }
        $updateClause = implode(', ', $updateSet);

        // Build column list
        $quotedColumns = array_map(fn($col) => $this->escapeIdentifier($col), $columns);
        $columnsList = implode(', ', $quotedColumns);
        $allColumnsList = "{$quotedPk}, {$columnsList}";

        $valuesClause = implode(', ', $values);
        
        return "MERGE {$quotedTable} AS target
                USING (VALUES {$valuesClause}) AS source ({$allColumnsList})
                ON target.{$quotedPk} = source.{$quotedPk}
                WHEN MATCHED THEN
                    UPDATE SET {$updateClause};";
    }

    public function getCreateDatabaseSql(string $databaseName): string
    {
        $dbName = $this->escapeIdentifier($databaseName);
        return "CREATE DATABASE {$dbName}";
    }

    public function getCheckDatabaseExistsSql(string $databaseName): string
    {
        return "SELECT name FROM sys.databases WHERE name = " . $this->escapeValue($databaseName);
    }

    public function getColumnType(string $type, ?int $length = null, ?int $precision = null, ?int $scale = null): string
    {
        $typeMap = [
            'string' => 'NVARCHAR',
            'int' => 'INT',
            'integer' => 'INT',
            'bigint' => 'BIGINT',
            'float' => 'FLOAT',
            'double' => 'REAL',
            'decimal' => 'DECIMAL',
            'bool' => 'BIT',
            'boolean' => 'BIT',
            'datetime' => 'DATETIME2',
            'date' => 'DATE',
            'time' => 'TIME',
            'text' => 'NTEXT',
            'blob' => 'VARBINARY(MAX)',
        ];

        $sqlType = $typeMap[strtolower($type)] ?? strtoupper($type);

        if ($length !== null && in_array(strtolower($type), ['string', 'nvarchar', 'varchar'])) {
            return "{$sqlType}({$length})";
        }

        if ($precision !== null && $scale !== null && in_array(strtolower($type), ['decimal', 'numeric'])) {
            return "{$sqlType}({$precision}, {$scale})";
        }

        return $sqlType;
    }

    public function getAutoIncrementKeyword(): string
    {
        return 'IDENTITY(1,1)';
    }

    public function getStringConcatOperator(): string
    {
        return '+'; // SQL Server uses + for string concatenation
    }

    public function getMaskingSql(string $columnName, string $maskChar = '*', int $visibleStart = 0, int $visibleEnd = 4, ?string $customMask = null): string
    {
        if ($customMask !== null) {
            return str_replace('{column}', $columnName, $customMask);
        }

        // Column name is already escaped (e.g., [main].[FirstName])
        // Don't escape again, use as is
        $quotedCol = $columnName;
        $maskCharEscaped = str_replace("'", "''", $maskChar);
        
        // SQL Server masking: LEFT + REPLICATE + RIGHT
        // Show first X chars, mask middle, show last Y chars
        if ($visibleStart > 0 && $visibleEnd > 0) {
            // Show both start and end
            return "LEFT({$quotedCol}, {$visibleStart}) + " .
                   "REPLICATE(N'{$maskCharEscaped}', LEN({$quotedCol}) - {$visibleStart} - {$visibleEnd}) + " .
                   "RIGHT({$quotedCol}, {$visibleEnd})";
        } elseif ($visibleStart > 0) {
            // Show only start
            return "LEFT({$quotedCol}, {$visibleStart}) + " .
                   "REPLICATE(N'{$maskCharEscaped}', LEN({$quotedCol}) - {$visibleStart})";
        } elseif ($visibleEnd > 0) {
            // Show only end
            return "REPLICATE(N'{$maskCharEscaped}', LEN({$quotedCol}) - {$visibleEnd}) + " .
                   "RIGHT({$quotedCol}, {$visibleEnd})";
        } else {
            // Mask all
            return "REPLICATE(N'{$maskCharEscaped}', LEN({$quotedCol}))";
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
        return "N'" . str_replace("'", "''", $value) . "'";
    }
}

