<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Tests\Unit\Database\ForwardRelationTranslator;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Toujou\DatabaseTransfer\Database\ForwardRelationTranslator\GroupRelationTranslator;
use Toujou\DatabaseTransfer\DTO\Relation;
use Toujou\DatabaseTransfer\DTO\RelationTranslation;

final class GroupRelationTranslatorTest extends TestCase
{
    private GroupRelationTranslator $subject;

    protected function setUp(): void
    {
        $this->subject = new GroupRelationTranslator();
    }

    #[Test]
    public function supportsReturnsTrueForGroupWithAllowed(): void
    {
        self::assertTrue(
            $this->subject->supports([
                'type' => 'group',
                'allowed' => 'tt_content,pages',
            ]),
        );
    }

    #[Test]
    public function supportsReturnsFalseForWrongType(): void
    {
        self::assertFalse(
            $this->subject->supports([
                'type' => 'select',
                'allowed' => 'tt_content',
            ]),
        );
    }

    #[Test]
    public function supportsReturnsFalseWhenAllowedMissing(): void
    {
        self::assertFalse(
            $this->subject->supports([
                'type' => 'group',
            ]),
        );
    }

    #[Test]
    public function translateReplacesValuesWithPrefixedTableNames(): void
    {
        $relationTranslations = [
            RelationTranslation::create(
                original: Relation::fromArray([
                    'recuid' => 1,
                    'tablename' => 'tt_content',
                    'ref_uid' => 1,
                    'ref_table' => 'tt_content',
                ]),
                translated: Relation::fromArray([
                    'recuid' => 2,
                    'tablename' => 'tt_content',
                    'ref_uid' => 10,
                    'ref_table' => 'tt_content',

                ]),
            ),
            RelationTranslation::create(
                original: Relation::fromArray([
                    'recuid' => 1,
                    'tablename' => 'tt_content',
                    'ref_uid' => 2,
                    'ref_table' => 'pages',
                ]),
                translated: Relation::fromArray([
                    'recuid' => 2,
                    'tablename' => 'tt_content',
                    'ref_uid' => 20,
                    'ref_table' => 'pages',

                ]),
            ),
        ];

        $result = $this->subject->translate(
            $relationTranslations,
            'tt_content_1,pages_2',
            [
                'type' => 'group',
                'allowed' => 'tt_content,pages',
                'prepend_tname' => true,
            ],
        );

        self::assertSame('tt_content_10,pages_20', $result);
    }

    #[Test]
    public function translateRemovesUntranslatedValues(): void
    {
        $relationTranslations = [
            RelationTranslation::create(
                original: Relation::fromArray([
                    'recuid' => 1,
                    'tablename' => 'tt_content',
                    'ref_uid' => 1,
                    'ref_table' => 'tt_content',
                ]),
                translated: Relation::fromArray([
                    'recuid' => 2,
                    'tablename' => 'tt_content',
                    'ref_uid' => 10,
                    'ref_table' => 'tt_content',

                ]),
            ),
        ];

        $result = $this->subject->translate(
            $relationTranslations,
            'tt_content_1,tt_content_999',
            [
                'type' => 'group',
                'allowed' => 'tt_content',
                'prepend_tname' => true,
            ],
        );

        self::assertSame('tt_content_10', $result);
    }

    #[Test]
    public function translateWorksWithoutTablePrefix(): void
    {
        $relationTranslations = [
            RelationTranslation::create(
                original: Relation::fromArray([
                    'recuid' => 1,
                    'tablename' => 'tt_content',
                    'ref_uid' => 1,
                    'ref_table' => 'tt_content',
                ]),
                translated: Relation::fromArray([
                    'recuid' => 2,
                    'tablename' => 'tt_content',
                    'ref_uid' => 10,
                    'ref_table' => 'tt_content',

                ]),
            ),
        ];

        $result = $this->subject->translate(
            $relationTranslations,
            '1',
            [
                'type' => 'group',
                'allowed' => 'tt_content',
                'prepend_tname' => false,
            ],
        );

        self::assertSame('10', $result);
    }

    #[Test]
    public function translateFallsBackToMultipleAllowedTablesPrefixBehavior(): void
    {
        $relationTranslations = [
            RelationTranslation::create(
                original: Relation::fromArray([
                    'recuid' => 1,
                    'tablename' => 'tt_content',
                    'ref_uid' => 1,
                    'ref_table' => 'tt_content',
                ]),
                translated: Relation::fromArray([
                    'recuid' => 2,
                    'tablename' => 'tt_content',
                    'ref_uid' => 10,
                    'ref_table' => 'tt_content',

                ]),
            ),
        ];

        $value = 'tt_content_1';

        $result = $this->subject->translate(
            $relationTranslations,
            'tt_content_1',
            [
                'type' => 'group',
                'allowed' => 'tt_content,pages',
                // prepend_tname NOT set -> should auto-enable
            ],
        );

        self::assertSame('tt_content_10', $result);
    }

}
