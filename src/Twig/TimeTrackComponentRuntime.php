<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Twig;

use InvalidArgumentException;
use Nowo\TimeTrackBundle\Entity\Timesheet;
use Nowo\TimeTrackBundle\Ui\TimesheetViewBuilder;
use Nowo\TimeTrackBundle\WorkingTime\BalanceReport;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;

use function abs;
use function array_values;
use function intdiv;
use function is_array;
use function is_string;
use function sprintf;
use function str_starts_with;
use function substr;

/**
 * Since 1.13: renders the configurable components (contract §16a) — `nowo_time_track_component(name, props)`:
 *
 * - `TimesheetWeekGrid` {timesheet, viewer, options}
 * - `BalanceCard` {report, options}
 * - `LedgerTable` {lines, viewer, owner_id, scope_key, options}
 *
 * The `TimeTrack:` prefix is accepted (`TimeTrack:BalanceCard`). Dialogs use the UiKit 2.29 anatomy.
 */
final readonly class TimeTrackComponentRuntime implements RuntimeExtensionInterface
{
    public const TEMPLATES = [
        'TimesheetWeekGrid' => '@NowoTimeTrackBundle/components/timesheet_week_grid.html.twig',
        'BalanceCard'       => '@NowoTimeTrackBundle/components/balance_card.html.twig',
        'LedgerTable'       => '@NowoTimeTrackBundle/components/ledger_table.html.twig',
    ];

    /**
     * Receives the view builder.
     */
    public function __construct(private TimesheetViewBuilder $views)
    {
    }

    /**
     * Renders a component.
     *
     * @param array<string, mixed> $props
     *
     * @throws InvalidArgumentException When the component or a required prop is unknown
     */
    public function render(Environment $twig, string $name, array $props = []): string
    {
        $name    = str_starts_with($name, 'TimeTrack:') ? substr($name, 10) : $name;
        $options = is_array($props['options'] ?? null) ? $props['options'] : [];
        $view    = match ($name) {
            'TimesheetWeekGrid' => $this->views->weekGrid($this->prop($props, 'timesheet', Timesheet::class), $this->prop($props, 'viewer', UserInterface::class), $options),
            'BalanceCard'       => $this->views->balanceCard($this->prop($props, 'report', BalanceReport::class), $options),
            'LedgerTable'       => $this->views->ledgerTable(is_array($props['lines'] ?? null) ? array_values($props['lines']) : [], $this->prop($props, 'viewer', UserInterface::class), $this->string($props, 'owner_id'), $this->string($props, 'scope_key'), $options),
            default             => throw new InvalidArgumentException(sprintf('Unknown TimeTrack component "%s".', $name)),
        };

        return $twig->render(self::TEMPLATES[$name], ['view' => $view]);
    }

    /**
     * `+H:MM` / `-H:MM` of a number of seconds (minutes rounded toward zero).
     */
    public static function duration(int $seconds, bool $signed = false): string
    {
        $minutes = intdiv(abs($seconds), 60);
        $sign    = $seconds < 0 ? '-' : ($signed && $seconds > 0 ? '+' : '');

        return sprintf('%s%d:%02d', $sign, intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * @template T of object
     *
     * @param array<string, mixed> $props
     * @param class-string<T> $class
     *
     * @return T
     */
    private function prop(array $props, string $key, string $class): object
    {
        $value = $props[$key] ?? null;
        if (!$value instanceof $class) {
            throw new InvalidArgumentException(sprintf('The "%s" prop must be a %s.', $key, $class));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $props
     */
    private function string(array $props, string $key): string
    {
        $value = $props[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('The "%s" prop must be a string.', $key));
        }

        return $value;
    }
}
