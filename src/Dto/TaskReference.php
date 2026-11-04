<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Dto;

use Nowo\TimeTrackBundle\Client\ClientTaskPresenter;

/**
 * Lightweight task reference returned by TaskProviderInterface.
 *
 * Since 1.10 the provider may also fill the task's project and the absolute URL of the task page in the host; the
 * client API publishes them on `tasks` and `timer` after its own checks ({@see ClientTaskPresenter}).
 */
final readonly class TaskReference
{
    /**
     * Creates a task reference.
     *
     * @param string|null $projectId Project of the task (since 1.10)
     * @param string|null $projectTitle Project name of the task (since 1.10)
     * @param string|null $url Absolute https URL of the task page in the host (since 1.10)
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $boardId = null,
        public ?string $boardTitle = null,
        public ?string $projectId = null,
        public ?string $projectTitle = null,
        public ?string $url = null,
    ) {
    }

    /**
     * Raw provider values (the client API applies its output rules on top).
     *
     * @return array{id: string, title: string, boardId: string|null, boardTitle: string|null, projectId: string|null, projectTitle: string|null, url: string|null}
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'title'        => $this->title,
            'boardId'      => $this->boardId,
            'boardTitle'   => $this->boardTitle,
            'projectId'    => $this->projectId,
            'projectTitle' => $this->projectTitle,
            'url'          => $this->url,
        ];
    }
}
