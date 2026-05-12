<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Service;

use Psr\Log\LoggerInterface;
use Toujou\DatabaseTransfer\Database\RelationAnalyzer;
use Toujou\DatabaseTransfer\Database\RelationEditor;
use Toujou\DatabaseTransfer\DTO\MmTableRecordAction;
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
        private LoggerInterface $logger,
    ) {}

    public function transfer(Selection $selection, string $transferName, string $importSourceName, bool $isDeltaUpdate = false): void
    {
        $targetDatabaseConnection = $this->connectionPool->getConnectionByName($transferName);

        $importIndex = $this->importIndexFactory->createImportIndex($targetDatabaseConnection, $importSourceName);
        $exportIndex = $this->exportIndexFactory->createExportIndex($selection, $importSourceName);

        $allTableNames = $exportIndex->getAllTableNames();
        $this->schemaService->establishSchemaOfTables($targetDatabaseConnection, $allTableNames);
        $tableColumnMetas = $this->schemaService->getTableColumnMeta($targetDatabaseConnection, $allTableNames);

        // This transaction leads to roughly 100x performance improvement on sqlite
        $targetDatabaseConnection->transactional(function (Connection $targetDatabase) use ($importIndex, $exportIndex, $tableColumnMetas, $isDeltaUpdate) {
            $comparisonResult = $importIndex->compare($exportIndex, $isDeltaUpdate);
            foreach ($comparisonResult->getRecordsToCreate() as $item) {
                // Insert placeholder to get target id
                $this->insertRow($targetDatabase, $item->tableName, [], $tableColumnMetas[$item->tableName]);
                $targetUid = (int)$targetDatabase->lastInsertId();
                $importIndex->addToIndex($item, $targetUid);
            }

            $mmComparisonResult = $importIndex->compareMmTableRecords($exportIndex);
            foreach ($mmComparisonResult->getMmTableRecordActions() as $action) {
                $table = $action->getTableName();
                $row = $action->getData();

                if ($action->getActionType() !== MmTableRecordAction::CREATE) {
                    $this->deleteRow($targetDatabase, $table, $row);
                }

                if ($action->getActionType() !== MmTableRecordAction::DELETE) {
                    $this->insertRow($targetDatabase, $table, $row, $tableColumnMetas[$table]);
                }
            }

            // TODO replace by $importIndex->deleteRefindex
            foreach ($comparisonResult->getRecordsToUpdate() as $row) {
                if ($row->updatedAt) {
                    $importIndex->updateUpdatedAtTimestamp($row);
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
                        $this->insertRow($targetDatabase, 'sys_refindex', $relationTranslation->translated->toArray(), $tableColumnMetas['sys_refindex']);
                    }
                }

                $this->updateRow($targetDatabase, $tableName, $record, ['uid' => $uid], $tableColumnMetas[$tableName]);
            }

            foreach ($comparisonResult->getRecordsToDelete() as $row) {
                $this->deleteRow($targetDatabase, 'sys_refindex', ['tablename' => $row->tableName, 'recuid' => $row->targetUid]);
                $this->deleteRow($targetDatabase, $row->tableName, ['uid' => $row->targetUid]);
                $importIndex->removeFromIndex($row->tableName, (int)$row->targetUid);
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
        try {
            $targetDatabase->insert($tableName, $row, $tableColumnMeta['types']);
        } catch (\Exception $e) {
            $this->logger->debug($e->getMessage(), $row);
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
}
