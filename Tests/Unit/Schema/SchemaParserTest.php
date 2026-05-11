<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Toujou\DatabaseTransfer\Schema\SchemaMigrator;
use Toujou\DatabaseTransfer\Schema\SchemaParser;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class SchemaParserTest extends UnitTestCase
{
    private SchemaParser $subject;
    private MockObject|SchemaMigrator $schemaMigratorMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schemaMigratorMock = $this->createMock(SchemaMigrator::class);
        $this->subject = new SchemaParser($this->schemaMigratorMock);
    }

    #[Test]
    public function itCanParseCreateTableStatements(): void
    {
        $this->schemaMigratorMock->expects(self::once())
            ->method('parseCreateTableStatements')
            ->with([
                'CREATE TABLE IF NOT EXISTS foo (id INTEGER PRIMARY KEY);',
            ]);

        $this->subject->parseCreateTableStatements([
            'CREATE TABLE IF NOT EXISTS foo (id INTEGER PRIMARY KEY);',
        ]);
    }
}
