<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Toujou\DatabaseTransfer\Export\SelectionFactory;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SelectionFactoryTest extends UnitTestCase
{
    #[Test]
    #[DataProvider('languageMapProvider')]
    public function itParsesTheLanguageMap(array $languageMapOptions, array $expectedMap, array $expectedIncludedLanguageIds): void
    {
        $selection = (new SelectionFactory())->buildFromCommandOptions(
            $this->createMock(Connection::class),
            ['language-map' => $languageMapOptions],
        );

        self::assertSame($expectedMap, $selection->getLanguageMap());
        self::assertSame($expectedIncludedLanguageIds, $selection->getIncludedSourceLanguageIds());
    }

    /**
     * @return array<string, array{0: string[], 1: array<int, int>, 2: int[]}>
     */
    public static function languageMapProvider(): array
    {
        return [
            'no map' => [[], [], [-1, 0]],
            'identity list' => [['1,2'], [1 => 1, 2 => 2], [-1, 0, 1, 2]],
            'remap list' => [['1:3,2:4'], [1 => 3, 2 => 4], [-1, 0, 1, 2]],
            'mixed tokens' => [['1,2:10'], [1 => 1, 2 => 10], [-1, 0, 1, 2]],
            'repeatable values are merged' => [['1:10', '2'], [1 => 10, 2 => 2], [-1, 0, 1, 2]],
            'whitespace and empty fragments are ignored' => [[' 1 , ,2:10 '], [1 => 1, 2 => 10], [-1, 0, 1, 2]],
        ];
    }

    #[Test]
    #[DataProvider('invalidLanguageMapProvider')]
    public function itRejectsInvalidLanguageMapTokens(string $token): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SelectionFactory())->buildFromCommandOptions(
            $this->createMock(Connection::class),
            ['language-map' => [$token]],
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidLanguageMapProvider(): array
    {
        return [
            'zero' => ['0'],
            'zero source' => ['0:1'],
            'zero target' => ['1:0'],
            'too many colons' => ['1:2:3'],
            'garbage' => ['foo'],
            'float' => ['1.5'],
            'empty source' => [':1'],
            'empty target' => ['1:'],
            'duplicate target' => ['1:3,2:3'],
            'duplicate target with identity token' => ['1,2:1'],
        ];
    }

    #[Test]
    public function itRejectsDuplicateSourceLanguageIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SelectionFactory())->buildFromCommandOptions(
            $this->createMock(Connection::class),
            ['language-map' => ['1:2,1:3']],
        );
    }

    #[Test]
    public function itRejectsDuplicateTargetLanguageIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SelectionFactory())->buildFromCommandOptions(
            $this->createMock(Connection::class),
            ['language-map' => ['1:2', '2:2']],
        );
    }
}
