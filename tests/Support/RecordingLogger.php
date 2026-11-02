<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * PSR-3 logger that keeps the records in memory (REQ-OBS-001 assertions).
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(static fn (array $record): string => $record['message'], $this->records);
    }

    /**
     * Every scalar context value of every record, for "no personal data" assertions.
     *
     * @return list<string>
     */
    public function contextValues(): array
    {
        $values = [];
        foreach ($this->records as $record) {
            array_walk_recursive($record['context'], static function (mixed $value) use (&$values): void {
                $values[] = (string) $value;
            });
        }

        return $values;
    }
}
