<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Service;

use Toujou\DatabaseTransfer\Database\RelationAnalyzer;
use Toujou\DatabaseTransfer\Database\RelationEditor;
use Toujou\DatabaseTransfer\Export\ExportIndexFactory;
use Toujou\DatabaseTransfer\Export\ImportIndexFactory;
use Toujou\DatabaseTransfer\Export\Selection;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

class TransferService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ExportIndexFactory $exportIndexFactory,
        private readonly ImportIndexFactory $importIndexFactory,
        private readonly SchemaService $schemaService,
        private readonly RelationEditor $relationEditor,
    ) {}

    public function transfer(Selection $selection, string $transferName, string $importSource): void
    {
        $targetDatabaseConnection = $this->connectionPool->getConnectionByName($transferName);

        $importIndex = $this->importIndexFactory->createImportIndex($targetDatabaseConnection, $importSource);
        $exportIndex = $this->exportIndexFactory->createExportIndex($selection, $importSource);

        $allTableNames = $exportIndex->getAllTableNames();
        $this->schemaService->establishSchemaOfTables($targetDatabaseConnection, $allTableNames);
        $tableColumnMetas = $this->schemaService->getTableColumnMeta($targetDatabaseConnection, $allTableNames);

        // This transaction leads to roughly 100x performance improvement on sqlite
        $targetDatabaseConnection->transactional(function (Connection $targetDatabase) use ($importIndex, $exportIndex, $tableColumnMetas) {
            // TODO refactor existing to "updated" by comparing last modified
            // TODO consider a state machine, as the order of actions is very relevant here

            [$recordsToCreate, $recordsToUpdate, $recordsToDelete] = $importIndex->compare($exportIndex);
            foreach ($recordsToCreate as $ident => $row) {
                // Insert placeholder to get target id
                $this->insertRow($targetDatabase, $row['tablename'], [], $tableColumnMetas[$row['tablename']]);
                $targetUid = (int)$targetDatabase->lastInsertId();
                $recordsToCreate[$ident] = $importIndex->addToIndex($row['tablename'], $row['sourceuid'], $row['type'], $targetUid);
            }

            $exportIndex->updateIndex(\array_merge($recordsToCreate, $recordsToUpdate));

            foreach ($importIndex->getMMRecords($exportIndex) as $mmTableName => $row) {
                $this->deleteRow($targetDatabase, $mmTableName, $row);
            }
            foreach ($exportIndex->getMMRecords() as $mmTableName => $row) {
                $localTable = $row['_local_table'];
                $foreignTable = $row['_foreign_table'];
                unset($row['_local_table'], $row['_foreign_table']);
                $row['uid_local'] = $importIndex->translateUid($localTable, $row['uid_local']);
                $row['uid_foreign'] = $importIndex->translateUid($foreignTable, $row['uid_foreign']);
                $this->insertRow($targetDatabase, $mmTableName, $row, $tableColumnMetas[$mmTableName]);
            }

            // TODO replace by $importIndex->deleteRefindex
            foreach ($recordsToUpdate as $row) {
                $this->deleteRow($targetDatabase, 'sys_refindex', ['tablename' => $row['tablename'], 'recuid' => $row['targetuid']]);
            }

            $exportRelationAnalyzer = new RelationAnalyzer($exportIndex);
            foreach ($exportIndex->getRecords() as $tableName => $record) {
                $uid = $importIndex->translateUid($tableName, (int)$record['uid']);
                // Pid is a special relation, that is not tracked via refindex
                if (isset($record['pid']) && $record['pid'] > 0) {
                    // TODO This needs some thoughts:
                    // * check whether fallback to 0 is a potential security issue
                    // * if you only export records without pages, it cannot be translated. Should we use the default target pid?
                    $record['pid'] = $importIndex->translateUid('pages', (int)$record['pid']) ?? 0;
                }

                $relations = \array_map([$importIndex, 'translateRelation'], $exportRelationAnalyzer->getRelationsForRecord($tableName, (int)$record['uid']));
                $record = $this->relationEditor->editRelationsInRecord($tableName, $uid, $record, $relations);
                foreach ($relations as $relation) {
                    if ($relation['translated'] !== null && $tableName === $relation['translated']['tablename']) {
                        $this->insertRow($targetDatabase, 'sys_refindex', $relation['translated'], $tableColumnMetas['sys_refindex']);
                    }
                }

                $this->updateRow($targetDatabase, $tableName, $record, ['uid' => $uid], $tableColumnMetas[$tableName]);
            }

            foreach ($recordsToDelete as $row) {
                $this->deleteRow($targetDatabase, 'sys_refindex', ['tablename' => $row['tablename'], 'recuid' => $row['targetuid']]);
                $this->deleteRow($targetDatabase, $row['tablename'], ['uid' => $row['targetuid']]);
                $importIndex->removeFromIndex($row['tablename'], $row['targetuid']);
            }
        });
    }

    /**
     * @param mixed[] $row
     * @param mixed[] $tableColumnMeta
     */
    private function insertRow(Connection $targetDatabase, string $tableName, array $row, array $tableColumnMeta): void
    {
        $row = \array_replace($tableColumnMeta['defaults'], $row);
        $targetDatabase->insert($tableName, $row, $tableColumnMeta['types']);
    }

    /**
     * @param mixed[] $row
     * @param mixed[] $identifier
     * @param mixed[] $tableColumnMeta
     */
    private function updateRow(Connection $targetDatabase, string $tableName, array $row, array $identifier, array $tableColumnMeta): int
    {
        unset($row['uid']);
        $row = \array_intersect_key($row, $tableColumnMeta['types']);

        return $targetDatabase->update($tableName, $row, $identifier, $tableColumnMeta['types']);
    }

    /**
     * @param mixed[] $identifier
     */
    private function deleteRow(Connection $targetDatabase, string $tableName, array $identifier): int
    {
        return $targetDatabase->delete($tableName, $identifier);
    }
}
