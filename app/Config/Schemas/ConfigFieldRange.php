<?php

namespace App\Config\Schemas;

final readonly class ConfigFieldRange
{
    public function __construct(
        public int|float|null $min,
        public int|float|null $max,
    ) {}

    public function contains(int|float $value): bool
    {
        if ($this->min !== null && $value < $this->min) {
            return false;
        }

        if ($this->max !== null && $value > $this->max) {
            return false;
        }

        return true;
    }
}
