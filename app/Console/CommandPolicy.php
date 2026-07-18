<?php

namespace App\Console;

use App\Operations\InputRedactor;

/**
 * Classifies raw console command text into a CommandRisk and derives the
 * safe-to-persist "category" and "redacted display" values Task 10's
 * ambiguity resolution #6 requires for secret-shaped commands.
 *
 * classify() is default-deny: SAFE is a small, fixed, predefined
 * allow-list ONLY (list, save-all flush, say <message>, time query
 * daytime, weather query). Everything else — including every command not
 * explicitly recognized — is Elevated. Input is normalized (trimmed,
 * internal whitespace collapsed to single spaces) before matching so that
 * leading/trailing whitespace around a genuinely safe command still
 * matches, but matching is otherwise deliberately exact and conservative:
 * "list; op me", "list foo", "LIST", or a command containing an embedded
 * NUL byte all fail the allow-list and fall through to Elevated, rather
 * than risking a prefix/fuzzy match a crafted string could exploit to be
 * misclassified as Safe.
 */
class CommandPolicy
{
    /**
     * @var list<string>
     */
    private const SAFE_EXACT = [
        'list',
        'save-all flush',
        'time query daytime',
        'weather query',
    ];

    /**
     * Command names CraftKeeper knows commonly take a secret-shaped
     * argument (e.g. AuthMe-style login/registration plugins), where the
     * argument itself is just an opaque string with no telltale keyword
     * SECRET_CONTENT_PATTERN could catch on its own.
     *
     * @var list<string>
     */
    private const SECRET_COMMAND_NAMES = [
        'login',
        'register',
        'changepassword',
        'setpassword',
        'passwd',
    ];

    /**
     * Content-based fallback: catches a literal "password=...", "token:
     * ...", etc. substring anywhere in the command text, independent of
     * which command it's attached to. Mirrors
     * App\Operations\InputRedactor::SENSITIVE_KEY_PATTERN's keyword list,
     * applied to free text instead of array keys.
     */
    private const SECRET_CONTENT_PATTERN = '/\b(password|secret|token|api[_-]?key|credential)\b\s*[:=]?\s*\S+/i';

    public function classify(string $command): CommandRisk
    {
        if (str_contains($command, "\0")) {
            return CommandRisk::Elevated;
        }

        $normalized = $this->normalize($command);

        if ($normalized === '') {
            return CommandRisk::Elevated;
        }

        if (in_array($normalized, self::SAFE_EXACT, true)) {
            return CommandRisk::Safe;
        }

        if (preg_match('/^say .+$/su', $normalized) === 1) {
            return CommandRisk::Safe;
        }

        return CommandRisk::Elevated;
    }

    /**
     * A stable, coarse label for a command — its first normalized token,
     * lowercased — used as the persisted, always-safe-to-store
     * replacement for the raw text of a secret-shaped command.
     */
    public function category(string $command): string
    {
        $token = $this->firstToken($command);

        return $token !== '' ? $token : 'unknown';
    }

    /**
     * Whether $command's raw text matches one of the configured
     * secret-shaped patterns: a known secret-taking command name, or a
     * literal password/token/secret/credential-looking substring. When
     * true, the command's real text must never be persisted as-is — see
     * App\Console\RconCommandService::proposeCommand() and
     * App\Models\RconCommandPayload.
     */
    public function looksLikeSecret(string $command): bool
    {
        if (preg_match(self::SECRET_CONTENT_PATTERN, $command) === 1) {
            return true;
        }

        return in_array($this->firstToken($command), self::SECRET_COMMAND_NAMES, true);
    }

    /**
     * The value safe to persist/display in place of a command's raw text:
     * "<category> ••••••" when looksLikeSecret() is true, or the plain
     * normalized command otherwise.
     */
    public function redactedDisplay(string $command): string
    {
        if (! $this->looksLikeSecret($command)) {
            return $this->normalize($command);
        }

        return trim($this->category($command).' '.InputRedactor::MASK);
    }

    /**
     * Trim and collapse any run of whitespace (spaces, tabs, newlines) to
     * a single space. This is both the risk-classification input AND the
     * canonical persisted/executed form for non-secret commands — see
     * RconCommandService.
     */
    public function normalize(string $command): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $command));
    }

    private function firstToken(string $command): string
    {
        $normalized = $this->normalize($command);

        if ($normalized === '') {
            return '';
        }

        $parts = explode(' ', $normalized, 2);

        return strtolower($parts[0]);
    }
}
