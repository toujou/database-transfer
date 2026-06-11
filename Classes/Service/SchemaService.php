<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Service;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\UniqueConstraint;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\StringType;
use Toujou\DatabaseTransfer\Schema\SchemaParser;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Schema\ConnectionMigrator;
use TYPO3\CMS\Core\Database\Schema\SqlReader;
use TYPO3\CMS\Core\Utility\GeneralUtility;

readonly class SchemaService
{
    public function __construct(
        private SchemaParser $schemaParser,
    ) {}

    public function getIndexTableName(string $type, string $importSourceName): string
    {
        return "sys_databasetransfer_{$type}_{$importSourceName}";
    }

    public function establishIndexTable(Connection $targetDatabase, string $type, string $importSourceName): string
    {
        $schemaManager = $targetDatabase->createSchemaManager();
        $schemaConfig = $schemaManager->createSchemaConfig();
        $transferIndexTableName = $this->getIndexTableName($type, $importSourceName);
        if ($schemaManager->tablesExist([$transferIndexTableName])) {
            return $transferIndexTableName;
        }

        $columns = [
            new Column('tablename', new StringType(), ['length' => $schemaConfig->getMaxIdentifierLength(), 'notnull' => true]),
            new Column('sourceuid', new IntegerType(), ['notnull' => true]),
            new Column('updated_at', new IntegerType(), ['notnull' => false]),
            new Column('type', new StringType(), ['length' => 8, 'notnull' => true]),
            new Column('targetuid', new IntegerType(), ['default' => null, 'notnull' => false]),
        ];

        if ($type === 'export') {
            $columns[] = new Column('identifier', new StringType(), ['default' => null, 'notnull' => false]);

        }

        $exportSelectionTable = new Table(
            $transferIndexTableName,
            $columns,
            [new Index('idx_tablename_sourceuid_' . crc32($transferIndexTableName), ['tablename', 'sourceuid'])],
            [
                new UniqueConstraint('uc_tablename_sourceuid_' . crc32($transferIndexTableName), ['tablename', 'sourceuid']),
                // This works because targetuid default is null
                new UniqueConstraint('uc_tablename_targetuid_' . crc32($transferIndexTableName), ['tablename', 'targetuid']),
            ],
            [],
        );
        ConnectionMigrator::create(ConnectionPool::DEFAULT_CONNECTION_NAME, $targetDatabase, [$exportSelectionTable])->install();

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

    /**
     * @param string[] $tableNames
     */
    public function establishSchemaOfTables(Connection $targetDatabase, array $tableNames): void
    {
        $sqlReader = GeneralUtility::makeInstance(SqlReader::class);
        $databaseDefinitions = $sqlReader->getCreateTableStatementArray($sqlReader->getTablesDefinitionString());
        $tables = \array_filter(
            $this->schemaParser->parseCreateTableStatements($databaseDefinitions),
            fn(Table $table) => \in_array($table->getName(), $tableNames),
        );
        ConnectionMigrator::create(ConnectionPool::DEFAULT_CONNECTION_NAME, $targetDatabase, $tables)->install();
    }

    /**
     * @param string[] $tableNames
     *
     * @return mixed[]
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function getTableColumnMeta(Connection $connection, array $tableNames): array
    {
        $schemaManager = $connection->createSchemaManager();
        $tableColumnMeta = [];
        foreach ($tableNames as $tableName) {
            $columns = $schemaManager->introspectTable($tableName)->getColumns();
            $columns = \array_combine(
                // This remapping is nessary as we get eg CType returned as ctype and simple array operations like key intersection doesn't work anymore.
                \array_map(fn(Column $column) => $column->getName(), $columns),
                \array_map(fn(Column $column) => $column, $columns),
            );
            $tableColumnMeta[$tableName] = [
                'defaults' => \array_map(
                    fn(Column $column) => $column->getName() === 'uid' ? null : ($column->getDefault() ?? ''),
                    \array_filter($columns, fn(Column $column) => $column->getNotnull()),
                ),
                'types' => \array_map(fn(Column $column) => $column->getType(), $columns),
            ];
        }

        return $tableColumnMeta;
    }
}
