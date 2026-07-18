<?php

namespace App\Models;

use Database\Factories\McpGrantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One MCP OAuth integration: exactly one row per Passport OAuth client
 * (`oauth_client_id`, a `laravel/passport` `oauth_clients.id`), carrying
 * the SAME scope strings as App\Support\ApiScope (Task 17), a display
 * name, expiry, revocation time, and last-used time.
 *
 * This is the AUTHORITATIVE source of truth App\Policies\McpGrantPolicy
 * enforces against for every MCP tool/resource call — deliberately NOT
 * the live Passport access token's own `oauth_scopes` (which the OAuth
 * negotiation could, in principle, ask for more broadly than what an
 * admin actually intends to allow). An admin sets this grant's scope
 * ceiling explicitly on the Integrations > MCP page
 * (App\Http\Controllers\Integrations\McpGrantController) BEFORE ever
 * handing the resulting client_id to MCP client software; nothing an MCP
 * client requests during the OAuth authorize dance can widen it — see
 * docs/architecture/decisions.md's Task 18 entry for the full rationale.
 *
 * @property int $id
 * @property string $oauth_client_id
 * @property string $display_name
 * @property list<string> $scopes
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_used_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['oauth_client_id', 'display_name', 'scopes', 'expires_at', 'created_by'])]
class McpGrant extends Model
{
    /** @use HasFactory<McpGrantFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<McpAuditEvent, $this>
     */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(McpAuditEvent::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
