<?php

declare(strict_types=1);


namespace Toujou\DatabaseTransfer\Database;

use TYPO3\CMS\Core\Database\Connection;

class FastImportConnection extends Connection
{
    protected function ensureDatabaseValueTypes(string $tableName, array &$data, array &$types): void
    {
        // We can skip this, as we only copy raw records between databases.
    }


}
