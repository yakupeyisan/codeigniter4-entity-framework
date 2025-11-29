<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Migrations;

use CodeIgniter\Database\BaseConnection;

/**
 * MigrationManager - Manages migrations
 * Equivalent to migration commands in EF Core (Add-Migration, Update-Database, etc.)
 */
class MigrationManager
{
    private BaseConnection $connection;
    private string $migrationsPath;

    public function __construct(?BaseConnection $connection = null, ?string $migrationsPath = null)
    {
        if ($connection === null) {
            // CodeIgniter 4 way to get database connection
            $this->connection = \Config\Database::connect();
        } else {
            $this->connection = $connection;
        }
        $this->migrationsPath = $migrationsPath ?? APPPATH . 'Database/Migrations/';
        
        // Ensure database exists
        $this->ensureDatabaseExists();
    }
    
    /**
     * Ensure database exists, create if not
     */
    private function ensureDatabaseExists(): void
    {
        try {
            // Try to connect to the database
            $this->connection->initialize();
        } catch (\Exception $e) {
            // If connection fails, try to create database
            $dbConfig = new \Config\Database();
            $defaultConfig = $dbConfig->default;
            $database = $defaultConfig['database'] ?? null;
            
            if (empty($database)) {
                throw new \RuntimeException('Database name not configured');
            }
            
            // Check if it's a SQL Server connection
            $driver = strtolower($defaultConfig['DBDriver'] ?? '');
            
            if ($driver === 'sqlsrv' || $driver === 'sqlserver') {
                $this->createSqlServerDatabase($defaultConfig, $database);
            } elseif ($driver === 'mysqli' || $driver === 'mysql') {
                $this->createMySqlDatabase($defaultConfig, $database);
            } else {
                // For other drivers, just re-throw the exception
                throw $e;
            }
            
            // Re-initialize connection after database creation
            $this->connection->initialize();
        }
    }
    
    /**
     * Create SQL Server database
     */
    private function createSqlServerDatabase(array $config, string $database): void
    {
        // Connect to master database to create new database
        $masterConfig = $config;
        $masterConfig['database'] = 'master';
        
        // Create a new connection to master database
        $db = \Config\Database::connect($masterConfig, false);
        
        try {
            // Check if database exists
            $query = $db->query("SELECT name FROM sys.databases WHERE name = ?", [$database]);
            $exists = $query->getNumRows() > 0;
            
            if (!$exists) {
                // Create database
                $dbName = '[' . str_replace(']', ']]', $database) . ']';
                $db->query("CREATE DATABASE {$dbName}");
                echo "Database '{$database}' created successfully.\n";
            }
        } finally {
            $db->close();
        }
    }
    
    /**
     * Create MySQL database
     */
    private function createMySqlDatabase(array $config, string $database): void
    {
        // Connect without database to create new database
        $tempConfig = $config;
        $tempConfig['database'] = '';
        
        $db = \Config\Database::connect($tempConfig, false);
        
        try {
            // Check if database exists
            $query = $db->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?", [$database]);
            $exists = $query->getNumRows() > 0;
            
            if (!$exists) {
                // Create database
                $dbName = '`' . str_replace('`', '``', $database) . '`';
                $db->query("CREATE DATABASE {$dbName}");
                echo "Database '{$database}' created successfully.\n";
            }
        } finally {
            $db->close();
        }
    }

    /**
     * Add migration (equivalent to Add-Migration)
     */
    public function addMigration(string $migrationName, callable $up = null, callable $down = null): string
    {
        $timestamp = date('YmdHis');
        $className = 'Migration_' . $timestamp . '_' . $migrationName;
        $fileName = $timestamp . '_' . $migrationName . '.php';
        $filePath = $this->migrationsPath . $fileName;

        // Generate content
        if ($up !== null && $down !== null) {
            // Use provided callables
            $content = $this->generateMigrationContentFromCallables($className, $up, $down);
        } else {
            // Try to generate from ApplicationDbContext
            $generated = $this->generateMigrationFromContext();
            
            error_log('addMigration: generated is ' . ($generated !== null ? 'not null' : 'null'));
            
            // Use generated code if available, otherwise use template
            if ($generated !== null && is_array($generated) && isset($generated['up']) && isset($generated['down']) && !empty(trim($generated['up'])) && !empty(trim($generated['down']))) {
                error_log('addMigration: using generated code. Up length: ' . strlen($generated['up']) . ', Down length: ' . strlen($generated['down']));
                $content = $this->generateMigrationContentFromCode($className, $generated['up'], $generated['down']);
            } else {
                error_log('addMigration: falling back to template. Generated: ' . ($generated !== null ? 'not null but invalid' : 'null'));
                $content = $this->generateMigrationContentFromCode($className, $this->getDefaultUpCode(), $this->getDefaultDownCode());
            }
        }

        file_put_contents($filePath, $content);

        return $fileName;
    }

