<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Integration;

use Nowo\TimeTrackBundle\Entity\TimeEntry;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;

/**
 * Since 1.25.0 (Beacon spec 234 FR-023): host port that tells a service account (an agent with its own API token) from a
 * person. Framework-free (REQ-ARCH-001 rule 3): it takes the bundle's {@see TimeTrackUserRef} only.
 *
 * What changes for a service account:
 *
 * - its time entries may overlap: a manual entry is not refused with `overlap`, and a backdated timer start is not
 *   clamped to the end of its latest entry;
 * - its entries are marked ({@see TimeEntry::isServiceAccount()}).
 *
 * Nothing else changes: one running timer per account, the period lock, the task access checks and every rule for
 * people stay as they are. The default ({@see NoServiceAccountPolicy}) answers false for everyone. Hosts alias this
 * interface to their own service (or set `nowo_time_track.service_account_policy`).
 *
 * The bundle asks it on every write and never memoises the answer (FrankenPHP worker mode, kernel reset false). An
 * implementation must not keep per-request state either.
 *
 * **It must not throw, and it must tolerate an account that is being deleted** (audit PA-3): it is asked inside every
 * timer stop, including the stop that saves the running time of a user the host is deleting, where the account row may
 * already be gone. An implementation that reads a repository must answer false instead of failing for an unknown
 * account. The bundle does not rely on that: an exception is caught, logged (the class only) and read as "a person",
 * so the entry is still written — but then an agent's entry is recorded as a person's.
 */
interface ServiceAccountPolicyInterface
{
    /**
     * Whether the account is a service account (agent). False means a person.
     */
    public function isServiceAccount(TimeTrackUserRef $account): bool;
}
