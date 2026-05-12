<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use Toujou\DatabaseTransfer\Schema\SchemaMigrator;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Schema\DefaultTcaSchema;
use TYPO3\CMS\Core\Database\Schema\Parser\Lexer;
use TYPO3\CMS\Core\Database\Schema\Parser\Parser;
use TYPO3\CMS\Core\Database\Schema\SchemaMigrator as CoreSchemaMigrator;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class SchemaMigratorTest extends UnitTestCase
{
    private SchemaMigrator $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new SchemaMigrator(
            $this->createMock(ConnectionPool::class),
            new Parser($this->createMock(Lexer::class)),
            $this->createMock(DefaultTcaSchema::class),
            $this->createMock(FrontendInterface::class),
        );

    }

    #[Test]
    public function itExtendsCoreSchemaMigrator(): void
    {
        self::assertInstanceOf(CoreSchemaMigrator::class, $this->subject);
    }

    #[Test]
    public function itCanParseCreateTableStatements(): void
    {
        $GLOBALS['TCA'] = [];
        $result = $this->subject->parseCreateTableStatements([]);

        self::assertIsArray($result);
    }
}
