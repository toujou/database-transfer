<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Service;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Toujou\DatabaseTransfer\DBAL\TableMigrator;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SchemaService
{
    public function establishSchemaOfTables(Connection $targetDatabase, array $tableNames): void
    {
        $sqlReader = GeneralUtility::makeInstance(SqlReader::class);
        $databaseDefinitions = $sqlReader->getCreateTableStatementArray($sqlReader->getTablesDefinitionString());
        $schemaMigrator = GeneralUtility::makeInstance(SchemaMigrator::class);
        $tables = \array_filter($schemaMigrator->parseCreateTableStatements($databaseDefinitions), fn (Table $table) => \in_array($table->getName(), $tableNames));
        (new TableMigrator($targetDatabase, $tables))->install();
    }

    public function getTableColumnTypes(Connection $connection, array $tableNames)
    {
        $schemaManager = $connection->createSchemaManager();
        $tableColumnTypes = [];
        foreach ($tableNames as $tableName) {
            $columns = $schemaManager->introspectTable($tableName)->getColumns();
            $tableColumnTypes[$tableName] = \array_combine(
                \array_map(fn (Column $column) => $column->getName(), $columns),
                \array_map(fn (Column $column) => $column->getType(), $columns),
            );
        }

        return $tableColumnTypes;
    }
}
