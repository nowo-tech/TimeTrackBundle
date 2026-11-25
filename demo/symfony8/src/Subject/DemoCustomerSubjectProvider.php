<?php

declare(strict_types=1);

namespace App\Subject;

use Nowo\TimeTrackBundle\Subject\TimeSubjectContext;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabel;
use Nowo\TimeTrackBundle\Subject\TimeSubjectLabels;
use Nowo\TimeTrackBundle\Subject\TimeSubjectProviderInterface;
use Nowo\TimeTrackBundle\Subject\TimeSubjectRef;
use Nowo\TimeTrackBundle\Subject\TimeSubjectSearch;

use function array_slice;
use function mb_stripos;

/**
 * TimeTrack 2.1 showcase (Beacon spec 256): a fixed list of demo customers as time subjects. Customer time may sit next
 * to a task (`allowsTask()`), every signed-in demo user may log and read it. A real host asks its own CRM here.
 */
final readonly class DemoCustomerSubjectProvider implements TimeSubjectProviderInterface
{
    /** Customer id => [name, city]. */
    public const CUSTOMERS = [
        'acme'    => ['ACME Corporation', 'Madrid'],
        'globex'  => ['Globex', 'Barcelona'],
        'initech' => ['Initech', 'Valencia'],
    ];

    /**
     * {@inheritDoc}
     */
    public static function subjectType(): string
    {
        return 'customer';
    }

    /**
     * {@inheritDoc}
     */
    public static function allowsTask(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function canLog(TimeSubjectContext $context, TimeSubjectRef $subject): bool
    {
        return $context->actor->hasId() && isset(self::CUSTOMERS[$subject->id]);
    }

    /**
     * {@inheritDoc}
     */
    public function describe(TimeSubjectContext $context, array $subjects): TimeSubjectLabels
    {
        $labels = [];
        foreach ($subjects as $subject) {
            if (isset(self::CUSTOMERS[$subject->id])) {
                $labels[] = new TimeSubjectLabel($subject, self::CUSTOMERS[$subject->id][0], null, self::CUSTOMERS[$subject->id][1]);
            }
        }

        return TimeSubjectLabels::of($labels);
    }

    /**
     * {@inheritDoc}
     */
    public function search(TimeSubjectContext $context, TimeSubjectSearch $search): array
    {
        $found = [];
        foreach (self::CUSTOMERS as $id => [$name, $city]) {
            if ($search->query === '' || mb_stripos($name, $search->query) !== false) {
                $found[] = new TimeSubjectLabel(new TimeSubjectRef('customer', $id), $name, null, $city);
            }
        }

        return array_slice($found, 0, $search->limit);
    }
}