    /**
     * Generate migration content from callables
     */
    private function generateMigrationContentFromCallables(string $className, callable $up, callable $down): string
    {
        // For callables, we'll use the template approach
        // In a full implementation, we'd need to serialize the builder operations
        return $this->generateMigrationContentFromCode($className, $this->getDefaultUpCode(), $this->getDefaultDownCode());
    }

    /**
     * Generate migration content from code strings
     */
    private function generateMigrationContentFromCode(string $className, string $upCode, string $downCode): string
    {
        // Indent the code
        $upCode = $this->indentCode($upCode, 8);
        $downCode = $this->indentCode($downCode, 8);
        
        return <<<PHP
<?php

namespace App\Database\Migrations;

use Yakupeyisan\CodeIgniter4\EntityFramework\Migrations\Migration;
use Yakupeyisan\CodeIgniter4\EntityFramework\Migrations\MigrationBuilder;
use Yakupeyisan\CodeIgniter4\EntityFramework\Migrations\ColumnBuilder;

class {$className} extends Migration
{
    public function up(): void
    {
        \$builder = new MigrationBuilder(\$this->connection);
        
{$upCode}
        
        \$builder->execute();
    }

    public function down(): void
    {
        \$builder = new MigrationBuilder(\$this->connection);
        
{$downCode}
        
        \$builder->execute();
    }
}
PHP;
    }

    /**
     * Indent code with specified number of spaces
     */
    private function indentCode(string $code, int $spaces): string
    {
        if (empty(trim($code))) {
            return $code;
        }
        
        $indent = str_repeat(' ', $spaces);
        $lines = explode("\n", $code);
        $indented = array_map(function($line) use ($indent) {
            // Don't indent empty lines
            if (trim($line) === '') {
                return $line;
            }
            return $indent . $line;
        }, $lines);
        return implode("\n", $indented);
    }

