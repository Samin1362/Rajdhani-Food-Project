<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

/**
 * `settings` (doc §8.2) — the key/value store for what is neither content nor
 * secret.
 *
 * Values are always strings; the schema has no type column, so interpretation
 * belongs to the caller. `bool()` exists because "is this feature on" is the
 * common case and every caller writing its own `=== '1'` check is how a setting
 * ends up meaning one thing in two places.
 */
final class SettingsRepository extends Repository
{
    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->scalar(
            'SELECT value FROM settings WHERE `key` = :key LIMIT 1',
            [':key' => $key],
        );

        return is_scalar($value) ? (string) $value : $default;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);

        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }
}
