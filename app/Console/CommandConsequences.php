<?php

namespace App\Console;

/**
 * Task 12's ambiguity resolution #1: "a small command -> consequence/
 * description lookup for the known elevated commands (stop, op, deop,
 * ban, whitelist, gamerule, execute) so a real consequence string
 * renders" in the CommandComposer's consequence-review step, BEFORE any
 * confirm control appears.
 *
 * This is presentation copy, not policy — App\Console\CommandPolicy alone
 * decides Safe vs Elevated (and therefore whether approval is required);
 * this class only supplies the human-readable "what will this do" string
 * shown alongside that decision. An unrecognized command (Elevated by
 * CommandPolicy's own default-deny) still gets an honest, generic
 * consequence rather than no explanation at all.
 *
 * Keyed by CommandPolicy::category() (the command's first normalized
 * token, lowercased) rather than the full command text, since every real
 * command this class describes takes arguments (e.g. "op Steve",
 * "ban Steve griefing") that vary per invocation.
 */
final class CommandConsequences
{
    /**
     * @var array<string, string>
     */
    private const SUMMARIES = [
        // Elevated — the brief's own named examples.
        'stop' => 'Stops the Minecraft server.',
        'op' => 'Grants a player operator (admin) privileges.',
        'deop' => "Revokes a player's operator (admin) privileges.",
        'ban' => 'Bans a player from the server, disconnecting them immediately.',
        'ban-ip' => 'Bans an IP address from the server, disconnecting any connected player from it.',
        'pardon' => "Reverses a player's ban.",
        'kick' => 'Disconnects a player from the server immediately.',
        'whitelist' => 'Changes who is allowed to join the server.',
        'gamerule' => 'Changes a server-wide game rule affecting every player.',
        'execute' => 'Runs another command as a different entity or context — can perform any other elevated action.',

        // Safe predefined actions (Task 10's CommandPolicy::SAFE_EXACT) —
        // shown for symmetry when an operator types one of these manually
        // rather than clicking the predefined-action button.
        'list' => 'Lists players currently online. Read-only.',
        'save-all' => 'Forces the server to save all worlds to disk.',
        'say' => 'Broadcasts a message to every player in chat.',
        'time' => 'Reports or changes the current in-game time.',
        'weather' => 'Reports or changes the current in-game weather.',
    ];

    private const DEFAULT_ELEVATED = 'This command is not on the predefined safe list and may change server or player state.';

    public function __construct(
        private readonly CommandPolicy $policy,
    ) {}

    /**
     * A human, consequence-first description of what $command will do.
     */
    public function describe(string $command): string
    {
        $category = $this->policy->category($command);

        return self::SUMMARIES[$category] ?? self::DEFAULT_ELEVATED;
    }
}
