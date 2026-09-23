<?php

use App\Models\User;
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

// RefreshDatabase wraps each test in a transaction and rolls it back — fast.
// Deliberately excludes Feature/Search: verified empirically against this
// project's MySQL container that InnoDB FULLTEXT does NOT reliably see a
// row inserted earlier in the same *uncommitted* transaction once the table
// already holds other committed rows (a fresh, still-empty FULLTEXT table
// does show same-transaction visibility, but the real, exercised `contacts`
// table does not) — see that directory's own DatabaseTruncation setup and
// DECISIONS.md #6. The two are not combined on one file: RefreshDatabase
// and DatabaseTruncation both hook database setup/teardown and are not
// designed to be layered together.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature/Auth', 'Feature/Console', 'Feature/Contacts', 'Feature/Interactions');

// Unit tests still need the app booted (Cache/report()/response() etc.),
// just without the per-test database transaction — none of them touch
// the DB directly.
pest()->extend(TestCase::class)->in('Unit');

// Cache::flush() between tests (rate limits, idempotency locks) happens in
// Tests\TestCase::setUp() — guaranteed to run for every test regardless of
// directory, which a Pest-level global beforeEach() was not reliably doing
// here alongside the per-directory pest()->extend() configuration above.

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

function agent(array $attributes = []): User
{
    return User::factory()->create($attributes);
}

function teamLead(array $attributes = []): User
{
    return User::factory()->teamLead()->create($attributes);
}
