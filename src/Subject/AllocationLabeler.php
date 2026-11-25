<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Subject;

use Nowo\TimeTrackBundle\TimeTrackBundle;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use Symfony\Contracts\Translation\TranslatorInterface;

use function str_replace;
use function ucfirst;

/**
 * Since 2.1.0 (Beacon spec 256, design §8.4): the labels of the subjects and activity types of one page of the bundle's
 * own read surfaces (client API `GET entries` / `GET timer` / `GET export`, the CSV, the web UI, the GDPR export), with
 * the **viewer** as actor: one `describe()` per (tenant, type) present and one activity type list per tenant present.
 * A subject the viewer may not read is masked without its id ({@see AllocationLabels}). Stateless.
 *
 * @internal
 */
final readonly class AllocationLabeler
{
    /** Label of a masked subject when no translator is available. */
    public const HIDDEN_SUBJECT_LABEL = 'Subject not visible';

    /**
     * Receives the label resolver, the activity types and (optional) the translator of the masked label.
     */
    public function __construct(
        private TimeSubjectLabelResolver $labels,
        private ActivityTypeCatalog $activityTypes,
        private ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * Whether a subject or activity type provider is configured (the bundle's surfaces show allocations only then).
     */
    public function isEnabled(): bool
    {
        return $this->labels->isEnabled() || $this->activityTypes->isEnabled();
    }

    /**
     * The labels of a page.
     *
     * @param iterable<array{0: string|null, 1: TimeSubjectRef|null, 2: string|null}> $items (entry scope, subject, activity type key) per row
     * @param string|null $locale Locale of the masked label (null = the translator's)
     */
    public function label(TimeTrackUserRef $viewer, iterable $items, ?string $locale = null): AllocationLabels
    {
        $subjects = [];
        $scopes   = [];
        foreach ($items as [$scopeKey, $subject, $activityTypeKey]) {
            $subjects[] = [$scopeKey, $subject];
            if ($activityTypeKey !== null) {
                $scopes[$scopeKey ?? ''] = true;
            }
        }
        $types = [];
        if ($this->activityTypes->isEnabled()) {
            foreach ($scopes as $scopeKey => $_) {
                $types[(string) $scopeKey] = $this->activityTypes->listFor((string) $scopeKey);
            }
        }

        return new AllocationLabels($this->labels->forViewer($viewer, $subjects), $types, $this->hiddenLabel($locale));
    }

    /**
     * The masked subject label, translated (`subjects.not_visible`) when a translator is available.
     */
    public function hiddenLabel(?string $locale = null): string
    {
        return $this->translator instanceof TranslatorInterface ? $this->translator->trans('subjects.not_visible', [], TimeTrackBundle::TRANSLATION_DOMAIN, $locale) : self::HIDDEN_SUBJECT_LABEL;
    }

    /**
     * The label of a subject type: the translation of `subjects.type.<type>` (add it to your own
     * `NowoTimeTrackBundle` translations), else the type itself with underscores as spaces and a capital first letter.
     */
    public function typeLabel(string $type, ?string $locale = null): string
    {
        $key        = 'subjects.type.' . $type;
        $translated = $this->translator instanceof TranslatorInterface ? $this->translator->trans($key, [], TimeTrackBundle::TRANSLATION_DOMAIN, $locale) : $key;

        return $translated !== $key && $translated !== '' ? $translated : ucfirst(str_replace('_', ' ', $type));
    }
}
