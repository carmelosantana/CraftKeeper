<?php

namespace App\Console;

/**
 * The five commands App\Console\CommandPolicy::classify() recognizes as
 * Safe (Task 10's SAFE_EXACT list plus the dynamic "say <message>"
 * pattern), reproduced here as a small, fixed catalog so
 * App\Http\Controllers\ServerController and App\Http\Controllers\
 * ConsoleController can render identical predefined-action buttons on
 * both pages without duplicating the list. CommandPolicy itself remains
 * the single source of truth for what actually classifies Safe —
 * tests/Feature/Http/ConsoleControllerTest.php asserts every entry below
 * still does.
 */
final class PredefinedSafeActions
{
    /**
     * @var list<array{key: string, command: string, label: string, needsMessage: bool}>
     */
    public const ALL = [
        ['key' => 'list', 'command' => 'list', 'label' => 'List online players', 'needsMessage' => false],
        ['key' => 'save-all-flush', 'command' => 'save-all flush', 'label' => 'Save all worlds', 'needsMessage' => false],
        ['key' => 'say', 'command' => 'say', 'label' => 'Broadcast a message', 'needsMessage' => true],
        ['key' => 'time-query-daytime', 'command' => 'time query daytime', 'label' => 'Query the time', 'needsMessage' => false],
        ['key' => 'weather-query', 'command' => 'weather query', 'label' => 'Query the weather', 'needsMessage' => false],
    ];

    /**
     * @return array{key: string, command: string, label: string, needsMessage: bool}|null
     */
    public static function find(string $key): ?array
    {
        foreach (self::ALL as $action) {
            if ($action['key'] === $key) {
                return $action;
            }
        }

        return null;
    }
}
