<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Schema;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Schema\DefaultTcaSchema;
use TYPO3\CMS\Core\Database\Schema\Parser\Parser;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;

class SchemaParser
{
    private SchemaMigrator $migrator;

    public function __construct(
        ConnectionPool $connectionPool,
        Parser $parser,
        DefaultTcaSchema $defaultTcaSchema,
    ) {
        $this->migrator = new class($connectionPool, $parser, $defaultTcaSchema) extends SchemaMigrator {
            public function parseCreateTableStatements(array $statements): array
            {
                return parent::parseCreateTableStatements($statements);
            }
        };
    }

    public function parseCreateTableStatements(array $statements): array
    {
        return $this->migrator->parseCreateTableStatements($statements);
    }
}
