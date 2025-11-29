<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Providers;

use CodeIgniter\Database\BaseConnection;

/**
 * DatabaseProvider - Base interface for database provider implementations
 * Provides database-specific SQL generation and operations
 */
interface DatabaseProvider
{
    /**
     * Get provider name
     */
    public function getName(): string;

    /**
     * Check if this provider supports the given connection
     */
    public function supports(BaseConnection $connection): bool;

    /**
     * Escape identifier (table name, column name)
     */
    public function escapeIdentifier(string $identifier): string;

    /**
     * Get LIMIT clause
     */
    public function getLimitClause(int $limit, ?int $offset = null): string;

    /**
     * Get EXPLAIN SQL
     */
    public function getExplainSql(string $sql): string;

    /**
     * Get batch update SQL (optimized for this database)
     */
    public function getBatchUpdateSql(string $tableName, array $data, string $primaryKey, array $columns): string;

    /**
     * Get CREATE DATABASE SQL
     */
    public function getCreateDatabaseSql(string $databaseName): string;

    /**
     * Get CHECK DATABASE EXISTS SQL
     */
    public function getCheckDatabaseExistsSql(string $databaseName): string;

    /**
     * Get column type mapping
     */
    public function getColumnType(string $type, ?int $length = null, ?int $precision = null, ?int $scale = null): string;

    /**
     * Get auto increment keyword
     */
    public function getAutoIncrementKeyword(): string;

    /**
     * Get string concatenation operator
     */
    public function getStringConcatOperator(): string;
}

