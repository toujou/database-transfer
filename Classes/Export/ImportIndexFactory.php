<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Export;

use Toujou\DatabaseTransfer\Service\SchemaService;
use TYPO3\CMS\Core\Database\Connection;

readonly class ImportIndexFactory
{
    public function __construct(
        private SchemaService $schemaService,
    ) {}

    public function createImportIndex(Connection $connection, string $importSource): ImportIndex
    {
        return new ImportIndex($connection, $this->schemaService, $importSource);
    }
}
