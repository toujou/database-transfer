<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\DBAL;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Schema\ConnectionMigrator;

class TableMigrator extends ConnectionMigrator
{
    protected $connectionName = ConnectionPool::DEFAULT_CONNECTION_NAME;

    public function __construct(Connection $connection, array $tables)
    {
        $this->connection = $connection;
        $this->tables = $tables;
    }
}
