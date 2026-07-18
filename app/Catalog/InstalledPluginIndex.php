<?php

namespace App\Catalog;

use App\Models\PluginInstallation;

/**
 * The "installed relevance for sorting" signal named in Task 14's
 * context (Task 13's inventory). Deliberately name-based, case-
 * insensitive: App\Models\PluginInstallation has no notion of a
 * catalog project id (Task 13 predates this task), so the only
 * available correlation between "a plugin already on this server" and
 * "a search result" is its declared name. This is used ONLY to break
 * sort ties (App\Catalog\UnifiedCatalogService ranks an
 * already-installed plugin's search result earlier, on the theory that
 * an operator searching the catalog is disproportionately likely to be
 * checking for updates to something they already run) — it is never
 * used to merge or deduplicate results, which stays strictly
 * source+projectId (see UnifiedCatalogService's docblock).
 */
final class InstalledPluginIndex
{
    /**
     * @var array<string, bool>
     */
    private readonly array $lowercasedNames;

    public function __construct()
    {
        $this->lowercasedNames = PluginInstallation::query()
            ->whereNotNull('name')
            ->pluck('name')
            ->mapWithKeys(fn (string $name) => [strtolower($name) => true])
            ->all();
    }

    public function isInstalled(string $name): bool
    {
        return isset($this->lowercasedNames[strtolower($name)]);
    }
}
