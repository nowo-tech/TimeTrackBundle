<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

/**
 * Since 2.1.0 (Beacon spec 256): the labels {@see AllocationLabeler} resolved for one page, and the wire shapes of the
 * bundle's read surfaces:
 *
 * - subject: `{type, id, label, url}` when the viewer may read it, else `{type, hidden: true, label: "Subject not
 *   visible"}` without its id (Q-TS-6); null without a subject;
 * - activity type: `{key, label}` (a key the tenant no longer lists is labelled with the key itself); null without one.
 *
 * Labels are plain text: every surface escapes them. Built per call.
 *
 * @internal
 */
final readonly class AllocationLabels
{
    /**
     * @param array<string, ActivityTypeList> $activityTypes Tenant scope ('' = none) => its activity types
     */
    public function __construct(
        private ScopedSubjectLabels $subjects,
        private array $activityTypes,
        private string $hiddenLabel,
    ) {
    }

    /**
     * No label at all (every subject masked, activity types labelled by key).
     */
    public static function none(string $hiddenLabel = AllocationLabeler::HIDDEN_SUBJECT_LABEL): self
    {
        return new self(new ScopedSubjectLabels(), [], $hiddenLabel);
    }

    /**
     * The readable label of a subject of an entry in this scope, or null (masked).
     */
    public function subjectLabel(?string $scopeKey, TimeSubjectRef $subject): ?TimeSubjectLabel
    {
        return $this->subjects->get($scopeKey, $subject);
    }

    /**
     * The label of the masked subject.
     */
    public function hiddenLabel(): string
    {
        return $this->hiddenLabel;
    }

    /**
     * Wire shape of a subject (see the class comment).
     *
     * @param (callable(string|null): ?string)|null $url Filters the provider's URL for the surface (null = no URL)
     *
     * @return array{type: string, hidden: true, label: string}|array{type: string, id: string, label: string, url: string|null}|null
     */
    public function subject(?string $scopeKey, ?TimeSubjectRef $subject, ?callable $url = null): ?array
    {
        if (!$subject instanceof TimeSubjectRef) {
            return null;
        }
        $label = $this->subjects->get($scopeKey, $subject);
        if (!$label instanceof TimeSubjectLabel) {
            return ['type' => $subject->type, 'hidden' => true, 'label' => $this->hiddenLabel];
        }

        return ['type' => $subject->type, 'id' => $subject->id, 'label' => $label->label, 'url' => $url === null ? null : $url($label->url)];
    }

    /**
     * Wire shape of an activity type (see the class comment).
     *
     * @return array{key: string, label: string}|null
     */
    public function activityType(?string $scopeKey, ?string $key): ?array
    {
        if ($key === null) {
            return null;
        }

        return ['key' => $key, 'label' => $this->activityTypeLabel($scopeKey, $key)];
    }

    /**
     * The label of an activity type key in this scope (the key itself when the tenant does not list it).
     */
    public function activityTypeLabel(?string $scopeKey, string $key): string
    {
        return ($this->activityTypes[$scopeKey ?? ''] ?? null)?->find($key)->label ?? $key;
    }
}
