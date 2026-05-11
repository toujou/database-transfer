<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Tests\Unit\Database\ForwardRelationTranslator;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Toujou\DatabaseTransfer\Database\ForwardRelationTranslator\SoftReferenceRelationTranslator;
use Toujou\DatabaseTransfer\DTO\Relation;
use Toujou\DatabaseTransfer\DTO\RelationTranslation;
use TYPO3\CMS\Core\DataHandling\SoftReference\SoftReferenceParserFactory;
use TYPO3\CMS\Core\DataHandling\SoftReference\SoftReferenceParserInterface;
use TYPO3\CMS\Core\DataHandling\SoftReference\SoftReferenceParserResult;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SoftReferenceRelationTranslatorTest extends UnitTestCase
{
    private SoftReferenceRelationTranslator $subject;
    private SoftReferenceParserFactory&MockObject $softReferenceParserFactory;
    private LinkService&MockObject $linkService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->softReferenceParserFactory = $this->createMock(SoftReferenceParserFactory::class);
        $this->linkService = $this->createMock(LinkService::class);

        $this->subject = new SoftReferenceRelationTranslator(
            $this->softReferenceParserFactory,
            $this->linkService,
        );
    }

    #[Test]
    public function supportsReturnsTrueWhenSoftrefExists(): void
    {
        $fieldConfig = [
            'softref' => 'typolink',
        ];

        self::assertTrue($this->subject->supports($fieldConfig));
    }

    #[Test]
    public function supportsReturnsFalseWhenSoftrefDoesNotExist(): void
    {
        self::assertFalse($this->subject->supports([]));
    }

    #[Test]
    public function translateReturnsOriginalValueWhenNoSoftRefExist(): void
    {
        $relationTranslation = RelationTranslation::create(
            original: Relation::fromArray([]),
            translated: null,
        );

        $value = 'original value';

        $result = $this->subject->translate(
            [$relationTranslation],
            $value,
            ['softref' => 'typolink'],
        );

        self::assertSame($value, $result);
    }

    #[Test]
    public function translateReturnsOriginalValueWhenFieldConfigHasNoSoftref(): void
    {
        $value = 'original value';

        $result = $this->subject->translate([], $value, []);

        self::assertSame($value, $result);
    }

    #[Test]
    public function translateReturnsOriginalValueWhenParserDidNotMatch(): void
    {
        $relationTranslation = RelationTranslation::create(
            original: Relation::fromArray([
                'softref_key' => 'typolink',
                'softref_id' => 'identifier',
                'tablename' => 'foo',
                'field' => 'bar',
                'recuid' => 10,
            ]),
            translated: null,
        );

        $parserResult = new SoftReferenceParserResult();

        $parserMock = $this->createMock(SoftReferenceParserInterface::class);
        $parserMock->method('parse')->willReturn($parserResult);

        $this->softReferenceParserFactory
            ->expects(self::once())
            ->method('getParsersBySoftRefParserList')
            ->with('typolink')
            ->willReturn([$parserMock]);

        $value = 'original value';

        $result = $this->subject->translate(
            [$relationTranslation],
            $value,
            ['softref' => 'typolink'],
        );

        self::assertSame($value, $result);
    }

    #[Test]
    public function translateReplacesDatabaseSoftReferenceWithTranslatedUid(): void
    {
        $relationTranslation = RelationTranslation::create(
            original: Relation::fromArray([
                'softref_key' => 'typolink',
                'softref_id' => 'identifier',
                'tablename' => 'foo',
                'field' => 'bar',
                'recuid' => 10,
            ]),
            translated: Relation::fromArray([
                'ref_uid' => 999,
                'tableName' => 'tt_content',
            ]),
        );

        $parserResult = SoftReferenceParserResult::create(
            content: 'before {softref:token123} after',
            elements: [
                'identifier' => [
                    'subst' => [
                        'tokenID' => 'token123',
                        'type' => 'db',
                        'tokenValue' => 'tt_content:123',
                    ],
                    'matchString' => 'tt_content:123',
                ],
            ],
        );

        $parserMock = $this->createMock(SoftReferenceParserInterface::class);
        $parserMock->method('getParserKey')->willReturn('typolink');
        $parserMock->method('parse')->willReturn($parserResult);

        $this->softReferenceParserFactory
            ->expects(self::once())
            ->method('getParsersBySoftRefParserList')
            ->with('typolink')
            ->willReturn([$parserMock]);

        $value = 'before tt_content:123 after';

        $result = $this->subject->translate(
            [$relationTranslation],
            $value,
            ['softref' => 'typolink'],
        );

        self::assertSame('before tt_content:999 after', $result);
    }

    #[Test]
    public function translateRemovesSoftReferenceWhenTranslatedRelationUidIsNull(): void
    {
        $relationTranslation = RelationTranslation::create(
            original: Relation::fromArray([
                'softref_key' => 'typolink',
                'softref_id' => 'identifier',
                'tablename' => 'foo',
                'field' => 'bar',
                'recuid' => 10,
            ]),
            translated: Relation::fromArray([
                'ref_uid' => null,
            ]),
        );

        $parserResult = SoftReferenceParserResult::create(
            content: 'before {softref:token123} after',
            elements: [
                'identifier' => [
                    'subst' => [
                        'tokenID' => 'token123',
                        'type' => 'db',
                        'tokenValue' => 'tt_content:123',
                    ],
                    'matchString' => 'tt_content:123',
                ],
            ],
        );

        $parserMock = $this->createMock(SoftReferenceParserInterface::class);
        $parserMock->method('getParserKey')->willReturn('typolink');
        $parserMock->method('parse')->willReturn($parserResult);

        $this->softReferenceParserFactory
            ->expects(self::once())
            ->method('getParsersBySoftRefParserList')
            ->with('typolink')
            ->willReturn([$parserMock]);

        $value = 'before tt_content:123 after';

        $result = $this->subject->translate(
            [$relationTranslation],
            $value,
            ['softref' => 'typolink'],
        );

        self::assertSame('before  after', $result);
    }

    #[Test]
    public function translateHandlesTypo3Links(): void
    {

        $relationTranslation = RelationTranslation::create(
            original: Relation::fromArray([
                'softref_key' => 'typolink',
                'softref_id' => 'identifier',
                'tablename' => 'pages',
                'field' => 'bar',
                'recuid' => 10,
            ]),
            translated: Relation::fromArray([
                'ref_uid' => 456,
                'tablename' => 'pages',
            ]),
        );

        $parserResult = SoftReferenceParserResult::create(
            content: '<a href="{softref:token123}">Link</a>',
            elements: [
                'identifier' => [
                    'subst' => [
                        'tokenID' => 'token123',
                        'type' => 'db',
                        'tokenValue' => 't3://page?uid=123',
                    ],
                    'matchString' => '<a href="t3://page?uid=123#999">Link</a>',
                ],
            ],
        );

        $parserMock = $this->createMock(SoftReferenceParserInterface::class);
        $parserMock->method('getParserKey')->willReturn('typolink');
        $parserMock->method('parse')->willReturn($parserResult);

        $this->softReferenceParserFactory
            ->method('getParsersBySoftRefParserList')
            ->willReturn([$parserMock]);

        $this->linkService
            ->expects(self::once())
            ->method('resolve')
            ->with('t3://page?uid=123#999')
            ->willReturn([
                'type' => 'page',
                'pageuid' => 123,
                'fragment' => '999',
            ]);

        $this->linkService
            ->expects(self::once())
            ->method('asString')
            ->with([
                'type' => 'page',
                'pageuid' => 456,
            ])
            ->willReturn('t3://page?uid=456');

        $value = '<a href="t3://page?uid=123#999">Link</a>';

        $result = $this->subject->translate(
            [$relationTranslation],
            $value,
            ['softref' => 'typolink'],
        );

        self::assertSame('<a href="t3://page?uid=456">Link</a>', $result);
    }

}
