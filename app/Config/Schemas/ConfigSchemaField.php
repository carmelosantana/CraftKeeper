<?php

namespace App\Config\Schemas;

/**
 * One documented, recognized field of a ConfigSchema. Every property
 * here is required by the Task 7 brief: dotted path, type, title,
 * description, default, restart impact, risk, allowed values/range,
 * secret flag, and an authoritative documentation URL.
 *
 * `$secret` gates whether Task 9's UI is ever allowed to send this
 * field's actual value to the browser (it must not) — see the plan's
 * "never sends raw secret values to the browser" test for
 * ConfigController. `rcon.password` is the canonical example.
 */
final readonly class ConfigSchemaField
{
    /**
     * @param  list<scalar>|null  $allowedValues
     */
    public function __construct(
        public string $path,
        public ConfigFieldType $type,
        public string $title,
        public string $description,
        public mixed $default,
        public RestartImpact $restartImpact,
        public ConfigFieldRisk $risk,
        public ?array $allowedValues,
        public ?ConfigFieldRange $range,
        public bool $secret,
        public string $documentationUrl,
    ) {}

    /**
     * @param  array<string, mixed>  $definition  One decoded entry from resources/schemas/config/*.json's "fields" array.
     */
    public static function fromArray(array $definition): self
    {
        $range = null;

        if (isset($definition['range']) && is_array($definition['range'])) {
            $range = new ConfigFieldRange(
                $definition['range']['min'] ?? null,
                $definition['range']['max'] ?? null,
            );
        }

        return new self(
            path: $definition['path'],
            type: ConfigFieldType::from($definition['type']),
            title: $definition['title'],
            description: $definition['description'],
            default: $definition['default'] ?? null,
            restartImpact: RestartImpact::from($definition['restartImpact']),
            risk: ConfigFieldRisk::from($definition['risk']),
            allowedValues: $definition['allowedValues'] ?? null,
            range: $range,
            secret: (bool) ($definition['secret'] ?? false),
            documentationUrl: $definition['documentationUrl'],
        );
    }
}
