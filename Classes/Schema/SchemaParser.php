<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Schema;

use Doctrine\DBAL\Schema\Table;

readonly class SchemaParser
{
    public function __construct(
        private SchemaMigrator $schemaMigrator,
    ) {}

    /**
     * @param string[] $statements The SQL CREATE TABLE statements
     *
     * @return array<non-empty-string, Table>
     */
    public function parseCreateTableStatements(array $statements): array
    {
        return $this->schemaMigrator->parseCreateTableStatements($statements);
    }
}
