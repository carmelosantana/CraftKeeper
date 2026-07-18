<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->create();
    // See tests/Feature/Support/BackupServiceTest.php's own docblock:
    // SQLite refuses VACUUM INTO while any transaction is open, and
    // Pest's RefreshDatabase wraps every test in one.
    DB::connection()->commit();
});

it('requires authentication for the backups page', function () {
    $this->get('/settings/backups')->assertRedirect('/login');
});

it('lists no backups until one is created', function () {
    $this->actingAs($this->admin)->get('/settings/backups')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/backups')->has('backups', 0));
});

it('creates, downloads, and deletes a backup end to end', function () {
    $this->actingAs($this->admin)->post('/settings/backups')->assertRedirect('/settings/backups');

    $listResponse = $this->actingAs($this->admin)->get('/settings/backups')->assertOk();
    $listResponse->assertInertia(fn (Assert $page) => $page->has('backups', 1));

    $name = $listResponse->getOriginalContent()->getData()['page']['props']['backups'][0]['name'];

    $this->actingAs($this->admin)->get("/settings/backups/{$name}/download")->assertOk();

    $this->actingAs($this->admin)->delete("/settings/backups/{$name}")->assertRedirect('/settings/backups');

    $this->actingAs($this->admin)->get('/settings/backups')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('backups', 0));
});

it('rejects a path-traversal-shaped backup filename', function () {
    $this->actingAs($this->admin)->get('/settings/backups/..%2f..%2f..%2fetc%2fpasswd/download')->assertNotFound();
    $this->actingAs($this->admin)->get('/settings/backups/not-a-real-backup.zip/download')->assertNotFound();
    $this->actingAs($this->admin)->delete('/settings/backups/not-a-real-backup.zip')->assertNotFound();
});
