<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Core;

use CodeIgniter\Database\BaseConnection;

/**
 * TransactionScope - Provides a scope-based transaction management
 * Automatically commits on success, rolls back on exception
 * Similar to TransactionScope in .NET
 */
class TransactionScope
{
    private DbContext $context;
    private bool $completed = false;
    private ?string $isolationLevel = null;
    private ?int $timeout = null;
    private array $callbacks = [];

    public function __construct(DbContext $context, ?string $isolationLevel = null, ?int $timeout = null)
    {
        $this->context = $context;
        $this->isolationLevel = $isolationLevel;
        $this->timeout = $timeout;
        
        $this->beginTransaction();
    }

    /**
     * Begin transaction with optional isolation level
     */
    private function beginTransaction(): void
    {
        if ($this->isolationLevel !== null) {
            $this->setIsolationLevel($this->isolationLevel);
        }
        
        if ($this->timeout !== null) {
            $this->setTimeout($this->timeout);
        }
        
        $this->context->beginTransaction();
    }

    /**
     * Set isolation level
     */
    private function setIsolationLevel(string $isolationLevel): void
    {
        $connection = $this->context->getConnection();
        $driver = strtolower($connection->getPlatform() ?? '');
        
        $sql = match($isolationLevel) {
            'READ UNCOMMITTED' => $this->getReadUncommittedSql($driver),
            'READ COMMITTED' => $this->getReadCommittedSql($driver),
            'REPEATABLE READ' => $this->getRepeatableReadSql($driver),
            'SERIALIZABLE' => $this->getSerializableSql($driver),
            default => null,
        };
        
        if ($sql !== null) {
            $connection->query($sql);
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
     * Set transaction timeout (in seconds)
     */
    private function setTimeout(int $timeout): void
    {
        $connection = $this->context->getConnection();
        $driver = strtolower($connection->getPlatform() ?? '');
        
        $sql = match($driver) {
            'mysql', 'mysqli' => "SET SESSION innodb_lock_wait_timeout = {$timeout}",
            'sqlsrv', 'sqlserver' => "SET LOCK_TIMEOUT " . ($timeout * 1000), // SQL Server uses milliseconds
            'postgre', 'pgsql' => "SET lock_timeout = '{$timeout}s'",
            default => null,
        };
        
        if ($sql !== null) {
            $connection->query($sql);
        }
    }

    /**
     * Complete transaction (commit)
     */
    public function complete(): void
    {
        if ($this->completed) {
            return;
        }
        
        $this->completed = true;
        $this->context->commit();
        
        // Execute callbacks
        foreach ($this->callbacks as $callback) {
            $callback();
        }
    }

    /**
     * Add callback to execute after successful commit
     */
    public function onComplete(callable $callback): void
    {
        $this->callbacks[] = $callback;
    }

    /**
     * Destructor - automatically rollback if not completed
     */
    public function __destruct()
    {
        if (!$this->completed) {
            try {
                $this->context->rollback();
            } catch (\Exception $e) {
                // Ignore rollback errors in destructor
                log_message('error', 'Transaction rollback failed in destructor: ' . $e->getMessage());
            }
        }
    }
}

