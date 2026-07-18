<?php

namespace App\Catalog\Data;

/**
 * One dependency a PluginRelease declares, as reported by its source —
 * intentionally a much thinner shape than App\Plugins\
 * PluginDependencyGraph's node (that graph is built from installed/
 * inspected JARs; this is catalog metadata about a release nothing has
 * necessarily been installed yet). $minVersion is advisory only —
 * nothing in this task enforces it.
 */
final readonly class PluginDependencyRef
{
    public function __construct(
        public string $name,
        public bool $required,
        public ?string $minVersion = null,
    ) {}

    /**
     * @return array{name: string, required: bool, minVersion: ?string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'required' => $this->required, 'minVersion' => $this->minVersion];
    }

    /**
     * @param  array<string, mixed>  $data  Shape: {name: string, required: bool, minVersion?: ?string}
     */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['name'], (bool) $data['required'], isset($data['minVersion']) ? (string) $data['minVersion'] : null);
    }
}
