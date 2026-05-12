<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\DTO;

final readonly class MmTableRecordChangeSet
{
    /**
     * @param MmTableRecordAction[] $mmTableRecordActions
     */
    private function __construct(
        private array $mmTableRecordActions,
    ) {}

    /**
     * @return MmTableRecordAction[]
     */
    public function getMmTableRecordActions(): array
    {
        return $this->mmTableRecordActions;
    }

    /**
     * @param mixed[] $importMmTableRecords
     * @param mixed[] $exportMmTableRecords
     */
    public static function create(array $importMmTableRecords, array $exportMmTableRecords): self
    {
        $mmTableRecordActions = [];

        foreach ($exportMmTableRecords as $combinedKey => $row) {
            [$table] = explode(':', $combinedKey);

            if (!isset($importMmTableRecords[$combinedKey])) {
                $mmTableRecordActions[] = MmTableRecordAction::createAction($row, $table);

                continue;
            }

            $oldRow = $importMmTableRecords[$combinedKey];
            if ($oldRow !== $row) {
                $mmTableRecordActions[] = MmTableRecordAction::updateAction($row, $table);
            }
            unset($importMmTableRecords[$combinedKey]);
        }

        foreach ($importMmTableRecords as $combinedKey => $row) {
            [$table] = explode(':', $combinedKey);
            $mmTableRecordActions[] = MmTableRecordAction::deleteAction($row, $table);
        }

        return new self($mmTableRecordActions);
    }
}
