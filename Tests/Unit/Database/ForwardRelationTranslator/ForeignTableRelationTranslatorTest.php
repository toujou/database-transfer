<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Tests\Unit\Database\ForwardRelationTranslator;

use PHPUnit\Framework\Attributes\Test;
use Toujou\DatabaseTransfer\Database\ForwardRelationTranslator\ForeignTableRelationTranslator;
use Toujou\DatabaseTransfer\DTO\Relation;
use Toujou\DatabaseTransfer\DTO\RelationTranslation;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ForeignTableRelationTranslatorTest extends UnitTestCase
{
    private ForeignTableRelationTranslator $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new ForeignTableRelationTranslator();
    }

    #[Test]
    public function supportsReturnsTrueForValidForeignTableSelect(): void
    {
        self::assertTrue(
            $this->subject->supports([
                'type' => 'select',
                'foreign_table' => 'tt_content',
            ]),
        );
    }

    #[Test]
    public function supportsReturnsFalseWhenTypeIsInvalid(): void
    {
        self::assertFalse(
            $this->subject->supports([
                'type' => 'text',
                'foreign_table' => 'tt_content',
            ]),
        );
    }

    #[Test]
    public function supportsReturnsFalseWhenForeignTableMissing(): void
    {
        self::assertFalse(
            $this->subject->supports([
                'type' => 'select',
            ]),
        );
    }

    #[Test]
    public function supportsReturnsFalseWhenForeignFieldIsSet(): void
    {
        self::assertFalse(
            $this->subject->supports([
                'type' => 'select',
                'foreign_table' => 'tt_content',
                'foreign_field' => 'uid_foreign',
            ]),
        );
    }

    #[Test]
    public function translateReplacesUidsCorrectly(): void
    {
        $relationTranslations = [
            RelationTranslation::create(
                original: Relation::fromArray([
                    'ref_uid' => '1',
                ]),
                translated: Relation::fromArray([
                    'ref_uid' => '101',
                ]),
            ),
            RelationTranslation::create(
                original: Relation::fromArray([
                    'ref_uid' => '2',
                ]),
                translated: Relation::fromArray([
                    'ref_uid' => '202',
                ]),
            ),
        ];

        $result = $this->subject->translate($relationTranslations, '1,2', ['type' => 'select', 'foreign_table' => 'tt_content']);

        self::assertSame('101,202', $result);
    }

    #[Test]
    public function translateRemovesUnmappedValues(): void
    {
        $relationTranslations = [
            RelationTranslation::create(
                original: Relation::fromArray([
                    'ref_uid' => '1',
                ]),
                translated: Relation::fromArray([
                    'ref_uid' => '101',
                ]),
            ),
        ];

        $result = $this->subject->translate(
            $relationTranslations,
            '1,999',
            ['type' => 'select', 'foreign_table' => 'tt_content'],
        );

        self::assertSame('101', $result);
    }

    #[Test]
    public function translateReturnsEmptyStringWhenNothingMatches(): void
    {
        $input = '1,2';

        $result = $this->subject->translate(
            [],
            $input,
            ['type' => 'select', 'foreign_table' => 'tt_content'],
        );

        self::assertSame('', $result);
    }
}
