<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Unit, Integration/Filesystem, Integration/Runtime, and Contract tests
// get the full Laravel TestCase (so config(), app(), etc. work) but skip
// RefreshDatabase — none of these touch the database. Contract tests
// validate fixtures against resources/catalog/plugin-catalog.schema.json
// purely in memory. Task 20's Integration/Runtime (the opt-in Legendary
// stack smoke test) only ever drives a real HTTP/RCON connection to an
// external container, never Eloquent.
//
// Listed as explicit subdirectories (not a blanket 'Integration') because
// Task 20's OWN Integration/Security needs a real database (see below) —
// Pest does not allow one directory to be covered by two separate
// `extend()` registrations that both reference the same TestCase.
pest()->extend(TestCase::class)
    ->in('Unit', 'Integration/Filesystem', 'Integration/Runtime', 'Contract');

// Task 20: Integration/Security is the one Integration subdirectory that
// DOES need a real (fast, in-memory) database — SecretLeakTest exercises
// real Users/Operations/McpGrants/AiConversations/McpAuditEvents across
// the full redaction matrix, and FilesystemBoundaryTest drives real HTTP/
// MCP entry points that require an authenticated admin.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Integration/Security');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
