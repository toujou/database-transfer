<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Export;

use Toujou\DatabaseTransfer\Service\SchemaService;
use TYPO3\CMS\Core\Database\ConnectionPool;

class ImportIndexFactory
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SchemaService $schemaService,
    ) {}

    public function createImportIndex(Selection $selection, string $transferName): ImportIndex
    {
        $connection = $this->connectionPool->getConnectionByName($transferName);
        $importIndex = new ImportIndex($connection, $this->schemaService, $transferName);

        return $importIndex;
    }
}
