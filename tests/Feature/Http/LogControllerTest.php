<?php

use App\Models\ConsoleEntry;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TempMinecraftRoot;

beforeEach(function () {
    $this->minecraftRoot = TempMinecraftRoot::create();
    config(['craftkeeper.minecraft_root' => $this->minecraftRoot]);
    $this->admin = User::factory()->create();

    // App\Server\ServerStatusService's `logs` half is a direct, independent
    // filesystem check (Task 11) — a real logs/latest.log must exist for
    // this suite's "remains usable" assertion to be meaningful rather than
    // vacuously true.
    File::makeDirectory($this->minecraftRoot.'/logs', 0755, true, true);
    file_put_contents($this->minecraftRoot.'/logs/latest.log', "[00:00:00 INFO]: Starting minecraft server\n");
});

afterEach(function () {
    TempMinecraftRoot::destroy($this->minecraftRoot);
});

function seedLogLines(): void
{
    $base = now()->subMinutes(10);

    ConsoleEntry::query()->create(['line' => '[00:00:01 INFO]: Server started', 'occurred_at' => $base]);
    ConsoleEntry::query()->create(['line' => '[00:00:02 WARN]: Can\'t keep up! Running behind', 'occurred_at' => $base->copy()->addSecond()]);
    ConsoleEntry::query()->create(['line' => 'Steve joined the game', 'occurred_at' => $base->copy()->addSeconds(2)]);
    ConsoleEntry::query()->create(['line' => '<Steve> hello world', 'occurred_at' => $base->copy()->addSeconds(3)]);
    ConsoleEntry::query()->create(['line' => 'Steve left the game', 'occurred_at' => $base->copy()->addSeconds(4)]);
}

it('requires authentication', function () {
    $this->get('/server/logs')->assertRedirect('/login');
});

it('remains usable when RCON is unavailable — file-based logs are independent of RCON', function () {
    seedLogLines();

    $this->actingAs($this->admin)
        ->get('/server/logs')
        ->assertInertia(fn (Assert $page) => $page
            ->component('server/Logs')
            ->where('logs.available', true)
            ->has('entries', 5)
            ->etc()
        );
});

it('filters by level', function () {
    seedLogLines();

    $this->actingAs($this->admin)
        ->get('/server/logs?level=WARN')
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries', 1)
            ->where('entries.0.line', fn ($line) => str_contains($line, "Can't keep up"))
            ->etc()
        );
});

it('filters by player, derived from the parsed line', function () {
    seedLogLines();

    $this->actingAs($this->admin)
        ->get('/server/logs?player=Steve')
        ->assertInertia(function (Assert $page) {
            $page->has('entries', 3);
            $entries = $page->toArray()['props']['entries'];

            foreach ($entries as $entry) {
                expect($entry['player'])->toBe('Steve');
            }

            return $page->etc();
        });
});

it('filters by text search and includes context lines around each match', function () {
    seedLogLines();

    $this->actingAs($this->admin)
        ->get('/server/logs?q=hello&context=1')
        ->assertInertia(function (Assert $page) {
            $page->has('entries', 3);
            $matchedFlags = array_column($page->toArray()['props']['entries'], 'matched');

            expect($matchedFlags)->toBe([false, true, false]);

            return $page->etc();
        });
});

it('bounds results and reports truncation', function () {
    for ($i = 0; $i < 10; $i++) {
        ConsoleEntry::query()->create(['line' => "line {$i}", 'occurred_at' => now()->subSeconds(10 - $i)]);
    }

    $this->actingAs($this->admin)
        ->get('/server/logs')
        ->assertInertia(fn (Assert $page) => $page
            ->where('totalMatched', 10)
            ->where('truncated', false) // under MAX_RESULT_ROWS
            ->etc()
        );
});

it('downloads a bounded, filtered plain-text export', function () {
    seedLogLines();

    $response = $this->actingAs($this->admin)->get('/server/logs/download?player=Steve');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    expect($response->getContent())->toContain('Steve joined the game')
        ->toContain('Steve left the game');
});
