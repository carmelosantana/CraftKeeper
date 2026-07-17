<?php

namespace App\Config\Formats;

use App\Config\ConfigChange;
use App\Config\ConfigChangeKind;
use App\Config\ConfigDiagnostic;
use App\Config\ConfigFormatAdapter;
use App\Config\DiagnosticSeverity;
use App\Config\Exceptions\ConfigParseException;
use App\Config\Formats\Support\DotPath;
use App\Config\Formats\Support\JsonSourceScanner;
use App\Config\ParsedConfig;
use App\Config\Schemas\ConfigSchema;
use App\Config\Schemas\SchemaValidator;
use App\Config\ValidationResult;
use App\Filesystem\MinecraftPath;
use JsonException;

/**
 * The JSON format used by ops.json, whitelist.json, and similar files.
 * Unlike Properties/YAML/TOML, JSON has no comment syntax at all, so —
 * per the Task 7 brief — this adapter always fully re-serializes on
 * write (decode → mutate → json_encode with 2-space indentation and a
 * trailing newline) rather than attempting a byte-precise patch;
 * willNormalize() always returns true, honestly reflecting that every
 * save reformats the whole file (there is nothing format-specific to
 * lose, since JSON never had comments to begin with).
 */
final class JsonAdapter implements ConfigFormatAdapter
{
    public function supports(MinecraftPath $path, string $contents): bool
    {
        return str_ends_with(strtolower($path->relativePath), '.json');
    }

    public function parse(string $contents): ParsedConfig
    {
        $data = $this->decode($contents);
        $nodes = (new JsonSourceScanner($contents))->scan();

        return new ParsedConfig($data, $nodes);
    }

    public function validate(string $contents, ?ConfigSchema $schema): ValidationResult
    {
        try {
            $data = $this->decode($contents);
        } catch (ConfigParseException $e) {
            return ValidationResult::invalid([
                new ConfigDiagnostic(DiagnosticSeverity::Error, $e->getMessage(), $e->parsedLine ?? 1, $e->parsedColumn ?? 1),
            ]);
        }

        $diagnostics = $schema !== null ? SchemaValidator::validate($data, $schema, flatKeys: false) : [];

        return ValidationResult::fromDiagnostics($diagnostics);
    }

    /**
     * @param  list<ConfigChange>  $changes
     */
    public function applyChanges(string $contents, array $changes, ?ConfigSchema $schema): string
    {
        $data = $this->decode($contents);

        foreach ($changes as $change) {
            $data = match ($change->kind) {
                ConfigChangeKind::Replace, ConfigChangeKind::Add => DotPath::set($data, $change->path, $change->value),
                ConfigChangeKind::Remove => DotPath::unset($data, $change->path),
            };
        }

        return $this->encode($data);
    }

    /**
     * Non-interface preview (see YamlAdapter's identical method for
     * why): always true for JSON — see class docblock.
     *
     * @param  list<ConfigChange>  $changes
     */
    public function willNormalize(string $contents, array $changes, ?ConfigSchema $schema): bool
    {
        return $changes !== [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $contents): array
    {
        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new ConfigParseException('The file is not valid UTF-8 text.', 1, 1);
        }

        if (trim($contents) === '') {
            return [];
        }

        try {
            $data = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw $this->locateError($contents, $e);
        }

        if (! is_array($data)) {
            throw new ConfigParseException('The document root must be a JSON object.', 1, 1);
        }

        return $data;
    }

    private function locateError(string $contents, JsonException $e): ConfigParseException
    {
        $patterns = [
            '/,(\s*[}\]])/' => 'Trailing comma is not allowed in JSON.',
            '/([{,]\s*)\'[^\']*\'\s*:/' => 'Object keys must be double-quoted; single quotes are not valid JSON.',
        ];

        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) === 1) {
                [$line, $column] = $this->lineColumnFromOffset($contents, $matches[0][1]);

                return new ConfigParseException($message, $line, $column, $e);
            }
        }

        return new ConfigParseException('Invalid JSON: '.$e->getMessage(), 1, 1, $e);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function lineColumnFromOffset(string $contents, int $offset): array
    {
        $before = substr($contents, 0, $offset);
        $line = substr_count($before, "\n") + 1;
        $lastNewline = strrpos($before, "\n");
        $column = $lastNewline === false ? $offset + 1 : $offset - $lastNewline;

        return [$line, $column];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        // json_encode's JSON_PRETTY_PRINT always uses 4-space indentation
        // with no way to configure it; halving every run of leading
        // whitespace converts it to 2-space without touching string
        // content (a JSON string value from json_encode is always a
        // single line — any embedded newline is escaped as \n — so a
        // leading-whitespace-only regex can never match inside one).
        $json = (string) preg_replace_callback('/^( +)/m', function (array $m): string {
            return str_repeat(' ', intdiv(strlen($m[1]), 2));
        }, $json);

        return $json."\n";
    }
}
