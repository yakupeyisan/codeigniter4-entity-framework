<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Core;

use CodeIgniter\Database\BaseConnection;
use ReflectionClass;
use Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProvider;
use Yakupeyisan\CodeIgniter4\EntityFramework\Providers\DatabaseProviderFactory;

/**
 * BulkOperations - Optimized bulk database operations
 * Provides efficient batch insert, update, and delete operations
 */
class BulkOperations
{
    private BaseConnection $connection;
    private DatabaseProvider $provider;
    private int $batchSize;
    private bool $useTransactions;

    public function __construct(BaseConnection $connection, int $batchSize = 1000, bool $useTransactions = true)
    {
        $this->connection = $connection;
        $this->provider = DatabaseProviderFactory::getProvider($connection);
        $this->batchSize = $batchSize;
        $this->useTransactions = $useTransactions;
    }

    /**
     * Optimized batch insert with chunking
     * 
     * @param string $tableName Table name
     * @param array $data Array of data rows
     * @param int|null $chunkSize Optional chunk size (overrides default)
     * @return int Number of inserted rows
     */
    public function batchInsert(string $tableName, array $data, ?int $chunkSize = null): int
    {
        if (empty($data)) {
            return 0;
        }

        $chunkSize = $chunkSize ?? $this->batchSize;
        $totalInserted = 0;
        $chunks = array_chunk($data, $chunkSize);

        $useTransaction = $this->useTransactions && count($chunks) > 1;
        
        if ($useTransaction) {
            $this->connection->transStart();
        }

        try {
            foreach ($chunks as $chunk) {
                $result = $this->connection->table($tableName)->insertBatch($chunk);
                if ($result) {
                    $totalInserted += count($chunk);
                } else {
                    if ($useTransaction) {
                        $this->connection->transRollback();
                    }
                    throw new \RuntimeException("Batch insert failed for table: {$tableName}");
                }
            }

            if ($useTransaction) {
                $this->connection->transComplete();
            }
        } catch (\Exception $e) {
            if ($useTransaction) {
                $this->connection->transRollback();
            }
            throw $e;
        }

        return $totalInserted;
    }

    /**
     * Optimized batch update using CASE WHEN statements
     * More efficient than individual UPDATE statements
     * 
     * @param string $tableName Table name
     * @param array $data Array of data rows with primary key
     * @param string $primaryKey Primary key column name
     * @param array|null $columns Optional list of columns to update (null = all columns except PK)
     * @return int Number of updated rows
     */
    public function batchUpdate(string $tableName, array $data, string $primaryKey = 'Id', ?array $columns = null): int
    {
        if (empty($data)) {
            return 0;
        }

        // Determine columns to update
        if ($columns === null && !empty($data)) {
            $columns = array_keys($data[0]);
            $columns = array_filter($columns, fn($col) => $col !== $primaryKey);
        }

        if (empty($columns)) {
            return 0;
        }

        $totalUpdated = 0;
        $chunks = array_chunk($data, $this->batchSize);

        $useTransaction = $this->useTransactions && count($chunks) > 1;
        
        if ($useTransaction) {
            $this->connection->transStart();
        }

        try {
            foreach ($chunks as $chunk) {
                // Use provider's batch update SQL
                $sql = $this->provider->getBatchUpdateSql($tableName, $chunk, $primaryKey, $columns);
                
                if (!empty($sql)) {
                    $this->connection->query($sql);
                    $updated = $this->connection->affectedRows();
                } else {
                    // Fallback to individual updates
                    $updated = $this->batchUpdateFallback($tableName, $chunk, $primaryKey, $columns);
                }
                $totalUpdated += $updated;
            }

            if ($useTransaction) {
                $this->connection->transComplete();
            }
        } catch (\Exception $e) {
            if ($useTransaction) {
                $this->connection->transRollback();
            }
            throw $e;
        }

        return $totalUpdated;
    }


    /**
     * Fallback batch update (individual updates)
     */
    private function batchUpdateFallback(string $tableName, array $data, string $primaryKey, array $columns): int
    {
        $updated = 0;
        foreach ($data as $row) {
            $id = $row[$primaryKey] ?? null;
            if ($id === null) {
                continue;
            }

            $updateData = [];
            foreach ($columns as $column) {
                if (isset($row[$column])) {
                    $updateData[$column] = $row[$column];
                }
            }

            if (!empty($updateData)) {
                $this->connection->table($tableName)
                    ->where($primaryKey, $id)
                    ->update($updateData);
                $updated++;
            }
        }
        return $updated;
    }

    /**
     * Optimized batch delete
     * 
     * @param string $tableName Table name
     * @param array $ids Array of primary key values
     * @param string $primaryKey Primary key column name
     * @return int Number of deleted rows
     */
    public function batchDelete(string $tableName, array $ids, string $primaryKey = 'Id'): int
    {
        if (empty($ids)) {
            return 0;
        }

        $ids = array_unique(array_filter($ids));
        if (empty($ids)) {
            return 0;
        }

        $chunks = array_chunk($ids, $this->batchSize);
        $totalDeleted = 0;

        $useTransaction = $this->useTransactions && count($chunks) > 1;
        
        if ($useTransaction) {
            $this->connection->transStart();
        }

        try {
            foreach ($chunks as $chunk) {
                $result = $this->connection->table($tableName)
                    ->whereIn($primaryKey, $chunk)
                    ->delete();
                
                if ($result) {
                    $totalDeleted += $this->connection->affectedRows();
                }
            }

            if ($useTransaction) {
                $this->connection->transComplete();
            }
        } catch (\Exception $e) {
            if ($useTransaction) {
                $this->connection->transRollback();
            }
            throw $e;
        }

        return $totalDeleted;
    }

    /**
     * Set batch size
     */
    public function setBatchSize(int $batchSize): void
    {
        $this->batchSize = $batchSize;
    }

    /**
     * Get batch size
     */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * Enable/disable transactions
     */
    public function setUseTransactions(bool $useTransactions): void
    {
        $this->useTransactions = $useTransactions;
    }

    /**
     * Check if transactions are enabled
     */
    public function getUseTransactions(): bool
    {
        return $this->useTransactions;
    }
}

