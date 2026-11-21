<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\PersonalData;

/**
 * Since 1.35.0: what {@see TimeTrackPersonalDataInterface::erase()} does to the user's time entries. Neither deletes an
 * entry or changes its time (owner rule: tracked time is never deleted), and neither is blocked by a locked or submitted
 * period: the timesheet hash covers ids, tasks, times and sources only, and anonymising outranks the lock.
 */
enum TimeEntryErasure: string
{
    /**
     * The bundle's erasure rule (`FormerPersonTimeEntries::anonymiseUser()`): the time stays, the person goes. The
     * entries lose their owner, name, reference, description, note and task title; the external id is hashed. Use it when
     * the account goes or the host keeps no link to the person.
     */
    case Anonymise = 'anonymise';

    /**
     * Only the person's free text goes: the description and `metadata.note` of each entry the user still owns. The owner
     * stays, so the hours keep counting in the user's worked time, balance and timesheets. For a host that keeps an
     * anonymised account row (its own erasure blanks the account). Entries left under the user's former reference
     * (`user:<id>`) are anonymised in both modes.
     */
    case FreeText = 'free_text';
}
