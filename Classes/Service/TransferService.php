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
        private readonly RelationEditor $relationEditor
    ) {
    }

    public function transfer(Selection $selection, string $transferName): void
    {
        $targetDatabase = $this->connectionPool->getConnectionByName($transferName);

        // Transfer name for export/import index tables?
        // Save $selection in import index and reload on subsequent transfers.
        $importIndex = $this->importIndexFactory->createImportIndex($selection, $transferName);
        $exportIndex = $this->exportIndexFactory->createExportIndex($selection, $transferName);

        $allTableNames = $exportIndex->getAllTableNames();
        $this->schemaService->establishSchemaOfTables($targetDatabase, $allTableNames);
        $tableColumnNames = $this->schemaService->getTableColumnTypes($targetDatabase, $allTableNames);

        // This transaction leads to roughly 100x performance improvement on sqlite
        $targetDatabase->transactional(function (Connection $targetDatabase) use ($importIndex, $exportIndex, $tableColumnNames) {
            // TODO refactor existing to "updated" by comparing last modified
            // TODO consider a state machine, as the order of actions is very relevant here

            [$unknown, $existing, $missing] = $importIndex->compare($exportIndex);
            foreach ($unknown as $ident => $row) {
                // Insert placeholder to get target id
                // TODO check mysql if this works, as constraints might be unfulfilled. SQlite doesn't care about column constraints
                $targetUid = $this->insertRow($targetDatabase, $row['tablename'], ['uid' => null], $tableColumnNames[$row['tablename']]);
                $unknown[$ident] = $importIndex->addToIndex($row['tablename'], $row['sourceuid'], $row['type'], $targetUid);
            }

            $exportIndex->updateIndex(\array_merge($unknown, $existing));

            $importIndex->deleteMMRecords($exportIndex);
            foreach ($exportIndex->getMMRecords() as $mmTableName => $row) {
                $localTable = $row['_localTable'];
                $foreignTable = $row['_foreignTable'];
                unset($row['_localTable'], $row['_foreignTable']);
                $row['uid_local'] = $importIndex->translateUid($localTable, $row['uid_local']);
                $row['uid_foreign'] = $importIndex->translateUid($foreignTable, $row['uid_foreign']);
                $this->insertRow($targetDatabase, $mmTableName, $row, $tableColumnNames[$mmTableName]);
            }

            // TODO replace by $importIndex->deleteRefindex
            foreach ($existing as $row) {
                $this->deleteRows($targetDatabase, 'sys_refindex', ['tablename' => $row['tablename'], 'recuid' => $row['targetuid']]);
            }

            $exportRelationAnalyzer = new RelationAnalyzer($exportIndex);
            foreach ($exportIndex->getRecords() as $tableName => $record) {
                $uid = $importIndex->translateUid($tableName, (int) $record['uid']);
                // Pid is a special relation, that is not tracked via refindex
                if (isset($record['pid']) && $record['pid'] > 0) {
                    $record['pid'] = $importIndex->translateUid('pages', (int) $record['pid']);
                }

                $relations = \array_map([$importIndex, 'translateRelation'], $exportRelationAnalyzer->getRelationsForRecord($tableName, (int) $record['uid']));
                $record = $this->relationEditor->editRelationsInRecord($tableName, $uid, $record, $relations);
                foreach ($relations as $relation) {
                    if (null !== $relation['translated'] && $tableName === $relation['translated']['tablename']) {
                        $this->insertRow($targetDatabase, 'sys_refindex', $relation['translated'], $tableColumnNames['sys_refindex']);
                    }
                }

                $this->updateRow($targetDatabase, $tableName, $record, ['uid' => $uid], $tableColumnNames[$tableName]);
            }

            foreach ($missing as $row) {
                $this->deleteRows($targetDatabase, 'sys_refindex', ['tablename' => $row['tablename'], 'recuid' => $row['targetuid']]);
                $this->deleteRows($targetDatabase, $row['tablename'], ['uid' => $row['targetuid']]);
                $importIndex->removeFromIndex($row['tablename'], $row['targetuid']);
            }
        });
    }

    private function insertRow(Connection $targetDatabase, string $tableName, array $row, array $types): int
    {
        $row = \array_intersect_key($row, $types);
        $targetDatabase->insert($tableName, $row, $types);

        return (int) $targetDatabase->lastInsertId($tableName);
    }

    private function updateRow(Connection $targetDatabase, string $tableName, array $row, array $identifier, array $types): int
    {
        unset($row['uid']);
        $row = \array_intersect_key($row, $types);

        return $targetDatabase->update($tableName, $row, $identifier, $types);
    }

    private function deleteRows(Connection $targetDatabase, string $tableName, array $identifier): int
    {
        return $targetDatabase->delete($tableName, $identifier);
    }
}
