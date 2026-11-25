<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Unit\Subject;

use Nowo\TimeTrackBundle\Subject\ActivityType;
use Nowo\TimeTrackBundle\Subject\ActivityTypeCatalog;
use Nowo\TimeTrackBundle\Subject\ActivityTypeList;
use Nowo\TimeTrackBundle\Subject\ActivityTypeProviderInterface;
use Nowo\TimeTrackBundle\Subject\AllocationLabeler;
use Nowo\TimeTrackBundle\Subject\AllocationLabels;
use Nowo\TimeTrackBundle\Subject\ScopedSubjectLabels;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabel;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabelResolver;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabels;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRegistry;
use Nowo\TimeTrackBundle\Tests\Support\SpyTimeSubjectProvider;
use Nowo\TimeTrackBundle\TimeTrackBundle;
use Nowo\TimeTrackBundle\ValueObject\TimeTrackUserRef;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Since 2.1.0 (Beacon spec 256): row labels hide unreadable subjects (no id) and fall back on the activity key.
 */
final class AllocationLabelerTest extends TestCase
{
    public function testIsEnabledWithSubjectsOrActivityTypes(): void
    {
        self::assertFalse((new AllocationLabeler(new TimeSubjectLabelResolver(TimeSubjectRegistry::none()), new ActivityTypeCatalog()))->isEnabled());
        self::assertTrue((new AllocationLabeler(new TimeSubjectLabelResolver(TimeSubjectRegistry::none()), $this->catalog()))->isEnabled());
        self::assertTrue($this->labeler()->isEnabled());
    }

    public function testLabelsWithoutTranslator(): void
    {
        $labeler = new AllocationLabeler(new TimeSubjectLabelResolver(TimeSubjectRegistry::none()), new ActivityTypeCatalog());

        self::assertSame(AllocationLabeler::HIDDEN_SUBJECT_LABEL, $labeler->hiddenLabel());
        self::assertSame('Work order', $labeler->typeLabel('work_order'));
    }

    public function testLabelsWithTranslator(): void
    {
        $labeler = $this->labeler();

        self::assertSame('No visible (es)', $labeler->hiddenLabel('es'));
        self::assertSame('Proyecto', $labeler->typeLabel('project'));
        self::assertSame('Work order', $labeler->typeLabel('work_order'), 'an untranslated key falls back');
        self::assertSame('Empty', $labeler->typeLabel('empty'), 'an empty translation falls back');
    }

    public function testLabelMasksUnreadableSubjectsAndLoadsActivityTypesPerScope(): void
    {
        $labels = $this->labeler()->label(TimeTrackUserRef::ofId('App\\User', '7'), [
            ['org-a', new TimeSubjectRef('project', 'p-1'), 'dev'],
            ['org-a', new TimeSubjectRef('project', 'p-2'), null],
            [null, null, 'dev'],
        ], 'es');

        self::assertSame('No visible (es)', $labels->hiddenLabel());
        self::assertSame(['type' => 'project', 'id' => 'p-1', 'label' => 'Label p-1', 'url' => null], $labels->subject('org-a', new TimeSubjectRef('project', 'p-1')));
        self::assertSame(['type' => 'project', 'hidden' => true, 'label' => 'No visible (es)'], $labels->subject('org-a', new TimeSubjectRef('project', 'p-2')));
        self::assertSame(['key' => 'dev', 'label' => 'Dev org-a'], $labels->activityType('org-a', 'dev'));
        self::assertSame(['key' => 'dev', 'label' => 'Dev'], $labels->activityType(null, 'dev'));
    }

    public function testLabelWithoutCatalogFallsBackToTheKey(): void
    {
        $labels = (new AllocationLabeler(new TimeSubjectLabelResolver(TimeSubjectRegistry::none()), new ActivityTypeCatalog()))->label(TimeTrackUserRef::ofId('App\\User', '7'), [['org-a', null, 'dev']]);

        self::assertSame(['key' => 'dev', 'label' => 'dev'], $labels->activityType('org-a', 'dev'));
    }

    public function testAllocationLabelsShapes(): void
    {
        $p1     = new TimeSubjectRef('project', 'p-1');
        $labels = new AllocationLabels(
            new ScopedSubjectLabels(['org-a' => TimeSubjectLabels::of([new TimeSubjectLabel($p1, 'One', 'https://crm.example.test/p-1')])]),
            ['org-a' => ActivityTypeList::of([new ActivityType('dev', 'Development')])],
            'Hidden',
        );

        self::assertNull($labels->subject('org-a', null));
        self::assertSame('One', $labels->subjectLabel('org-a', $p1)?->label);
        self::assertNull($labels->subjectLabel('org-b', $p1));
        self::assertSame(['type' => 'project', 'id' => 'p-1', 'label' => 'One', 'url' => null], $labels->subject('org-a', $p1));
        self::assertSame(['type' => 'project', 'id' => 'p-1', 'label' => 'One', 'url' => 'filtered:https://crm.example.test/p-1'], $labels->subject('org-a', $p1, static fn (?string $url): string => 'filtered:' . $url));
        $masked = $labels->subject('org-b', $p1);
        self::assertSame(['type' => 'project', 'hidden' => true, 'label' => 'Hidden'], $masked);
        self::assertArrayNotHasKey('id', $masked);
        self::assertNull($labels->activityType('org-a', null));
        self::assertSame('Development', $labels->activityTypeLabel('org-a', 'dev'));
        self::assertSame('qa', $labels->activityTypeLabel('org-a', 'qa'));
        self::assertSame('dev', $labels->activityTypeLabel('org-b', 'dev'));

        $none = AllocationLabels::none();
        self::assertSame(AllocationLabeler::HIDDEN_SUBJECT_LABEL, $none->hiddenLabel());
        self::assertSame(['type' => 'project', 'hidden' => true, 'label' => AllocationLabeler::HIDDEN_SUBJECT_LABEL], $none->subject('org-a', $p1));
    }

    /**
     * A labeler with a project provider (p-1 readable), a catalog and a translator.
     */
    private function labeler(): AllocationLabeler
    {
        $projects = new SpyTimeSubjectProvider('project', ['p-1']);

        return new AllocationLabeler(
            new TimeSubjectLabelResolver(new TimeSubjectRegistry(new ServiceLocator(['project' => static fn (): SpyTimeSubjectProvider => $projects]), ['project' => true])),
            $this->catalog(),
            new class implements TranslatorInterface {
                /**
                 * Fixed translations; anything else answers its key.
                 *
                 * @param array<string, mixed> $parameters
                 */
                public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
                {
                    if ($domain !== TimeTrackBundle::TRANSLATION_DOMAIN) {
                        return 'wrong domain';
                    }

                    return match ($id) {
                        'subjects.not_visible'  => 'No visible (' . $locale . ')',
                        'subjects.type.project' => 'Proyecto',
                        'subjects.type.empty'   => '',
                        default                 => $id,
                    };
                }

                /**
                 * Always English.
                 */
                public function getLocale(): string
                {
                    return 'en';
                }
            },
        );
    }

    /**
     * A catalog naming "dev" after the scope.
     */
    private function catalog(): ActivityTypeCatalog
    {
        return new ActivityTypeCatalog(new class implements ActivityTypeProviderInterface {
            /**
             * "Dev <scope>".
             */
            public function activityTypesFor(string $scopeKey): ActivityTypeList
            {
                return ActivityTypeList::of([new ActivityType('dev', 'Dev ' . $scopeKey)]);
            }
        });
    }
}
