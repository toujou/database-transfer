<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Service;

use Symfony\Component\Console\Style\SymfonyStyle;
use Toujou\DatabaseTransfer\Database\RelationAnalyzer;
use Toujou\DatabaseTransfer\Database\RelationEditor;
use Toujou\DatabaseTransfer\DTO\MmTableRecordAction;
use Toujou\DatabaseTransfer\DTO\RecordAction;
use Toujou\DatabaseTransfer\DTO\RecordChangeSet;
use Toujou\DatabaseTransfer\DTO\RelationTranslation;
use Toujou\DatabaseTransfer\Export\ExportIndexFactory;
use Toujou\DatabaseTransfer\Export\ImportIndexFactory;
use Toujou\DatabaseTransfer\Export\Selection;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

readonly class TransferService
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private ExportIndexFactory $exportIndexFactory,
        private ImportIndexFactory $importIndexFactory,
        private SchemaService $schemaService,
        private RelationEditor $relationEditor,
    ) {}

    public function transfer(
        Selection $selection,
        string $sourceConnectionName,
        string $importSourceName,
        bool $isDeltaUpdate = false,
        bool $dryRun = false,
        ?SymfonyStyle $io = null,
    ): void {
        // Connection of the export side
        $sourceDatabaseConnection = $this->connectionPool->getConnectionByName($sourceConnectionName);

        // Connection of the current instance (aka target of import)
        $connection = $this->connectionPool->getConnectionForTable(ExportIndexFactory::TABLENAME_REFERENCE_INDEX);

        $importIndex = $this->importIndexFactory->createImportIndex($connection, $importSourceName);
        $exportIndex = $this->exportIndexFactory->createExportIndex($sourceDatabaseConnection, $importSourceName, $selection);

        $allTableNames = $exportIndex->getAllTableNames();
        $this->schemaService->establishSchemaOfTables($sourceDatabaseConnection, $allTableNames);
        $tableColumnMetas = $this->schemaService->getTableColumnMeta($sourceDatabaseConnection, $allTableNames);

        $comparisonResult = $importIndex->compare($exportIndex, $isDeltaUpdate);
        if ($dryRun) {
            $this->outputComparisonResult($comparisonResult, $io);
            return;
        }

        // This transaction leads to roughly 100x performance improvement on sqlite
        $connection->transactional(function (Connection $targetDatabase) use ($importIndex, $exportIndex, $tableColumnMetas, $comparisonResult) {
            foreach ($comparisonResult->getRecordsToCreate() as $item) {
                // Insert placeholder to get target id
                $this->insertRow($targetDatabase, $item->tableName, [], $tableColumnMetas[$item->tableName]);
                $targetUid = (int)$targetDatabase->lastInsertId();
                $importIndex->addToIndex($targetDatabase, $item, $targetUid);
            }

            $mmComparisonResult = $importIndex->compareMmTableRecords($exportIndex);
            foreach ($mmComparisonResult->getMmTableRecordActions() as $action) {
                $table = $action->getTableName();
                $row = $action->getData();

                if ($action->getActionType() !== MmTableRecordAction::CREATE) {
                    $allowedKeys = ['uid_local', 'uid_foreign', 'tablenames', 'fieldname'];
                    $filteredRow = array_intersect_key($row, array_flip($allowedKeys));
                    $this->deleteRow($targetDatabase, $table, $filteredRow);
                }

                if ($action->getActionType() !== MmTableRecordAction::DELETE) {
                    $this->insertRow($targetDatabase, $table, $row, $tableColumnMetas[$table]);
                }
            }

            // TODO replace by $importIndex->deleteRefindex
            foreach ($comparisonResult->getRecordsToUpdate() as $row) {
                if ($row->updatedAt !== null) {
                    $importIndex->updateUpdatedAtTimestamp($targetDatabase, $row);
                }
                $this->deleteRow($targetDatabase, 'sys_refindex', ['tablename' => $row->tableName, 'recuid' => $row->targetUid]);
            }

            $exportRelationAnalyzer = new RelationAnalyzer($exportIndex);
            foreach ($exportIndex->getSourceTcaRecords($comparisonResult) as $tableName => $record) {
                $uid = $importIndex->translateUid($tableName, (int)$record['uid']);
                // Pid is a special relation, that is not tracked via refindex
                if (isset($record['pid']) && $record['pid'] > 0) {
                    // TODO This needs some thoughts:
                    // * check whether fallback to 0 is a potential security issue
                    // * if you only export records without pages, it cannot be translated. Should we use the default target pid?
                    $record['pid'] = $importIndex->translateUid('pages', (int)$record['pid']) ?? 0;
                }

                /** @var RelationTranslation[] $relationTranslations */
                $relationTranslations = \array_map([$importIndex, 'translateRelation'], $exportRelationAnalyzer->getRelationsForRecord($tableName, (int)$record['uid']));
                $record = $this->relationEditor->editRelationsInRecord($tableName, $uid, $record, $relationTranslations);
                foreach ($relationTranslations as $relationTranslation) {
                    if ($tableName === $relationTranslation->translated?->getTableName()) {
                        try {
                            $this->insertRow($targetDatabase, 'sys_refindex', $relationTranslation->translated->toArray(), $tableColumnMetas['sys_refindex']);
                        } catch (\Exception) {
                            // ignore if inserting refindex fails (same hash)
                        }
                    }
                }

                $this->updateRow($targetDatabase, $tableName, $record, ['uid' => $uid], $tableColumnMetas[$tableName]);
            }

            foreach ($comparisonResult->getRecordsToDelete() as $row) {
                $this->deleteRow($targetDatabase, 'sys_refindex', ['tablename' => $row->tableName, 'recuid' => $row->targetUid]);
                $this->deleteRow($targetDatabase, $row->tableName, ['uid' => $row->targetUid]);
                $importIndex->removeFromIndex($targetDatabase, $row->tableName, (int)$row->targetUid);
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

        if (($row['uid'] ?? null) === null) {
            unset($row['uid']);
        }

        try {
            $targetDatabase->insert($tableName, $row, $tableColumnMeta['types']);
        } catch (\Exception $e) {
            throw new \Exception(sprintf('Error on trying to insert %s on %s with meta %s', json_encode($row), $tableName, json_encode($tableColumnMeta['defaults'])), $e->getCode(), $e);
        }
    }

    /**
     * @param mixed[] $row
     * @param mixed[] $identifier
     * @param mixed[] $tableColumnMeta
     */
    private function updateRow(Connection $targetDatabase, string $tableName, array $row, array $identifier, array $tableColumnMeta): void
    {
        unset($row['uid']);
        $row = \array_intersect_key($row, $tableColumnMeta['types']);

        $targetDatabase->update($tableName, $row, $identifier, $tableColumnMeta['types']);
    }

    /**
     * @param mixed[] $identifier
     */
    private function deleteRow(Connection $targetDatabase, string $tableName, array $identifier): void
    {
        $targetDatabase->delete($tableName, $identifier);
    }

    private function outputComparisonResult(RecordChangeSet $comparisonResult, ?SymfonyStyle $io): void
    {
        if ($io === null) {
            return;
        }

        $hasChanges = false;

        if ($comparisonResult->getRecordsToCreate() !== []) {
            $hasChanges = true;
            $rows = array_map(fn(RecordAction $recordAction) => [
                'INSERT',
                $recordAction->tableName,
                $recordAction->sourceUid ?? '-',
            ], $comparisonResult->getRecordsToCreate());

            $io->section(sprintf('New records (%d)', count($rows)));
            $io->table(
                ['Action', 'Table', 'Source-UID'],
                $rows,
            );

        }
        if ($comparisonResult->getRecordsToUpdate() !== []) {
            $hasChanges = true;
            $rows = array_map(fn(RecordAction $recordAction) => [
                'UPDATE',
                $recordAction->tableName,
                $recordAction->sourceUid ?? '-',
                $recordAction->targetUid ?? '-',
            ], $comparisonResult->getRecordsToUpdate());

            $io->section(sprintf('Update records (%d)', count($rows)));
            $io->table(
                ['Action', 'Table', 'Source-UID', 'Target-UID'],
                $rows,
            );
        }

        if ($comparisonResult->getRecordsToDelete() !== []) {
            $hasChanges = true;
            $rows = array_map(fn(RecordAction $recordAction) => [
                'DELETE',
                $recordAction->tableName,
                $recordAction->targetUid ?? '-',
            ], $comparisonResult->getRecordsToDelete());

            $io->section(sprintf('Delete records (%d)', count($rows)));
            $io->table(
                ['Action', 'Table', 'Target-UID'],
                $rows,
            );
        }

        if (!$hasChanges) {
            $io->success('Dry run completed: no changes detected.');
        }
    }
}
