<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Query;

/**
 * QueryHints - Query hints and optimization options
 * Provides database-specific query hints for performance optimization
 */
class QueryHints
{
    private array $hints = [];
    private ?int $timeout = null;
    private ?string $indexHint = null;
    private ?string $lockHint = null;
    private ?int $maxRows = null;
    private bool $noCache = false;
    private bool $forceIndex = false;
    private bool $ignoreIndex = false;
    private array $optimizerHints = [];

    /**
     * Set query timeout (in seconds)
     */
    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * Use specific index
     */
    public function useIndex(string $indexName): self
    {
        $this->indexHint = $indexName;
        $this->forceIndex = false;
        return $this;
    }

    /**
     * Force specific index
     */
    public function forceIndex(string $indexName): self
    {
        $this->indexHint = $indexName;
        $this->forceIndex = true;
        return $this;
    }

    /**
     * Ignore specific index
     */
    public function ignoreIndex(string $indexName): self
    {
        $this->indexHint = $indexName;
        $this->ignoreIndex = true;
        return $this;
    }

    /**
     * Set lock hint (SQL Server: NOLOCK, READPAST, etc.)
     */
    public function withLock(string $lockHint): self
    {
        $this->lockHint = $lockHint;
        return $this;
    }

    /**
     * Set maximum rows to return
     */
    public function maxRows(int $rows): self
    {
        $this->maxRows = $rows;
        return $this;
    }

    /**
     * Disable query cache
     */
    public function noCache(): self
    {
        $this->noCache = true;
        return $this;
    }

    /**
     * Add optimizer hint (database-specific)
     */
    public function optimizerHint(string $hint): self
    {
        $this->optimizerHints[] = $hint;
        return $this;
    }

    /**
     * Apply hints to SQL query
     */
    public function applyToSql(string $sql, string $driver, string $tableName): string
    {
        $driver = strtolower($driver);
        
        // Apply index hints
        if ($this->indexHint !== null) {
            $sql = $this->applyIndexHint($sql, $driver, $tableName, $this->indexHint, $this->forceIndex, $this->ignoreIndex);
        }
        
        // Apply lock hints (SQL Server)
        if ($this->lockHint !== null && ($driver === 'sqlsrv' || $driver === 'sqlserver')) {
            $sql = $this->applyLockHint($sql, $this->lockHint);
        }
        
        // Apply optimizer hints
        if (!empty($this->optimizerHints)) {
            $sql = $this->applyOptimizerHints($sql, $driver);
        }
        
        return $sql;
    }

    /**
     * Apply index hint to SQL
     */
    private function applyIndexHint(string $sql, string $driver, string $tableName, string $indexName, bool $force, bool $ignore): string
    {
        $quotedTable = $this->escapeIdentifier($tableName, $driver);
        $quotedIndex = $this->escapeIdentifier($indexName, $driver);
        
        if ($driver === 'mysql' || $driver === 'mysqli') {
            $hint = $force ? 'FORCE INDEX' : ($ignore ? 'IGNORE INDEX' : 'USE INDEX');
            // MySQL: SELECT * FROM table USE INDEX (index_name) WHERE ...
            $pattern = '/FROM\s+' . preg_quote($quotedTable, '/') . '/i';
            $replacement = "FROM {$quotedTable} {$hint} ({$quotedIndex})";
            $sql = preg_replace($pattern, $replacement, $sql, 1);
        } elseif ($driver === 'sqlsrv' || $driver === 'sqlserver') {
            // SQL Server: WITH (INDEX(index_name))
            $pattern = '/FROM\s+' . preg_quote($quotedTable, '/') . '/i';
            $replacement = "FROM {$quotedTable} WITH (INDEX({$quotedIndex}))";
            $sql = preg_replace($pattern, $replacement, $sql, 1);
        } elseif ($driver === 'postgre' || $driver === 'pgsql') {
            // PostgreSQL doesn't support index hints directly, but can use planner hints
            // This would require pg_hint_plan extension
        }
        
        return $sql;
    }

