<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Query;

use Yakupeyisan\CodeIgniter4\EntityFramework\Core\DbContext;
use CodeIgniter\Database\BaseConnection;

/**
 * DatabaseSpecificQueryBuilder - Database-specific query features
 * Provides database-specific query methods (full-text search, JSON, window functions, etc.)
 */
class DatabaseSpecificQueryBuilder
{
    private DbContext $context;
    private BaseConnection $connection;
    private AdvancedQueryBuilder $queryBuilder;
    private string $driver;

    public function __construct(DbContext $context, BaseConnection $connection, AdvancedQueryBuilder $queryBuilder)
    {
        $this->context = $context;
        $this->connection = $connection;
        $this->queryBuilder = $queryBuilder;
        $this->driver = strtolower($connection->getPlatform() ?? '');
    }

    /**
     * Full-text search (MySQL, PostgreSQL, SQL Server)
     * Returns query builder for chaining
     */
    public function fullTextSearch(string $column, string $searchTerm, ?string $mode = null): AdvancedQueryBuilder
    {
        $tableName = $this->queryBuilder->getTableName();
        
        $sql = match($this->driver) {
            'mysql', 'mysqli' => $this->getMySqlFullTextSearch($tableName, $column, $searchTerm, $mode),
            'postgre', 'pgsql' => $this->getPostgreSqlFullTextSearch($tableName, $column, $searchTerm),
            'sqlsrv', 'sqlserver' => $this->getSqlServerFullTextSearch($tableName, $column, $searchTerm),
            default => null,
        };
        
        if ($sql !== null) {
            // Use raw SQL for full-text search
            $this->queryBuilder->fromSqlRaw($sql);
        }
        
        return $this->queryBuilder;
    }

    /**
     * JSON functions (MySQL, PostgreSQL, SQL Server, SQLite)
     */
    public function jsonExtract(string $column, string $path): AdvancedQueryBuilder
    {
        $quotedColumn = $this->escapeIdentifier($column);
        $sql = match($this->driver) {
            'mysql', 'mysqli' => "JSON_EXTRACT({$quotedColumn}, '{$path}')",
            'postgre', 'pgsql' => "{$quotedColumn}->>'{$path}'",
            'sqlsrv', 'sqlserver' => "JSON_VALUE({$quotedColumn}, '{$path}')",
            'sqlite', 'sqlite3' => "json_extract({$quotedColumn}, '{$path}')",
            default => null,
        };
        
        if ($sql !== null) {
            $this->queryBuilder->selectRaw($sql);
        }
        
        return $this->queryBuilder;
    }

    /**
     * JSON contains (MySQL, PostgreSQL, SQL Server)
     */
    public function jsonContains(string $column, string $path, $value): AdvancedQueryBuilder
    {
        $quotedColumn = $this->escapeIdentifier($column);
        $escapedValue = is_string($value) ? "'" . str_replace("'", "''", $value) . "'" : $value;
        
        $sql = match($this->driver) {
            'mysql', 'mysqli' => "JSON_CONTAINS({$quotedColumn}, {$escapedValue}, '{$path}')",
            'postgre', 'pgsql' => "{$quotedColumn} @> '{\"{$path}\": {$escapedValue}}'::jsonb",
            'sqlsrv', 'sqlserver' => "JSON_CONTAINS({$quotedColumn}, {$escapedValue}, '{$path}')",
            default => null,
        };
        
        if ($sql !== null) {
            $this->queryBuilder->whereRaw($sql);
        }
        
        return $this->queryBuilder;
    }

    /**
     * Window functions (ROW_NUMBER, RANK, DENSE_RANK, etc.)
     */
    public function windowFunction(string $function, ?string $partitionBy = null, ?string $orderBy = null): AdvancedQueryBuilder
    {
        $partition = $partitionBy ? "PARTITION BY {$partitionBy}" : '';
        $order = $orderBy ? "ORDER BY {$orderBy}" : '';
        
        $sql = "{$function}() OVER ({$partition} {$order})";
        $this->queryBuilder->selectRaw($sql);
        
        return $this->queryBuilder;
    }

    /**
     * ROW_NUMBER window function
     */
    public function rowNumber(?string $partitionBy = null, ?string $orderBy = null): AdvancedQueryBuilder
    {
        return $this->windowFunction('ROW_NUMBER', $partitionBy, $orderBy);
    }

    /**
     * RANK window function
     */
    public function rank(?string $partitionBy = null, ?string $orderBy = null): AdvancedQueryBuilder
    {
        return $this->windowFunction('RANK', $partitionBy, $orderBy);
    }

