<?php

namespace App\Config\Schemas;

use App\Config\ConfigDiagnostic;
use App\Config\DiagnosticSeverity;
use App\Config\Formats\Support\DotPath;

/**
 * Shared schema-metadata validation used by every ConfigFormatAdapter::
 * validate() implementation once the underlying syntax has already parsed
 * successfully. Deliberately format-aware about how a field's dotted
 * `path` maps onto the decoded data: structured formats (YAML/JSON/TOML)
 * treat it as real nesting via DotPath; the flat Properties format
 * treats the whole string as one literal key (server.properties keys can
 * themselves legitimately contain dots, e.g. "rcon.port").
 *
 * Schema mismatches are always Warning severity, never Error — the
 * schema describes CraftKeeper's recognized, guided-editing surface, not
 * a strict contract the underlying server enforces. A value the schema
 * doesn't expect (e.g. a plugin fork that accepts a wider range) must
 * not block viewing or editing the file; only a genuine syntax failure
 * (caught separately, before this ever runs) makes a ValidationResult
 * invalid.
 */
final class SchemaValidator
{
    /**
     * @param  array<string, mixed>  $data
     * @return list<ConfigDiagnostic>
     */
    public static function validate(array $data, ConfigSchema $schema, bool $flatKeys): array
    {
        $diagnostics = [];

        foreach ($schema->fields as $field) {
            $exists = $flatKeys
                ? array_key_exists($field->path, $data)
                : DotPath::has($data, $field->path);

            if (! $exists) {
                continue;
            }

            $value = $flatKeys ? $data[$field->path] : self::get($data, $field->path);

            array_push($diagnostics, ...self::validateField($field, $value));
        }

        return $diagnostics;
    }

    /**
     * @return list<ConfigDiagnostic>
     */
    private static function validateField(ConfigSchemaField $field, mixed $value): array
    {
        $diagnostics = [];

        if (! self::matchesType($field->type, $value)) {
            $diagnostics[] = new ConfigDiagnostic(
                DiagnosticSeverity::Warning,
                sprintf('Expected [%s] to be %s, got %s.', $field->path, $field->type->value, get_debug_type($value)),
                path: $field->path,
            );

            return $diagnostics;
        }

        if ($field->allowedValues !== null && is_scalar($value) && ! in_array($value, $field->allowedValues, true)) {
            $diagnostics[] = new ConfigDiagnostic(
                DiagnosticSeverity::Warning,
                sprintf('[%s] value %s is not one of the recognized allowed values.', $field->path, json_encode($value)),
                path: $field->path,
            );
        }

        if ($field->range !== null && (is_int($value) || is_float($value)) && ! $field->range->contains($value)) {
            $diagnostics[] = new ConfigDiagnostic(
                DiagnosticSeverity::Warning,
                sprintf('[%s] value %s is outside the recognized range.', $field->path, (string) $value),
                path: $field->path,
            );
        }

        return $diagnostics;
    }

    private static function matchesType(ConfigFieldType $type, mixed $value): bool
    {
        return match ($type) {
            ConfigFieldType::Boolean => is_bool($value),
            ConfigFieldType::Integer => is_int($value),
            ConfigFieldType::Number => is_int($value) || is_float($value),
            ConfigFieldType::String => is_string($value),
            ConfigFieldType::Array => is_array($value) && array_is_list($value),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function get(array $data, string $path): mixed
    {
        $cursor = $data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
