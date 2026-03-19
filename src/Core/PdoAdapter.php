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

    public function fieldExists(string $fieldName, string $tableName): bool
    {
        $driver = strtolower($this->getPlatform());
        $sql = match ($driver) {
            'mysql' => "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1",
            'pgsql' => "SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name = ? AND column_name = ? LIMIT 1",
            'sqlite' => "PRAGMA table_info({$tableName})",
            'sqlsrv' => "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ?",
            default => "SELECT 1",
        };

        if ($driver === 'sqlite') {
            $stmt = $this->pdo->query($sql);
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columns as $column) {
                if (isset($column['name']) && strcasecmp((string) $column['name'], $fieldName) === 0) {
                    return true;
                }
            }
            return false;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName, $fieldName]);
        return (bool) $stmt->fetchColumn();
    }

    public function error(): array
    {
        return $this->pdo->errorInfo();
    }

    /**
     * Escape SQL identifiers (table/column names) in a CI-compatible way.
     * Keeps SQL expressions untouched (e.g. aliases, functions, dotted names are escaped per segment).
     *
     * @param string|array $item
     * @return string|array
     */
    public function escapeIdentifiers(string|array $item): string|array
    {
        if (is_array($item)) {
            return array_map(fn($identifier) => $this->escapeSingleIdentifier((string) $identifier), $item);
        }

        return $this->escapeSingleIdentifier($item);
    }

    private function escapeSingleIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        if ($identifier === '' || $identifier === '*') {
            return $identifier;
        }

        // Leave raw expressions/functions untouched.
        if (str_contains($identifier, '(') || str_contains($identifier, ')')) {
            return $identifier;
        }

        // Handle aliases: "table.column AS alias" or "table.column alias".
        if (preg_match('/^(.+?)\s+AS\s+(.+)$/i', $identifier, $matches)) {
            return $this->escapeSingleIdentifier($matches[1]) . ' AS ' . $this->escapeSingleIdentifier($matches[2]);
        }

        if (preg_match('/^([^\s]+)\s+([^\s]+)$/', $identifier, $matches)) {
            return $this->escapeSingleIdentifier($matches[1]) . ' ' . $this->escapeSingleIdentifier($matches[2]);
        }

        $parts = explode('.', $identifier);
        $escapedParts = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || $part === '*') {
                $escapedParts[] = $part;
                continue;
            }

            // Do not double-escape already escaped identifiers.
            if (
                (str_starts_with($part, '[') && str_ends_with($part, ']')) ||
                (str_starts_with($part, '`') && str_ends_with($part, '`')) ||
                (str_starts_with($part, '"') && str_ends_with($part, '"'))
            ) {
                $escapedParts[] = $part;
                continue;
            }

            $escapedParts[] = '[' . str_replace(']', ']]', $part) . ']';
        }

        return implode('.', $escapedParts);
    }

    /**
     * CI-compatible wrapper used by query builders.
     * Keeps signature-compatible optional flags but, for PDO adapter, escaping is the main behavior.
     *
     * @param string|array $item
     * @param bool $prefixSingle Ignored in PDO adapter
     * @param bool|null $protectIdentifiers If false, returns input as-is
     * @param bool $fieldExists Ignored in PDO adapter
     * @return string|array
     */
    public function protectIdentifiers(
        string|array $item,
        bool $prefixSingle = false,
        ?bool $protectIdentifiers = null,
        bool $fieldExists = true
    ): string|array {
        unset($prefixSingle, $fieldExists);

        if ($protectIdentifiers === false) {
            return $item;
        }

        return $this->escapeIdentifiers($item);
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
    private array $select = ['*'];
    private array $joins = [];
    private array $whereParts = [];
    private bool $nextBooleanIsOr = false;
    private array $orderBys = [];
    private ?int $limitValue = null;
    private ?int $offsetValue = null;

    public function __construct(PdoAdapter $connection, string $table)
    {
        $this->connection = $connection;
        $this->table = $table;
    }

    public function where(string $column, $value = null, ?bool $escape = null): self
    {
        unset($escape);

        if ($value === null) {
            $this->addWherePart($column, 'AND');
            return $this;
        }

        $this->where[] = "{$column} = ?";
        $this->bindings[] = $value;
        $this->addWherePart("{$column} = ?", 'AND', [$value]);
        return $this;
    }

    public function orWhere(string $column, $value = null, ?bool $escape = null): self
    {
        unset($escape);

        if ($value === null) {
            $this->addWherePart($column, 'OR');
            return $this;
        }

        $this->addWherePart("{$column} = ?", 'OR', [$value]);
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
        $this->addWherePart("{$column} IN ({$placeholders})", 'AND', array_values($values));
        return $this;
    }

    public function groupStart(): self
    {
        $boolean = $this->nextBooleanIsOr ? 'OR' : 'AND';
        $this->whereParts[] = ['type' => 'group_start', 'boolean' => $boolean];
        $this->nextBooleanIsOr = false;
        return $this;
    }

    public function orGroupStart(): self
    {
        $this->nextBooleanIsOr = true;
        return $this->groupStart();
    }

    public function groupEnd(): self
    {
        $this->whereParts[] = ['type' => 'group_end'];
        return $this;
    }

    public function select(string $select = '*', ?bool $escape = null): self
    {
        unset($escape);
        $this->select = [$select];
        return $this;
    }

    public function join(string $table, ?string $cond = null, string $type = '', ?bool $escape = null): self
    {
        unset($escape);
        $joinType = strtoupper(trim($type));
        if ($joinType === '') {
            $joinType = 'INNER';
        }

        $this->joins[] = [
            'type' => $joinType,
            'table' => $table,
            'condition' => $cond,
        ];
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC', ?bool $escape = null): self
    {
        unset($escape);
        $dir = strtoupper(trim($direction));
        if ($dir !== 'ASC' && $dir !== 'DESC') {
            $dir = 'ASC';
        }

        $this->orderBys[] = "{$column} {$dir}";
        return $this;
    }

    public function limit(int $value, ?int $offset = null): self
    {
        $this->limitValue = max(0, $value);
        if ($offset !== null) {
            $this->offsetValue = max(0, $offset);
        }
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offsetValue = max(0, $offset);
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

    public function getCompiledSelect(bool $reset = true): string
    {
        [$whereSql] = $this->buildWhere();
        $selectSql = implode(', ', $this->select);
        $sql = "SELECT {$selectSql} FROM {$this->table}";

        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN {$join['table']}";
            if (!empty($join['condition'])) {
                $sql .= " ON {$join['condition']}";
            }
        }

        $sql .= $whereSql;
        $sql .= $this->buildOrderBySql();
        $sql .= $this->buildLimitOffsetSql();

        if ($reset) {
            $this->resetQueryState();
        }

        return $sql;
    }

    public function countAllResults(bool $reset = true): int
    {
        [$whereSql, $bindings] = $this->buildWhere();
        $sql = "SELECT COUNT(*) AS aggregate FROM {$this->table}";

        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN {$join['table']}";
            if (!empty($join['condition'])) {
                $sql .= " ON {$join['condition']}";
            }
        }

        $sql .= $whereSql;

        $row = $this->connection->query($sql, $bindings)->getRowArray();
        $count = isset($row['aggregate']) ? (int) $row['aggregate'] : (int) array_values($row)[0];

        if ($reset) {
            $this->resetQueryState();
        }

        return $count;
    }

    public function get(?int $limit = null, ?int $offset = null, bool $reset = true): PdoResult
    {
        if ($limit !== null) {
            $this->limit($limit, $offset);
        } elseif ($offset !== null) {
            $this->offset($offset);
        }

        [$whereSql, $bindings] = $this->buildWhere();
        $selectSql = implode(', ', $this->select);
        $sql = "SELECT {$selectSql} FROM {$this->table}";

        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN {$join['table']}";
            if (!empty($join['condition'])) {
                $sql .= " ON {$join['condition']}";
            }
        }

        $sql .= $whereSql;
        $sql .= $this->buildOrderBySql();
        $sql .= $this->buildLimitOffsetSql();

        $result = $this->connection->query($sql, $bindings);

        if ($reset) {
            $this->resetQueryState();
        }

        return $result;
    }

    private function buildWhere(): array
    {
        if (!empty($this->whereParts)) {
            $sql = '';
            $bindings = [];
            $isFirstCondition = true;

            foreach ($this->whereParts as $part) {
                if ($part['type'] === 'group_start') {
                    $prefix = $isFirstCondition ? '' : (' ' . $part['boolean'] . ' ');
                    $sql .= $prefix . '(';
                    continue;
                }

                if ($part['type'] === 'group_end') {
                    $sql .= ')';
                    continue;
                }

                $prefix = $isFirstCondition ? '' : (' ' . $part['boolean'] . ' ');
                $sql .= $prefix . $part['sql'];
                $bindings = array_merge($bindings, $part['bindings']);
                $isFirstCondition = false;
            }

            $whereSql = trim($sql) === '' ? '' : (' WHERE ' . $sql);
            return [$whereSql, $bindings];
        }

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

    private function addWherePart(string $sql, string $boolean, array $bindings = []): void
    {
        $this->whereParts[] = [
            'type' => 'condition',
            'boolean' => $boolean,
            'sql' => $sql,
            'bindings' => $bindings,
        ];
        $this->nextBooleanIsOr = false;
    }

    private function resetQueryState(): void
    {
        $this->select = ['*'];
        $this->joins = [];
        $this->where = [];
        $this->whereIn = [];
        $this->bindings = [];
        $this->whereParts = [];
        $this->nextBooleanIsOr = false;
        $this->orderBys = [];
        $this->limitValue = null;
        $this->offsetValue = null;
    }

    private function buildOrderBySql(): string
    {
        if (empty($this->orderBys)) {
            return '';
        }

        return ' ORDER BY ' . implode(', ', $this->orderBys);
    }

    private function buildLimitOffsetSql(): string
    {
        // SQL Server requires ORDER BY with OFFSET/FETCH.
        if ($this->limitValue === null && $this->offsetValue === null) {
            return '';
        }

        $offset = $this->offsetValue ?? 0;

        if (empty($this->orderBys)) {
            return " ORDER BY (SELECT 1) OFFSET {$offset} ROWS"
                . ($this->limitValue !== null ? " FETCH NEXT {$this->limitValue} ROWS ONLY" : '');
        }

        return " OFFSET {$offset} ROWS"
            . ($this->limitValue !== null ? " FETCH NEXT {$this->limitValue} ROWS ONLY" : '');
    }
}
