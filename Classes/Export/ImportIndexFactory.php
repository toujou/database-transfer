<?php

declare(strict_types=1);


namespace Toujou\DatabaseTransfer\Export;

use Toujou\DatabaseTransfer\Service\SchemaService;
use TYPO3\CMS\Core\Database\ConnectionPool;

class ImportIndexFactory
{

    public const TABLENAME_REFERENCE_INDEX = 'sys_refindex';

    public function __construct(
        private readonly ConnectionPool $connectionPool
    ) {
    }

    public function createImportIndex(Selection $selection, string $transferName): ImportIndex
    {
        $connection = $this->connectionPool->getConnectionByName($transferName);
        $importIndex = new ImportIndex($connection, new SchemaService(), $transferName);
        return $importIndex;
    }
}