    /**
     * DENSE_RANK window function
     */
    public function denseRank(?string $partitionBy = null, ?string $orderBy = null): AdvancedQueryBuilder
    {
        return $this->windowFunction('DENSE_RANK', $partitionBy, $orderBy);
    }

    /**
     * PostgreSQL array functions
     */
    public function arrayContains(string $column, $value): AdvancedQueryBuilder
    {
        if ($this->driver !== 'postgre' && $this->driver !== 'pgsql') {
            return $this->queryBuilder;
        }
        
        $quotedColumn = $this->escapeIdentifier($column);
        $escapedValue = is_string($value) ? "'" . str_replace("'", "''", $value) . "'" : $value;
        $this->queryBuilder->whereRaw("{$quotedColumn} @> ARRAY[{$escapedValue}]");
        
        return $this->queryBuilder;
    }

    /**
     * PostgreSQL array length
     */
    public function arrayLength(string $column): AdvancedQueryBuilder
    {
        if ($this->driver !== 'postgre' && $this->driver !== 'pgsql') {
            return $this->queryBuilder;
        }
        
        $quotedColumn = $this->escapeIdentifier($column);
        $this->queryBuilder->selectRaw("array_length({$quotedColumn}, 1)");
        
        return $this->queryBuilder;
    }

    /**
     * MySQL/PostgreSQL JSON array functions
     */
    public function jsonArrayLength(string $column): AdvancedQueryBuilder
    {
        $quotedColumn = $this->escapeIdentifier($column);
        $sql = match($this->driver) {
            'mysql', 'mysqli' => "JSON_LENGTH({$quotedColumn})",
            'postgre', 'pgsql' => "jsonb_array_length({$quotedColumn})",
            'sqlite', 'sqlite3' => "json_array_length({$quotedColumn})",
            default => null,
        };
        
        if ($sql !== null) {
            $this->queryBuilder->selectRaw($sql);
        }
        
        return $this->queryBuilder;
    }

    /**
     * Get MySQL full-text search SQL
     */
    private function getMySqlFullTextSearch(string $tableName, string $column, string $searchTerm, ?string $mode): string
    {
        $quotedTable = "`{$tableName}`";
        $quotedColumn = "`{$column}`";
        $escapedTerm = str_replace("'", "''", $searchTerm);
        
        $modeClause = match($mode) {
            'boolean' => 'IN BOOLEAN MODE',
            'natural' => 'IN NATURAL LANGUAGE MODE',
            'query' => 'IN NATURAL LANGUAGE MODE WITH QUERY EXPANSION',
            default => 'IN NATURAL LANGUAGE MODE',
        };
        
        return "SELECT * FROM {$quotedTable} WHERE MATCH({$quotedColumn}) AGAINST('{$escapedTerm}' {$modeClause})";
    }

    /**
     * Get PostgreSQL full-text search SQL
     */
    private function getPostgreSqlFullTextSearch(string $tableName, string $column, string $searchTerm): string
    {
        $quotedTable = "\"{$tableName}\"";
        $quotedColumn = "\"{$column}\"";
        $escapedTerm = str_replace("'", "''", $searchTerm);
        
        return "SELECT * FROM {$quotedTable} WHERE to_tsvector('english', {$quotedColumn}) @@ to_tsquery('english', '{$escapedTerm}')";
    }

    /**
     * Get SQL Server full-text search SQL
     */
    private function getSqlServerFullTextSearch(string $tableName, string $column, string $searchTerm): string
    {
        $quotedTable = "[{$tableName}]";
        $quotedColumn = "[{$column}]";
        $escapedTerm = str_replace("'", "''", $searchTerm);
        
        return "SELECT * FROM {$quotedTable} WHERE CONTAINS({$quotedColumn}, '{$escapedTerm}')";
    }

    /**
     * Escape identifier
     */
    private function escapeIdentifier(string $identifier): string
    {
        return match($this->driver) {
            'mysql', 'mysqli' => '`' . str_replace('`', '``', $identifier) . '`',
            'sqlsrv', 'sqlserver' => '[' . str_replace(']', ']]', $identifier) . ']',
            'postgre', 'pgsql' => '"' . str_replace('"', '""', $identifier) . '"',
            'sqlite', 'sqlite3' => '"' . str_replace('"', '""', $identifier) . '"',
            default => $identifier,
        };
    }

    /**
     * Get underlying query builder
     */
    public function getQueryBuilder(): AdvancedQueryBuilder
    {
        return $this->queryBuilder;
    }
}
