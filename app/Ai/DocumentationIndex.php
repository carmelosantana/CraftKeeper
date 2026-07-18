<?php

namespace App\Ai;

/**
 * Cached official documentation the AI assistant may cite. "Cached"
 * deliberately means a small, curated, offline dataset baked into this
 * class — not a live fetch — because CraftKeeper's own runtime environment
 * (and this task's own test/build sandbox) cannot assume outbound network
 * access is available (see docs/architecture/decisions.md). A future task
 * MAY replace search()'s implementation with one backed by a periodically
 * refreshed cache table without changing this class's public contract.
 *
 * Every entry links to the AUTHORITATIVE source for its topic (Minecraft
 * itself, PaperMC, GeyserMC/Floodgate, Hangar, Modrinth) — the same set
 * the task brief names. search() does simple, bounded keyword overlap;
 * it deliberately does not attempt full-text relevance ranking, since the
 * dataset is small and every entry is already narrowly scoped.
 */
final class DocumentationIndex
{
    /**
     * @return list<array{title: string, url: string, source: string, keywords: list<string>}>
     */
    private static function catalog(): array
    {
        return [
            [
                'title' => 'Server.properties reference',
                'url' => 'https://minecraft.wiki/w/Server.properties',
                'source' => 'Minecraft Wiki',
                'keywords' => ['server.properties', 'server-properties', 'minecraft', 'vanilla', 'whitelist', 'online-mode', 'motd'],
            ],
            [
                'title' => 'Paper configuration reference',
                'url' => 'https://docs.papermc.io/paper/reference/configuration',
                'source' => 'PaperMC',
                'keywords' => ['paper', 'paper-global', 'paper-world-defaults', 'chunk', 'tick', 'performance', 'tps'],
            ],
            [
                'title' => 'Paper command reference',
                'url' => 'https://docs.papermc.io/paper/reference/commands',
                'source' => 'PaperMC',
                'keywords' => ['command', 'rcon', 'console'],
            ],
            [
                'title' => 'Geyser setup and configuration',
                'url' => 'https://wiki.geysermc.org/geyser/setup/',
                'source' => 'GeyserMC',
                'keywords' => ['geyser', 'bedrock', 'cross-platform'],
            ],
            [
                'title' => 'Floodgate setup and configuration',
                'url' => 'https://wiki.geysermc.org/floodgate/',
                'source' => 'GeyserMC',
                'keywords' => ['floodgate', 'bedrock', 'auth-type', 'authentication'],
            ],
            [
                'title' => 'Hangar plugin listings',
                'url' => 'https://hangar.papermc.io/',
                'source' => 'Hangar',
                'keywords' => ['hangar', 'plugin', 'paper plugin'],
            ],
            [
                'title' => 'Modrinth plugin and mod listings',
                'url' => 'https://modrinth.com/',
                'source' => 'Modrinth',
                'keywords' => ['modrinth', 'plugin', 'mod'],
            ],
        ];
    }

    /**
     * @param  list<string>  $keywords
     * @return list<array{title: string, url: string, source: string}>
     */
    public function search(array $keywords): array
    {
        $needles = array_values(array_filter(array_map(
            fn (string $keyword): string => strtolower(trim($keyword)),
            $keywords,
        ), fn (string $keyword): bool => $keyword !== ''));

        if ($needles === []) {
            return [];
        }

        $matches = [];

        foreach (self::catalog() as $entry) {
            $hit = false;

            foreach ($entry['keywords'] as $keyword) {
                foreach ($needles as $needle) {
                    if (str_contains($keyword, $needle) || str_contains($needle, $keyword)) {
                        $hit = true;
                        break 2;
                    }
                }
            }

            if ($hit) {
                $matches[] = ['title' => $entry['title'], 'url' => $entry['url'], 'source' => $entry['source']];
            }
        }

        return $matches;
    }
}
