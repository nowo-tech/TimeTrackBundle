<?php

declare(strict_types=1);

namespace Nowo\TimeTrackBundle\Client;

use InvalidArgumentException;

use function mb_strlen;
use function preg_match;
use function trim;

/**
 * Since 2.2.0 (Beacon Desktop, Q-BD-7): one tenant context a client may name in the context header (e.g. Beacon's
 * `personal` or `org:<uuid>`) with the label the client shows in its switcher. Value object, validated on construction.
 */
final readonly class ClientContextOption
{
    /** Context key: 1 to 128 visible ASCII characters (no space). */
    public const KEY_PATTERN = '/\A[\x21-\x7E]{1,128}\z/';

    /**
     * @param string $key Context key, sent back by the client in the context header
     * @param string $label Name shown to the user (1 to 200 characters, no control character)
     *
     * @throws InvalidArgumentException On a malformed key or label
     */
    public function __construct(
        public string $key,
        public string $label,
    ) {
        if (!self::isKey($key)) {
            throw new InvalidArgumentException('A client context key is 1 to 128 visible ASCII characters.');
        }
        if (trim($label) === '' || mb_strlen($label) > 200 || preg_match('/[\x00-\x1F\x7F]/u', $label) === 1) {
            throw new InvalidArgumentException('A client context label is 1 to 200 characters without control characters.');
        }
    }

    /**
     * Whether a string is a well-formed context key.
     */
    public static function isKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * Wire shape (`GET me.contexts`).
     *
     * @return array{key: string, label: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label];
    }
}
