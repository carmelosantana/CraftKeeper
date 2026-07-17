<?php

namespace App\Config\Schemas;

/**
 * The recognized field metadata for one config file convention (e.g.
 * "server-properties", "paper-global") — loaded from a single
 * resources/schemas/config/*.json file by ConfigSchemaRegistry.
 */
final readonly class ConfigSchema
{
    /**
     * @param  list<ConfigSchemaField>  $fields
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $format,
        public array $fields,
    ) {}

    public function field(string $path): ?ConfigSchemaField
    {
        foreach ($this->fields as $field) {
            if ($field->path === $path) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $definition  The fully decoded contents of a resources/schemas/config/*.json file.
     */
    public static function fromArray(array $definition): self
    {
        $fields = array_values(array_map(
            fn (array $field): ConfigSchemaField => ConfigSchemaField::fromArray($field),
            $definition['fields'] ?? [],
        ));

        return new self(
            id: $definition['id'],
            title: $definition['title'],
            format: $definition['format'],
            fields: $fields,
        );
    }
}
