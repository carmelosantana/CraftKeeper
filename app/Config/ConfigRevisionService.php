<?php

namespace App\Config;

use App\Filesystem\MinecraftFilesystem;
use App\Filesystem\MinecraftPath;
use App\Models\ConfigRevision;
use App\Models\Operation;
use App\Models\User;
use App\Operations\OperationAuthor;
use RuntimeException;

/**
 * Restores a config file toward a previously recorded ConfigRevision — by
 * proposing a fresh, reviewable config.restore Operation, never by writing
 * the old bytes back directly. See the class docblock on
 * App\Operations\Handlers\ConfigRestoreHandler for the execute()-time half
 * of this (which reuses the exact same approve -> execute pipeline as a
 * normal edit).
 */
class ConfigRevisionService
{
    public function __construct(
        private readonly MinecraftFilesystem $filesystem,
        private readonly ConfigFormatRegistry $formats,
        private readonly ConfigChangeService $changes,
    ) {}

    /**
     * Builds the field-level changes needed to move the file's CURRENT
     * content toward $revision's captured content, and proposes them
     * through App\Config\ConfigChangeService — exactly the same
     * conflict-check, redaction, diff, and validation pipeline a normal
     * edit goes through. The base hash used for the conflict check is the
     * file's real, freshly-read current hash, so restoring is subject to
     * the same optimistic-concurrency guarantee as any other edit.
     */
    public function restore(ConfigRevision $revision, User $user): Operation
    {
        $configFile = $revision->configFile;
        $path = MinecraftPath::fromUserInput($configFile->path);
        $current = $this->filesystem->read($path);

        $targetContents = $this->readSnapshot($revision);
        $adapter = $this->formats->for($current);

        $changeSet = $this->diffTowardRevision($adapter, $current->contents, $targetContents);

        $request = new ConfigChangeRequest($configFile->path, $current->sha256, $changeSet);

        return $this->changes->proposeRestore($request, OperationAuthor::user($user->getKey()), $revision);
    }

    private function readSnapshot(ConfigRevision $revision): string
    {
        $contents = @file_get_contents($revision->snapshot_path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read the captured snapshot for revision [{$revision->id}].");
        }

        return $contents;
    }

    /**
     * Diffs two full file contents field-by-field (over each format's
     * locatable scalar leaves — see App\Config\ParsedConfig::$nodes) and
     * emits the Replace/Add/Remove ConfigChanges needed to move
     * $currentContents toward $targetContents. This is deliberately a
     * best-effort "propose the changes needed to return it toward the
     * revision" (per the V1 plan), not a byte-identical restoration
     * guarantee — non-scalar-leaf structural differences (e.g. a
     * reordered YAML sequence) are outside what ConfigChange can express
     * field-by-field, exactly as they are for a normal guided/structured
     * edit.
     *
     * @return list<ConfigChange>
     */
    private function diffTowardRevision(ConfigFormatAdapter $adapter, string $currentContents, string $targetContents): array
    {
        if ($currentContents === $targetContents) {
            return [];
        }

        $currentByPath = [];
        foreach ($adapter->parse($currentContents)->nodes as $node) {
            $currentByPath[$node->path] = $node;
        }

        $targetByPath = [];
        foreach ($adapter->parse($targetContents)->nodes as $node) {
            $targetByPath[$node->path] = $node;
        }

        $changes = [];

        foreach ($targetByPath as $path => $node) {
            if (! array_key_exists($path, $currentByPath)) {
                $changes[] = ConfigChange::add($path, $node->value);
            } elseif ($currentByPath[$path]->value !== $node->value) {
                $changes[] = ConfigChange::replace($path, $node->value);
            }
        }

        foreach (array_keys($currentByPath) as $path) {
            if (! array_key_exists($path, $targetByPath)) {
                $changes[] = ConfigChange::remove($path);
            }
        }

        return $changes;
    }
}
