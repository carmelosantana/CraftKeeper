<?php

namespace App\Ai;

use App\Ai\Tools\AllowedToolsPolicy;
use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;

/**
 * The one agent CraftKeeper's AI assistant ever runs. Its tool set is
 * fixed to exactly what the constructor is given — never a toolkit, never
 * anything discovered at runtime — and every call is additionally gated
 * by App\Ai\Tools\AllowedToolsPolicy. In production this is always
 * exactly App\Ai\Tools\ReadConfigTool + ProposeConfigChangeTool +
 * ComposeRconCommandTool (see App\Ai\Providers\AbstractAiProvider::stream()),
 * but the class itself does not hard-code that — the security boundary is
 * "closed set decided by CraftKeeper's own PHP code", not "these three
 * specific tools", so a test can safely swap in fewer/different tools
 * (e.g. to prove an injected tool call is denied) without weakening
 * anything production actually runs.
 *
 * `maxIterations: 8` bounds a single turn's tool-call loop — generous
 * enough for read → propose/compose → done, small enough that a
 * misbehaving or adversarially-prompted model cannot spin forever.
 */
final class AssistantAgent extends AbstractAgent
{
    /**
     * @param  list<ToolInterface>  $tools
     */
    public function __construct(
        ProviderInterface $provider,
        private readonly array $tools,
        private readonly string $systemPrompt,
    ) {
        parent::__construct($provider, maxIter: 8, executionPolicy: new AllowedToolsPolicy);
    }

    public function instructions(): string
    {
        return $this->systemPrompt;
    }

    /**
     * @return list<ToolInterface>
     */
    public function tools(): array
    {
        return $this->tools;
    }
}
