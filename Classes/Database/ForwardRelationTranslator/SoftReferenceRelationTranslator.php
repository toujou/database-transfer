<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\Database\ForwardRelationTranslator;

use Toujou\DatabaseTransfer\DTO\Relation;
use Toujou\DatabaseTransfer\DTO\RelationTranslation;
use TYPO3\CMS\Core\DataHandling\SoftReference\SoftReferenceParserFactory;
use TYPO3\CMS\Core\LinkHandling\LinkService;

readonly class SoftReferenceRelationTranslator implements RelationTranslationStrategy
{
    public function __construct(
        private SoftReferenceParserFactory $softReferenceParserFactory,
        private LinkService $linkService,
    ) {}

    public function supports(array $fieldConfig): bool
    {
        return isset($fieldConfig['softref']);
    }

    public function translate(array $relationTranslations, mixed $value, array $fieldConfig): mixed
    {
        $hasSoftRefs = false;
        if (isset($fieldConfig['softref'])) {
            foreach ($relationTranslations as $relationTranslation) {
                if ($relationTranslation->original->getSoftrefKey() !== null) {
                    $hasSoftRefs = true;
                    break;
                }
            }
        }

        if ($hasSoftRefs) {
            return $this->translateRelationsFromSoftReferences($relationTranslations, $value, $fieldConfig['softref']);
        }

        return $value;
    }

    /**
     * @param RelationTranslation[] $relationTranslations
     */
    private function translateRelationsFromSoftReferences(array $relationTranslations, mixed $value, string $softref): mixed
    {
        /** @var Relation $representativeRelation */
        $representativeRelation = \reset($relationTranslations)->original;
        $parsedValue = $value;
        $matchedElements = [];
        foreach ($this->softReferenceParserFactory->getParsersBySoftRefParserList($softref) as $softReferenceParser) {
            $parserResult = $softReferenceParser->parse(
                $representativeRelation->getTableName(),
                $representativeRelation->getField(),
                $representativeRelation->getRecordUid(),
                $parsedValue,
                $representativeRelation->getFlexPointer(),
            );
            if ($parserResult->hasMatched()) {
                $matchedElements[$softReferenceParser->getParserKey()] = $parserResult->getMatchedElements();
                if ($parserResult->hasContent()) {
                    $parsedValue = $parserResult->getContent();
                }
            }
        }
        if (empty($matchedElements) || (string)$value === (string)$parsedValue || !str_contains($parsedValue, '{softref:')) {
            return $value;
        }

        // This is ugly and complicated. As there is no way to stringify the found softreferences back to their original string,
        // we need to split the parsed values by the softreference tokens and then split the original value by text around the tokens.
        // Only this way we can build a list of the softreference tokens with its original string.
        $splitParsedValue = \preg_split('/({softref:[a-z0-9]+})/', $parsedValue, -1, \PREG_SPLIT_DELIM_CAPTURE);
        $unparsedValue = $value;
        $softrefValues = [];
        foreach ($splitParsedValue as $index => $split) {
            if (!($index % 2)) {
                $start = \strpos($unparsedValue, $split);
                if ($start > 0) {
                    $softrefValues[$splitParsedValue[$index - 1]] = \substr($unparsedValue, 0, $start);
                }
                $unparsedValue = \substr($unparsedValue, $start + \strlen($split));
            }
        }

        // As the reference index stores the key of the softreference parser and the id, we need to map this to the
        // softreference tokenId.
        foreach ($relationTranslations as $relationTranslation) {
            $originalRelation = $relationTranslation->original;
            $translatedRelation = $relationTranslation->translated;

            $softrefKey = $originalRelation->getSoftrefKey();
            $softrefId = $originalRelation->getSoftrefId();
            $softrefElement = $matchedElements[$softrefKey][$softrefId] ?? null;
            $tokenId = $softrefElement['subst']['tokenID'] ?? null;

            if ($tokenId === null) {
                continue;
            }

            if (($translatedRelation?->getRefUid()) === null) {
                $softrefValues['{softref:' . $tokenId . '}'] = '';

                continue;
            }

            switch ($softrefElement['subst']['type'] ?? null) {
                case 'file':
                    // @todo add handling for files

                case 'db':
                    $insertValue = $translatedRelation->getRefUid();
                    $tokenValue = (string)$softrefElement['subst']['tokenValue'];
                    if (str_contains($tokenValue, ':')) {
                        [$tokenKey] = explode(':', $tokenValue);
                        $insertValue = $tokenKey . ':' . $insertValue;
                    }
                    $matchString = $softrefElement['matchString'] ?? '';

                    // Handling for typo3_links
                    if (str_contains($matchString, 't3://')) {
                        $link = $matchString;
                        if (preg_match('/href="([^"]+)"/', $link, $linkMatches)) {
                            $link = $linkMatches[1];
                        }

                        $parts = $this->linkService->resolve($link);

                        if ($translatedRelation->getTableName() === 'pages') {
                            $parts['pageuid'] = $translatedRelation->getRefUid();
                        }
                        // content element anchors will be replaced via own softref
                        if ((int)($parts['fragment'] ?? 0) > 0) {
                            unset($parts['fragment']);
                        }

                        $insertValue = $this->linkService->asString($parts);
                    }
                    $softrefValues['{softref:' . $tokenId . '}'] = $insertValue;
            }

        }

        // TODO relation softref_id has to be recalculated as the hash includes the uid of the record
        return \str_replace(\array_keys($softrefValues), $softrefValues, $parsedValue);
    }
}
