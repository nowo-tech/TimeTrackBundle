<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Dto;

/**
 * Lightweight task reference returned by TaskProviderInterface.
 */
final readonly class TaskReference
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $boardId = null,
        public ?string $boardTitle = null,
    ) {
    }

    /**
     * @return array{id: string, title: string, boardId: string|null, boardTitle: string|null}
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'title'      => $this->title,
            'boardId'    => $this->boardId,
            'boardTitle' => $this->boardTitle,
        ];
    }
}
