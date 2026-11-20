<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Fixtures\ProbeEntity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\Tests\Fixtures\FunctionalEntity\AppUser;

/**
 * 1.33.0: a host entity that points at a TimeTrack row (like a host's audit row of an entry), for the host-flush probe
 * tests.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_entry_note')]
class EntryNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TimeEntry::class)]
        #[ORM\JoinColumn(name: 'entry_id', nullable: true, onDelete: 'SET NULL')]
        public ?TimeEntry $entry,
        #[ORM\Column(length: 64)]
        public string $text = 'note',
        #[ORM\ManyToOne(targetEntity: AppUser::class)]
        #[ORM\JoinColumn(name: 'author_id', nullable: true, onDelete: 'SET NULL')]
        public ?AppUser $author = null,
    ) {
    }
}
