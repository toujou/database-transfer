<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Schema;

use Doctrine\DBAL\Schema\Table;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator as CoreSchemaMigrator;

class SchemaMigrator extends CoreSchemaMigrator
{
    /**
     * @param string[] $statements The SQL CREATE TABLE statements
     *
     * @return array<non-empty-string, Table>
     */
    public function parseCreateTableStatements(array $statements): array
    {
        return parent::parseCreateTableStatements($statements);
    }
}