    /**
     * Apply lock hint to SQL (SQL Server)
     */
    private function applyLockHint(string $sql, string $lockHint): string
    {
        // SQL Server: WITH (NOLOCK), WITH (READPAST), etc.
        // Pattern: FROM table -> FROM table WITH (NOLOCK)
        $pattern = '/FROM\s+(\[?[\w]+\]?)(?:\s+AS\s+[\w]+)?(?:\s+WITH\s*\([^)]+\))?/i';
        
        $hints = [
            'NOLOCK' => 'NOLOCK',
            'READPAST' => 'READPAST',
            'READUNCOMMITTED' => 'READUNCOMMITTED',
            'READCOMMITTED' => 'READCOMMITTED',
            'REPEATABLEREAD' => 'REPEATABLEREAD',
            'SERIALIZABLE' => 'SERIALIZABLE',
        ];
        
        $hintValue = $hints[strtoupper($lockHint)] ?? $lockHint;
        
        $replacement = function($matches) use ($hintValue) {
            $table = $matches[1];
            if (preg_match('/WITH\s*\(/', $matches[0])) {
                // Already has WITH clause, append
                return str_replace(')', ", {$hintValue})", $matches[0]);
            } else {
                return $matches[0] . " WITH ({$hintValue})";
            }
        };
        
        return preg_replace_callback($pattern, $replacement, $sql);
    }

    /**
     * Apply optimizer hints
     */
    private function applyOptimizerHints(string $sql, string $driver): string
    {
        if ($driver === 'mysql' || $driver === 'mysqli') {
            // MySQL optimizer hints
            $hints = implode(' ', $this->optimizerHints);
            // Add as comment: /*+ hint */
            $sql = preg_replace('/SELECT\s+/i', "SELECT /*+ {$hints} */ ", $sql, 1);
        } elseif ($driver === 'sqlsrv' || $driver === 'sqlserver') {
            // SQL Server query hints
            $hints = implode(', ', $this->optimizerHints);
            // Add OPTION clause
            if (stripos($sql, 'OPTION') === false) {
                $sql = rtrim($sql, ';') . " OPTION ({$hints})";
            } else {
                $sql = preg_replace('/OPTION\s*\(([^)]+)\)/i', "OPTION ($1, {$hints})", $sql);
            }
        } elseif ($driver === 'postgre' || $driver === 'pgsql') {
            // PostgreSQL planner hints (requires pg_hint_plan)
            $hints = implode(' ', $this->optimizerHints);
            $sql = "/*+ {$hints} */ " . $sql;
        }
        
        return $sql;
    }

    /**
     * Escape identifier
     */
    private function escapeIdentifier(string $identifier, string $driver): string
    {
        return match($driver) {
            'mysql', 'mysqli' => '`' . str_replace('`', '``', $identifier) . '`',
            'sqlsrv', 'sqlserver' => '[' . str_replace(']', ']]', $identifier) . ']',
            'postgre', 'pgsql' => '"' . str_replace('"', '""', $identifier) . '"',
            'sqlite', 'sqlite3' => '"' . str_replace('"', '""', $identifier) . '"',
            default => $identifier,
        };
    }

    /**
     * Get timeout
     */
    public function getTimeout(): ?int
    {
        return $this->timeout;
    }

    /**
     * Get index hint
     */
    public function getIndexHint(): ?string
    {
        return $this->indexHint;
    }

    /**
     * Get lock hint
     */
    public function getLockHint(): ?string
    {
        return $this->lockHint;
    }

    /**
     * Get max rows
     */
    public function getMaxRows(): ?int
    {
        return $this->maxRows;
    }

    /**
     * Check if no cache
     */
    public function isNoCache(): bool
    {
        return $this->noCache;
    }

    /**
     * Get optimizer hints
     */
    public function getOptimizerHints(): array
    {
        return $this->optimizerHints;
    }

    /**
     * Check if force index
     */
    public function isForceIndex(): bool
    {
        return $this->forceIndex;
    }

    /**
     * Check if ignore index
     */
    public function isIgnoreIndex(): bool
    {
        return $this->ignoreIndex;
    }
}

