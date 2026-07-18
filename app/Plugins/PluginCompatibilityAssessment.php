<?php

namespace App\Plugins;

/**
 * The result of PluginCompatibilityService::evaluate(): a verdict AND the
 * evidence behind it, never just the verdict alone — every consumer
 * (this task's PluginInventoryService, and Task 15's plugin pages) can
 * show the operator WHY a plugin was assessed a given way instead of
 * presenting an opaque label.
 */
final readonly class PluginCompatibilityAssessment
{
    /**
     * @param  list<PluginCompatibilityEvidence>  $evidence
     */
    public function __construct(
        public PluginCompatibilityState $state,
        public array $evidence,
    ) {}
}