    /**
     * Generate migration from ApplicationDbContext
     * 
     * @param string|null $contextClass The fully qualified class name of the DbContext (e.g., 'App\EntityFramework\ApplicationDbContext')
     * @return array|null Returns array with 'up' and 'down' keys, or null if generation fails
     */
    public function generateMigrationFromContext(?string $contextClass = null): ?array
    {
        // Try to auto-detect ApplicationDbContext if not provided
        if ($contextClass === null) {
            // Common locations for ApplicationDbContext
            $possibleClasses = [
                'App\EntityFramework\ApplicationDbContext',
                'App\ApplicationDbContext',
                'ApplicationDbContext'
            ];
            
            foreach ($possibleClasses as $class) {
                if (class_exists($class)) {
                    $contextClass = $class;
                    break;
                }
            }
        }
        
        if ($contextClass === null || !class_exists($contextClass)) {
            error_log("Context class not found. Please provide the fully qualified class name of your DbContext.");
            return null;
        }
        
        try {
            $generator = new MigrationGenerator($contextClass, $this->connection);
            $result = $generator->generateMigrationCode();
            
            // Return null if generation failed (empty code)
            if (empty($result['up']) || empty($result['down'])) {
                return null;
            }
            
            return $result;
        } catch (\Exception $e) {
            error_log("Error generating migration from context: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Update database (equivalent to Update-Database)
     */
    public function updateDatabase(?string $targetMigration = null): void
    {
        $migrations = $this->getPendingMigrations();
        
        if ($targetMigration !== null) {
            $migrations = array_filter($migrations, fn($m) => $m['name'] <= $targetMigration);
        }

        foreach ($migrations as $migration) {
            $this->runMigration($migration, 'up');
        }
    }

    /**
     * Remove migration (equivalent to Remove-Migration)
     */
    public function removeMigration(string $migrationName): bool
    {
        $files = glob($this->migrationsPath . '*_' . $migrationName . '.php');
        if (empty($files)) {
            return false;
        }

        foreach ($files as $file) {
            unlink($file);
        }

        return true;
    }

    /**
     * Rollback migration
     */
    public function rollbackMigration(int $steps = 1): void
    {
        $appliedMigrations = $this->getAppliedMigrations();
        $allMigrations = $this->getAllMigrations();
        
        // Create a map of applied migrations by timestamp+name
        $appliedMap = [];
        foreach ($appliedMigrations as $applied) {
            $key = $applied['timestamp'] . '_' . $applied['name'];
            $appliedMap[$key] = $applied;
        }
        
        // Match applied migrations with file paths from all migrations
        $migrationsToRollback = [];
        foreach ($allMigrations as $migration) {
            $key = $migration['timestamp'] . '_' . $migration['name'];
            if (isset($appliedMap[$key])) {
                $migrationsToRollback[] = $migration; // Use migration with 'file' key
            }
        }
        
        // Get last N migrations
        $migrationsToRollback = array_slice($migrationsToRollback, -$steps);

        foreach ($migrationsToRollback as $migration) {
            $this->runMigration($migration, 'down');
        }
    }

    /**
     * Get pending migrations
     */
    public function getPendingMigrations(): array
    {
        $allMigrations = $this->getAllMigrations();
        $appliedMigrations = $this->getAppliedMigrations();
        $appliedNames = array_column($appliedMigrations, 'name');

        return array_filter($allMigrations, fn($m) => !in_array($m['name'], $appliedNames));
    }

    /**
     * Get all migrations
     */
    public function getAllMigrations(): array
    {
        $files = glob($this->migrationsPath . '*.php');
        $migrations = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');
            $parts = explode('_', $name, 2);
            if (count($parts) === 2) {
                $migrations[] = [
                    'timestamp' => $parts[0],
                    'name' => $parts[1],
                    'file' => $file
                ];
            }
        }

        usort($migrations, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
        return $migrations;
    }

    /**
     * Get applied migrations
     */
    public function getAppliedMigrations(): array
    {
        // Check migrations table
        if (!$this->connection->tableExists('migrations')) {
            $this->createMigrationsTable();
            return [];
        }

        $query = $this->connection->query("SELECT * FROM migrations ORDER BY timestamp DESC");
        $results = $query->getResultArray();

        return array_map(fn($r) => ['timestamp' => $r['timestamp'], 'name' => $r['name']], $results);
    }

    /**
     * Run migration
     */
    private function runMigration(array $migration, string $direction): void
    {
        require_once $migration['file'];
        $className = 'App\\Database\\Migrations\\Migration_' . $migration['timestamp'] . '_' . $migration['name'];
        
        if (class_exists($className)) {
            $migrationInstance = new $className($this->connection);
            if ($direction === 'up') {
                $migrationInstance->up();
                $this->recordMigration($migration);
            } else {
                $migrationInstance->down();
                $this->removeMigrationRecord($migration);
            }
        }
    }

    /**
     * Record migration
     */
    private function recordMigration(array $migration): void
    {
        if (!$this->connection->tableExists('migrations')) {
            $this->createMigrationsTable();
        }

        $this->connection->table('migrations')->insert([
            'timestamp' => $migration['timestamp'],
            'name' => $migration['name'],
            'applied_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Remove migration record
     */
    private function removeMigrationRecord(array $migration): void
    {
        $this->connection->table('migrations')
            ->where('timestamp', $migration['timestamp'])
            ->where('name', $migration['name'])
            ->delete();
    }

    /**
     * Create migrations table
     */
    private function createMigrationsTable(): void
    {
        $driver = strtolower($this->connection->getPlatform() ?? '');
        
        // SQL Server uses IDENTITY instead of AUTO_INCREMENT
        if ($driver === 'sqlsrv' || $driver === 'sqlserver') {
            $this->createMigrationsTableSqlServer();
        } else {
            // MySQL and other databases
            $forge = new \CodeIgniter\Database\Forge($this->connection);
            $forge->addField([
                'id' => ['type' => 'INT', 'auto_increment' => true],
                'timestamp' => ['type' => 'VARCHAR(14)'],
                'name' => ['type' => 'VARCHAR(255)'],
                'applied_at' => ['type' => 'DATETIME']
            ]);
            $forge->addKey('id', true);
            $forge->createTable('migrations');
        }
    }
    
    /**
     * Create migrations table for SQL Server
     */
    private function createMigrationsTableSqlServer(): void
    {
        $sql = "CREATE TABLE [migrations] (
            [id] INT IDENTITY(1,1) PRIMARY KEY,
            [timestamp] VARCHAR(14) NOT NULL,
            [name] VARCHAR(255) NOT NULL,
            [applied_at] DATETIME NOT NULL
        )";
        
        $this->connection->query($sql);
    }

    /**
     * Generate migration content
     */

    /**
     * Get default up code template
     */
    private function getDefaultUpCode(): string
    {
        return <<<'CODE'
        // Örnek: Tablo oluşturma
        // $builder->createTable('TableName', function(ColumnBuilder $columns) {
        //     $columns->integer('Id')->primaryKey()->autoIncrement();
        //     $columns->string('Name', 255)->notNull();
        //     $columns->datetime('CreatedAt')->nullable();
        //     $columns->datetime('UpdatedAt')->nullable();
        // });
        
        // Örnek: Index oluşturma
        // $builder->createIndex('TableName', 'IX_TableName_Name', ['Name'], true);
        
        // Örnek: Foreign key oluşturma
        // $builder->addForeignKey(
        //     'TableName',
        //     'FK_TableName_OtherTable',
        //     ['OtherTableId'],
        //     'OtherTable',
        //     ['Id'],
        //     'CASCADE'
        // );
CODE;
    }

    /**
     * Get default down code template
     */
    private function getDefaultDownCode(): string
    {
        return <<<'CODE'
        // Rollback işlemleri (up metodundaki işlemlerin tersi)
        // Örnek: Tablo silme
        // $builder->dropTable('TableName');
CODE;
    }
}

