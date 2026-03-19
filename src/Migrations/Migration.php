<?php

namespace Yakupeyisan\CodeIgniter4\EntityFramework\Migrations;

use Yakupeyisan\CodeIgniter4\EntityFramework\Core\PdoAdapter;

/**
 * Migration - Base migration class
 * Equivalent to Migration in EF Core
 */
abstract class Migration
{
    protected PdoAdapter $connection;

    public function __construct(PdoAdapter $connection)
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

