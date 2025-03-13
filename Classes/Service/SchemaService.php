<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Service;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\UniqueConstraint;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\StringType;
use Toujou\DatabaseTransfer\DBAL\TableMigrator;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SchemaService
{

    public function getIndexTableName(string $type, string $transferName)
    {
        return "sys_databasetransfer_{$type}_{$transferName}";
    }

    public function establishIndexTable(Connection $targetDatabase, string $type, string $transferName): string
    {
        $schemaManager = $targetDatabase->createSchemaManager();
        $transferIndexTableName = $this->getIndexTableName($type, $transferName);
        if ($schemaManager->tablesExist($transferIndexTableName)) {
            return $transferIndexTableName;
        }
        $exportSelectionTable = new Table(
            $transferIndexTableName,
            [
                new Column('tablename', new StringType(), ['notnull' => true]),
                new Column('sourceuid', new IntegerType(), ['notnull' => true]),
                new Column('type', new StringType(), ['notnull' => true]),
                new Column('targetuid', new IntegerType(), ['default' => null, 'notnull' => false]),
            ],
            [new Index('idx_tablename_sourceuid_' . crc32($transferIndexTableName), ['tablename', 'sourceuid'])],
            [
                new UniqueConstraint('uc_tablename_sourceuid_' . crc32($transferIndexTableName), ['tablename', 'sourceuid']),
                // This works because targetuid default is null
                new UniqueConstraint('uc_tablename_targetuid_' . crc32($transferIndexTableName), ['tablename', 'targetuid'])
            ],
            []
        );
        $schemaManager->createTable($exportSelectionTable);
        return $transferIndexTableName;
    }

    public function emptyTable(Connection $targetDatabase, string $tableName): void
    {
        $targetDatabase->truncate($tableName);
    }

    public function dropTable(Connection $targetDatabase, string $tableName): void
    {
        $schemaManager = $targetDatabase->createSchemaManager();
        $schemaManager->dropTable($tableName);
    }

    public function renameTable(Connection $targetDatabase, string $tableName, string $oldTableName): void
    {
        $schemaManager = $targetDatabase->createSchemaManager();
        $schemaManager->renameTable($oldTableName, $tableName);
    }

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
