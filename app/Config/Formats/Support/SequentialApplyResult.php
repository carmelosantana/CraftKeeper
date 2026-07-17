<?php

namespace App\Config\Formats\Support;

/**
 * The result of walking a change batch sequentially (see YamlAdapter's
 * and TomlAdapter's applySequentially()): the fully-applied $contents,
 * and whether ANY step along the way required a full structural
 * re-serialize rather than an in-place byte patch.
 *
 * applyChanges() keeps only `contents`; willNormalize() keeps only
 * `normalized` and discards the simulated bytes — both adapters run the
 * exact same pass either way, so the two can never disagree about the
 * same input.
 */
final readonly class SequentialApplyResult
{
    public function __construct(
        public string $contents,
        public bool $normalized,
    ) {}
}
