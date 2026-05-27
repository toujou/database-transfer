<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Tests\Functional;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Toujou\DatabaseTransfer\Database\FastImportConnection;
use Toujou\DatabaseTransfer\Export\SelectionFactory;
use Toujou\DatabaseTransfer\Service\TransferService;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

abstract class AbstractTransferTestCase extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'core',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/toujou_database_transfer',
    ];

    protected array $testFilesToDelete = [];

    protected function runTransfer(array $options): void
    {
        GeneralUtility::makeInstance(ReferenceIndex::class)->updateIndex(false);

        $targetConnectionName = $this->createTemporarySqliteDatabaseAndClearSource('export');

        $selectionFactory = $this->get(SelectionFactory::class);

        $selection = $selectionFactory->buildFromCommandOptions(
            $this->getConnectionPool()->getConnectionByName($targetConnectionName),
            $options,
        );

        $transferService = $this->get(TransferService::class);
        $transferService->transfer($selection, $targetConnectionName, 'source');
    }

    private function createTemporarySqliteDatabaseAndClearSource(string $filePrefix): string
    {
        $connectionName = uniqid('', false);
        // For yet unknown reason this has to be an absolute path. Probably some working directory issues switching in the test framework.
        $fileName = $this->instancePath . '/typo3temp/var/transient/' . $filePrefix . '_' . $connectionName . '.sqlite';
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections'][$connectionName] = [
            'url' => 'pdo-sqlite:///' . $fileName,
            'wrapperClass' => FastImportConnection::class,
        ];
        $this->testFilesToDelete[] = $fileName;

        // copy schema to source database
        $sourceConnection = $this->getConnectionPool()->getConnectionByName('Default');
        $targetConnection = $this->getConnectionPool()->getConnectionByName($connectionName);

        $sourceSchemaManager = $sourceConnection->createSchemaManager();
        $targetPlatform = $targetConnection->getDatabasePlatform();

        $tables = $sourceSchemaManager->introspectTables();

        /*
            * Create SQLite schema manually.
            *
            * Cross-platform Doctrine schema conversion (MariaDB -> SQLite)
            * is unreliable because of collations, index naming differences,
            * engine options, etc.
            *
            * For test snapshots we only need a lightweight structure.
            */
        foreach ($tables as $table) {
            $tableName = $table->getObjectName()->getUnqualifiedName()->getValue();

            if (str_starts_with($tableName, 'sqlite_')) {
                continue;
            }

            $columns = [];

            foreach ($table->getColumns() as $column) {
                $columnName = $column->getName();

                // SQLite is weakly typed, TEXT is sufficient for test snapshots
                $columns[] = sprintf('"%s" TEXT', $columnName);
            }

            $sql = sprintf(
                'CREATE TABLE "%s" (%s)',
                $tableName,
                implode(', ', $columns),
            );

            $targetConnection->executeStatement($sql);
        }

        /*
         * Copy data
         */
        foreach ($tables as $table) {
            $tableName = $table->getObjectName()->getUnqualifiedName()->getValue();

            if (str_starts_with($tableName, 'sqlite_')) {
                continue;
            }

            $queryBuilder = $sourceConnection->createQueryBuilder();
            $queryBuilder->getRestrictions()->removeAll();

            $rows = $queryBuilder
                ->select('*')
                ->from($tableName)
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($rows as $row) {
                $targetConnection->insert($tableName, $row);
            }
        }

        /*
         * Clear source database
         */
        $platform = $sourceConnection->getDatabasePlatform();

        if ($platform instanceof SqlitePlatform) {
            $sourceConnection->executeStatement('PRAGMA foreign_keys = OFF');
        }

        foreach ($tables as $table) {
            $tableName = $table->getObjectName()->getUnqualifiedName()->getValue();

            if (str_starts_with($tableName, 'sqlite_')) {
                continue;
            }

            $sourceConnection->executeStatement(
                $platform->getTruncateTableSQL($tableName, true),
            );
            // Explicit PostgreSQL sequence reset
            if ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
                $sequenceName = $tableName . '_uid_seq';

                try {
                    $sourceConnection->executeStatement(sprintf(
                        'ALTER SEQUENCE "%s" RESTART WITH 1',
                        $sequenceName,
                    ));
                } catch (\Throwable) {
                    // table may not have uid sequence
                }
            }
        }

        if ($platform instanceof SqlitePlatform) {
            $sourceConnection->executeStatement('DELETE FROM sqlite_sequence');
            $sourceConnection->executeStatement('PRAGMA foreign_keys = ON');
        }

        return $connectionName;
    }

    protected function tearDown(): void
    {
        foreach ($this->testFilesToDelete as $absoluteFileName) {
            if (@is_file($absoluteFileName)) {
                unlink($absoluteFileName);
            }
        }
        parent::tearDown();
    }
}
