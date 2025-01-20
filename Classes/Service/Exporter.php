<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Service;

use Toujou\DatabaseTransfer\Database\RelationHandler;
use Toujou\DatabaseTransfer\Export\ExportIndex;
use Toujou\DatabaseTransfer\Export\ExportIndexFactory;
use Toujou\DatabaseTransfer\Export\Selection;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\ReferenceIndex;

class Exporter
{
    public function __construct(
        private readonly ConnectionPool     $connectionPool,
        private readonly ExportIndexFactory $exportIndexFactory,
        private readonly SchemaService      $schemaService,
        private readonly RelationHandler    $relationHandler,
        private readonly ReferenceIndex     $referenceIndex
    ) {
    }

    public function export(Selection $selection, string $targetConnectionName): void
    {
        $targetDatabase = $this->connectionPool->getConnectionByName($targetConnectionName);

        $exportIndex = $this->exportIndexFactory->createExportIndex($selection, $targetConnectionName);
        $allTableNames = $exportIndex->getAllTableNames();
        $allTableNames[] = $exportIndexTableName = $this->exportIndexFactory->establishExportIndexTable($targetDatabase, 'export');
        $this->schemaService->establishSchemaOfTables($targetDatabase, $allTableNames);
        $tableColumnNames = $this->schemaService->getTableColumnTypes($targetDatabase, $allTableNames);

        // This transaction leads to roughly 100x performance improvement on sqlite
        $targetDatabase->transactional(function (Connection $targetDatabase) use ($exportIndex, $tableColumnNames, $exportIndexTableName) {
            // TODO create import map here with olduid to newuid mapping
            // TODO get newuid by importing dummy records with defaults (use the first best from the export index as it fulfills all constraints already)
            // TODO keep an importmap as object, maybe persist it in the db to support for repeated updates
            // TODO eventually we need temporarly the source and permanently the target index within the same db, to check for updates via tstamp?
            foreach ($exportIndex->getExportIndex($exportIndexTableName) as $row) {
                $this->insertRow($targetDatabase, $row, $tableColumnNames);
            }
            foreach ($exportIndex->getReferenceIndex() as $row) {
                $this->insertRow($targetDatabase, $row, $tableColumnNames);
            }
            foreach ($exportIndex->getRecords() as $row) {
                $row = $this->filterLostRelations($row, $exportIndex);
                $this->insertRow($targetDatabase, $row, $tableColumnNames);
            }
            foreach ($exportIndex->getMMRelations() as $row) {
                $this->insertRow($targetDatabase, $row, $tableColumnNames);
            }
        });

        return;
        var_dump(memory_get_peak_usage(true) / 1024 / 1024);
        die();
    }

    private function filterLostRelations(array $record, ExportIndex $exportIndex): array
    {
        $lostRelations = \iterator_to_array($exportIndex->getLostRelationsForRecord($record['_tablename'], (int) $record['uid']));

        return $this->relationHandler->removeRelationsFromRecord($lostRelations, $record);
    }

    private function insertRow(Connection $targetDatabase, array $row, array $columnTypes): void
    {
        $tableName = \array_shift($row);
        $types = $columnTypes[$tableName];
        $row = \array_intersect_key($row, $types);
        $targetDatabase->insert($tableName, $row, $types);
    }
}
