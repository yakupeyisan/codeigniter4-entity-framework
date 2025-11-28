<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Migrations;

use CodeIgniter\Database\BaseConnection;

/**
 * Migration - Base migration class
 * Equivalent to Migration in EF Core
 */
abstract class Migration
{
    protected BaseConnection $connection;

    public function __construct(BaseConnection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Up migration - Apply changes
     */
    abstract public function up(): void;

    /**
     * Down migration - Rollback changes
     */
    abstract public function down(): void;
}

