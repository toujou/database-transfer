<?php

declare(strict_types=1);

namespace Toujou\DatabaseTransfer\DTO;

final readonly class RecordChangeSet
{
    /**
     * @param RecordAction[] $recordActions
     */
    private function __construct(
        private array $recordActions,
    ) {}

    /**
     * @return RecordAction[]
     */
    public function getRecordsToCreate(): array
    {
        return array_filter($this->recordActions, static fn(RecordAction $recordAction) => $recordAction->isCreateAction());
    }

    /**
     * @return RecordAction[]
     */
    public function getRecordsToUpdate(): array
    {
        return array_filter($this->recordActions, static fn(RecordAction $recordAction) => $recordAction->isUpdateAction());
    }

    /**
     * @return RecordAction[]
     */
    public function getRecordsToPersist(): array
    {
        return array_filter($this->recordActions, static fn(RecordAction $recordAction) => $recordAction->isUpdateAction() || $recordAction->isCreateAction());
    }

    /**
     * @return RecordAction[]
     */
    public function getRecordsToDelete(): array
    {
        return array_filter($this->recordActions, static fn(RecordAction $recordAction) => $recordAction->isDeleteAction());
    }

    /**
     * @param RecordAction[] $recordActions
     */
    public static function create(
        array $recordActions,
    ): self {
        return new self($recordActions);
    }
}
