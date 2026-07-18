<?php

namespace Database\Factories;

use App\Models\McpGrant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

/**
 * @extends Factory<McpGrant>
 *
 * Every McpGrant needs a REAL backing Laravel\Passport\Client row (an
 * authorization-code, public/PKCE client — see
 * ClientRepository::createAuthorizationCodeGrantClient()) so
 * tests/Concerns/CallsMcp.php can build a genuine
 * Laravel\Passport\AccessToken pointing at it, exercising the exact same
 * App\Mcp\Support\McpGuard::resolveGrant() code path production traffic
 * uses.
 */
class McpGrantFactory extends Factory
{
    protected $model = McpGrant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            'Test MCP Client '.Str::random(8),
            ['https://example.test/callback'],
            confidential: false,
        );

        return [
            'oauth_client_id' => $client->id,
            'display_name' => $client->name,
            'scopes' => [],
            'expires_at' => null,
            'revoked_at' => null,
            'last_used_at' => null,
            'created_by' => null,
        ];
    }

    /**
     * @param  list<string>  $scopes
     */
    public function withScopes(array $scopes): static
    {
        return $this->state(['scopes' => $scopes]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }
}
