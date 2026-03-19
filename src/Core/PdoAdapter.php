<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Core;

use PDO;
use PDOStatement;

/**
 * Lightweight PDO adapter that mimics the subset of CI4 DB API
 * used by this package.
 */
class PdoAdapter
{
    private PDO $pdo;
    private int $transactionDepth = 0;
    private int $lastAffectedRows = 0;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function raw(): PDO
    {
        return $this->pdo;
    }

    public function getPlatform(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function getDatabase(): ?string
    {
        return match (strtolower($this->getPlatform())) {
            'mysql' => (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn(),
            'pgsql' => (string) $this->pdo->query('SELECT current_database()')->fetchColumn(),
            'sqlite' => null,
            'sqlsrv' => (string) $this->pdo->query('SELECT DB_NAME()')->fetchColumn(),
            default => null,
        };
    }

    public function initialize(): bool
    {
        $this->pdo->query('SELECT 1');
        return true;
    }

    public function query(string $sql, array $parameters = []): PdoResult
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($parameters));
        $this->lastAffectedRows = $stmt->rowCount();
        return new PdoResult($stmt);
    }

    /**
     * Stream query result rows as associative arrays.
     */
    public function streamQuery(string $sql, array $parameters = []): \Generator
    {
        $stmt = $this->pdo->prepare($sql, [PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY]);
        $stmt->execute(array_values($parameters));

        try {
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                yield $row;
            }
        } finally {
            $stmt->closeCursor();
        }
    }

    public function execStatement(string $sql, array $parameters = []): bool
    {
        $stmt = $this->pdo->prepare($sql);
        $ok = $stmt->execute(array_values($parameters));
        $this->lastAffectedRows = $stmt->rowCount();
        return $ok;
    }

    public function table(string $table): PdoTableBuilder
    {
        return new PdoTableBuilder($this, $table);
    }

    public function insertID(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function affectedRows(): int
    {
        return $this->lastAffectedRows;
    }

    public function transStart(): bool
    {
        if ($this->transactionDepth === 0) {
            $this->pdo->beginTransaction();
        }
        $this->transactionDepth++;
        return true;
    }

    public function transComplete(): bool
    {
        if ($this->transactionDepth <= 0) {
            return false;
        }
        $this->transactionDepth--;
        if ($this->transactionDepth === 0 && $this->pdo->inTransaction()) {
            return $this->pdo->commit();
        }
        return true;
    }

    public function transRollback(): bool
    {
        $this->transactionDepth = 0;
        if ($this->pdo->inTransaction()) {
            return $this->pdo->rollBack();
        }
        return true;
    }

    public function transCommit(): bool
    {
        if ($this->pdo->inTransaction()) {
            $this->transactionDepth = 0;
            return $this->pdo->commit();
        }
        return true;
    }

    public function tableExists(string $tableName): bool
    {
        $driver = strtolower($this->getPlatform());
        $sql = match ($driver) {
            'mysql' => "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1",
            'pgsql' => "SELECT 1 FROM information_schema.tables WHERE table_schema='public' AND table_name = ? LIMIT 1",
            'sqlite' => "SELECT 1 FROM sqlite_master WHERE type='table' AND name = ? LIMIT 1",
            'sqlsrv' => "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?",
            default => "SELECT 1",
        };

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);
        return (bool) $stmt->fetchColumn();
    }

    public function error(): array
    {
        return $this->pdo->errorInfo();
    }
}

class PdoResult
{
    private PDOStatement $stmt;

    public function __construct(PDOStatement $stmt)
    {
        $this->stmt = $stmt;
    }

    public function getResultArray(): array
    {
        return $this->stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getNumRows(): int
    {
        return $this->stmt->rowCount();
    }

    public function getRow(): ?object
    {
        $row = $this->stmt->fetch(PDO::FETCH_OBJ);
        return $row === false ? null : $row;
    }

    public function getRowArray(): array
    {
        $row = $this->stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? [] : $row;
    }
}

class PdoTableBuilder
{
    private PdoAdapter $connection;
    private string $table;
    private array $where = [];
    private array $whereIn = [];
    private array $bindings = [];

    public function __construct(PdoAdapter $connection, string $table)
    {
        $this->connection = $connection;
        $this->table = $table;
    }

    public function where(string $column, $value): self
    {
        $this->where[] = "{$column} = ?";
        $this->bindings[] = $value;
        return $this;
    }

    public function whereIn(string $column, array $values): self
    {
        if (empty($values)) {
            return $this;
        }
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->whereIn[] = "{$column} IN ({$placeholders})";
        array_push($this->bindings, ...array_values($values));
        return $this;
    }

    public function insert(array $data): bool
    {
        if (empty($data)) {
            return false;
        }
        $columns = array_keys($data);
        $columnSql = implode(', ', $columns);
        $valuesSql = implode(', ', array_fill(0, count($columns), '?'));
        $sql = "INSERT INTO {$this->table} ({$columnSql}) VALUES ({$valuesSql})";
        return $this->connection->execStatement($sql, array_values($data));
    }

    public function insertBatch(array $rows): bool
    {
        if (empty($rows)) {
            return true;
        }

        $this->connection->transStart();
        try {
            foreach ($rows as $row) {
                $this->insert($row);
            }
            return $this->connection->transComplete();
        } catch (\Throwable $e) {
            $this->connection->transRollback();
            throw $e;
        }
    }

    public function update(array $data): bool
    {
        if (empty($data)) {
            return false;
        }
        $set = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($data)));
        [$whereSql, $whereBindings] = $this->buildWhere();
        $sql = "UPDATE {$this->table} SET {$set}{$whereSql}";
        return $this->connection->execStatement($sql, array_merge(array_values($data), $whereBindings));
    }

    public function delete(): bool
    {
        [$whereSql, $whereBindings] = $this->buildWhere();
        $sql = "DELETE FROM {$this->table}{$whereSql}";
        return $this->connection->execStatement($sql, $whereBindings);
    }

    private function buildWhere(): array
    {
        $clauses = [];
        if (!empty($this->where)) {
            $clauses[] = implode(' AND ', $this->where);
        }
        if (!empty($this->whereIn)) {
            $clauses[] = implode(' AND ', $this->whereIn);
        }
        $whereSql = empty($clauses) ? '' : (' WHERE ' . implode(' AND ', $clauses));
        return [$whereSql, $this->bindings];
    }
}
