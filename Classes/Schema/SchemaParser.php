<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Schema;

use Doctrine\DBAL\Schema\Table;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Schema\DefaultTcaSchema;
use TYPO3\CMS\Core\Database\Schema\Parser\Parser;

class SchemaParser
{
    private SchemaMigrator $migrator;

    public function __construct(
        ConnectionPool $connectionPool,
        Parser $parser,
        DefaultTcaSchema $defaultTcaSchema,
        #[Autowire(service: 'cache.runtime')]
        protected FrontendInterface $runtimeCache,
    ) {
        $this->migrator = new SchemaMigrator($connectionPool, $parser, $defaultTcaSchema, $runtimeCache);
    }

    /**
     * @param string[] $statements The SQL CREATE TABLE statements
     *
     * @return array<non-empty-string, Table>
     */
    public function parseCreateTableStatements(array $statements): array
    {
        return $this->migrator->parseCreateTableStatements($statements);
    }
}
