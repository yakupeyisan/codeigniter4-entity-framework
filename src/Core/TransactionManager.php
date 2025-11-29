<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Core;

use CodeIgniter\Database\BaseConnection;

/**
 * TransactionManager - Advanced transaction management
 * Provides nested transactions, savepoints, and transaction statistics
 */
class TransactionManager
{
    private BaseConnection $connection;
    private int $transactionLevel = 0;
    private array $savepoints = [];
    private array $statistics = [
        'total_transactions' => 0,
        'committed' => 0,
        'rolled_back' => 0,
        'nested_transactions' => 0,
        'savepoints_created' => 0,
    ];

    public function __construct(BaseConnection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Begin transaction (supports nested transactions with savepoints)
     */
    public function beginTransaction(?string $isolationLevel = null): bool
    {
        if ($this->transactionLevel === 0) {
            // Root transaction
            if ($isolationLevel !== null) {
                $this->setIsolationLevel($isolationLevel);
            }
            $result = $this->connection->transStart();
            if ($result) {
                $this->statistics['total_transactions']++;
            }
        } else {
            // Nested transaction - use savepoint
            $savepointName = 'sp_' . $this->transactionLevel;
            $this->createSavepoint($savepointName);
            $this->savepoints[] = $savepointName;
            $this->statistics['nested_transactions']++;
            $this->statistics['savepoints_created']++;
            $result = true;
        }
        
        $this->transactionLevel++;
        return $result;
    }

    /**
     * Commit transaction
     */
    public function commit(): bool
    {
        if ($this->transactionLevel === 0) {
            return false; // No active transaction
        }

        $this->transactionLevel--;

        if ($this->transactionLevel === 0) {
            // Root transaction - commit
            $result = $this->connection->transComplete();
            if ($result) {
                $this->statistics['committed']++;
            }
            $this->savepoints = [];
            return $result;
        } else {
            // Nested transaction - release savepoint
            $savepointName = array_pop($this->savepoints);
            $this->releaseSavepoint($savepointName);
            return true;
        }
    }

    /**
     * Rollback transaction
     */
    public function rollback(?string $savepointName = null): bool
    {
        if ($this->transactionLevel === 0) {
            return false; // No active transaction
        }

        if ($savepointName !== null) {
            // Rollback to specific savepoint
            $this->rollbackToSavepoint($savepointName);
            // Remove savepoints after this one
            $index = array_search($savepointName, $this->savepoints);
            if ($index !== false) {
                $this->savepoints = array_slice($this->savepoints, 0, $index);
                $this->transactionLevel = $index + 1;
            }
            return true;
        }

        $this->transactionLevel--;

        if ($this->transactionLevel === 0) {
            // Root transaction - rollback
            $result = $this->connection->transRollback();
            if ($result) {
                $this->statistics['rolled_back']++;
            }
            $this->savepoints = [];
            return $result;
        } else {
            // Nested transaction - rollback to savepoint
            $savepointName = array_pop($this->savepoints);
            $this->rollbackToSavepoint($savepointName);
            return true;
        }
    }

    /**
     * Create savepoint
     */
    private function createSavepoint(string $savepointName): void
    {
        $driver = strtolower($this->connection->getPlatform() ?? '');
        $quotedName = $this->escapeSavepointName($savepointName, $driver);
        
        $sql = match($driver) {
            'mysql', 'mysqli' => "SAVEPOINT {$quotedName}",
            'sqlsrv', 'sqlserver' => "SAVE TRANSACTION {$quotedName}",
            'postgre', 'pgsql' => "SAVEPOINT {$quotedName}",
            'sqlite', 'sqlite3' => "SAVEPOINT {$quotedName}",
            default => null,
        };
        
        if ($sql !== null) {
            $this->connection->query($sql);
        }
    }

    /**
     * Release savepoint
     */
    private function releaseSavepoint(string $savepointName): void
    {
        $driver = strtolower($this->connection->getPlatform() ?? '');
        $quotedName = $this->escapeSavepointName($savepointName, $driver);
        
        $sql = match($driver) {
            'mysql', 'mysqli' => "RELEASE SAVEPOINT {$quotedName}",
            'sqlsrv', 'sqlserver' => null, // SQL Server doesn't support explicit release
            'postgre', 'pgsql' => "RELEASE SAVEPOINT {$quotedName}",
            'sqlite', 'sqlite3' => "RELEASE SAVEPOINT {$quotedName}",
            default => null,
        };
        
        if ($sql !== null) {
            $this->connection->query($sql);
        }
    }

    /**
     * Rollback to savepoint
     */
    private function rollbackToSavepoint(string $savepointName): void
    {
        $driver = strtolower($this->connection->getPlatform() ?? '');
        $quotedName = $this->escapeSavepointName($savepointName, $driver);
        
        $sql = match($driver) {
            'mysql', 'mysqli' => "ROLLBACK TO SAVEPOINT {$quotedName}",
            'sqlsrv', 'sqlserver' => "ROLLBACK TRANSACTION {$quotedName}",
            'postgre', 'pgsql' => "ROLLBACK TO SAVEPOINT {$quotedName}",
            'sqlite', 'sqlite3' => "ROLLBACK TO SAVEPOINT {$quotedName}",
            default => null,
        };
        
        if ($sql !== null) {
            $this->connection->query($sql);
        }
    }

    /**
     * Escape savepoint name
     */
    private function escapeSavepointName(string $name, string $driver): string
    {
        return match($driver) {
            'mysql', 'mysqli' => '`' . str_replace('`', '``', $name) . '`',
            'sqlsrv', 'sqlserver' => '[' . str_replace(']', ']]', $name) . ']',
            'postgre', 'pgsql' => '"' . str_replace('"', '""', $name) . '"',
            'sqlite', 'sqlite3' => '"' . str_replace('"', '""', $name) . '"',
            default => $name,
        };
    }

    /**
     * Set isolation level
     */
    public function setIsolationLevel(string $isolationLevel): void
    {
        $driver = strtolower($this->connection->getPlatform() ?? '');
        
        $sql = match($isolationLevel) {
            'READ UNCOMMITTED' => $this->getReadUncommittedSql($driver),
            'READ COMMITTED' => $this->getReadCommittedSql($driver),
            'REPEATABLE READ' => $this->getRepeatableReadSql($driver),
            'SERIALIZABLE' => $this->getSerializableSql($driver),
            default => null,
        };
        
        if ($sql !== null) {
            $this->connection->query($sql);
        }
    }

    /**
     * Get READ UNCOMMITTED SQL
     */
    private function getReadUncommittedSql(string $driver): ?string
    {
        return match($driver) {
            'mysql', 'mysqli' => "SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED",
            'sqlsrv', 'sqlserver' => "SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED",
            'postgre', 'pgsql' => "SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED",
            default => null,
        };
    }

    /**
     * Get READ COMMITTED SQL
     */
    private function getReadCommittedSql(string $driver): ?string
    {
        return match($driver) {
            'mysql', 'mysqli' => "SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED",
            'sqlsrv', 'sqlserver' => "SET TRANSACTION ISOLATION LEVEL READ COMMITTED",
            'postgre', 'pgsql' => "SET TRANSACTION ISOLATION LEVEL READ COMMITTED",
            default => null,
        };
    }

    /**
     * Get REPEATABLE READ SQL
     */
    private function getRepeatableReadSql(string $driver): ?string
    {
        return match($driver) {
            'mysql', 'mysqli' => "SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ",
            'sqlsrv', 'sqlserver' => "SET TRANSACTION ISOLATION LEVEL REPEATABLE READ",
            'postgre', 'pgsql' => "SET TRANSACTION ISOLATION LEVEL REPEATABLE READ",
            default => null,
        };
    }

    /**
     * Get SERIALIZABLE SQL
     */
    private function getSerializableSql(string $driver): ?string
    {
        return match($driver) {
            'mysql', 'mysqli' => "SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE",
            'sqlsrv', 'sqlserver' => "SET TRANSACTION ISOLATION LEVEL SERIALIZABLE",
            'postgre', 'pgsql' => "SET TRANSACTION ISOLATION LEVEL SERIALIZABLE",
            default => null,
        };
    }

    /**
     * Get current transaction level
     */
    public function getTransactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Check if transaction is active
     */
    public function isTransactionActive(): bool
    {
        return $this->transactionLevel > 0;
    }

    /**
     * Get transaction statistics
     */
    public function getStatistics(): array
    {
        return array_merge($this->statistics, [
            'current_level' => $this->transactionLevel,
            'active_savepoints' => count($this->savepoints),
        ]);
    }

    /**
     * Reset statistics
     */
    public function resetStatistics(): void
    {
        $this->statistics = [
            'total_transactions' => 0,
            'committed' => 0,
            'rolled_back' => 0,
            'nested_transactions' => 0,
            'savepoints_created' => 0,
        ];
    }
}

