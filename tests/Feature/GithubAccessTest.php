<?php

use App\Models\Activity;
use App\Models\Application;
use App\Models\GithubToken;
use App\Models\Site;
use App\Models\User;
use App\Net\GithubClient;
use App\Net\Results\GithubRepository;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * GitHub access (SPEC §11.9, §13.6): one read-only token per owner, never
 * sent back to the browser, and a Test of every linked repository.
 */
const PAT = 'github_pat_11ABCDEFG0123456789_abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUV1234';

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->github = app(GithubClient::class);
});

it('lists the owner of every linked repository with its token, masked', function () {
    Application::factory()->create(['github_url' => 'https://github.com/media24/svet24']);
    Site::factory()->create(['github_url' => 'https://github.com/Media24/api']);
    Site::factory()->create(['github_url' => 'https://github.com/northwind/shop']);
    GithubToken::factory()->create(['owner' => 'media24', 'token' => PAT, 'token_last4' => '1234']);

    $this->actingAs($this->admin)->get(route('github-access.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('github-access/Index')
            ->has('owners', 2)
            ->where('owners.0.owner', 'media24')
            ->where('owners.0.repositories', ['Media24/api', 'media24/svet24'])
            ->where('owners.0.token_masked', 'github_pat_••••1234')
            ->where('owners.1.owner', 'northwind')
            ->where('owners.1.token_id', null))
        ->assertDontSee(PAT);
});

it('saves a token encrypted and records only its last four characters', function () {
    $this->actingAs($this->admin)->post(route('github-tokens.store'), ['owner' => ' media24 ', 'token' => PAT.' '])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $token = GithubToken::query()->sole();
    $activity = Activity::query()->where('type', 'github_token.set')->sole();

    expect($token->owner)->toBe('media24')
        ->and($token->token)->toBe(PAT)
        ->and($token->getRawOriginal('token'))->not->toContain(PAT)
        ->and($token->token_last4)->toBe('1234')
        ->and($token->toArray())->not->toHaveKey('token')
        ->and($activity->summary)->toBe('GitHub token for media24 set (…1234)')
        ->and($activity->user_id)->toBe($this->admin->id)
        ->and(json_encode($activity->properties))->not->toContain(PAT);
});

it('replaces the token of an owner, whatever the case of its name', function () {
    $token = GithubToken::factory()->create(['owner' => 'media24', 'last_error' => 'GitHub token rejected (401): expired or revoked']);
    $new = substr(PAT, 0, -4).'9876';

    $this->actingAs($this->admin)->post(route('github-tokens.store'), ['owner' => 'Media24', 'token' => $new])->assertSessionHasNoErrors();

    expect(GithubToken::query()->count())->toBe(1)
        ->and($token->fresh()->token)->toBe($new)
        ->and($token->fresh()->last_error)->toBeNull()
        ->and(Activity::query()->where('type', 'github_token.replaced')->sole()->properties)
        ->toEqual(['token_last4' => ['old' => $token->token_last4, 'new' => '9876']]);
});

it('rejects classic tokens and owners that are not GitHub names', function () {
    $this->actingAs($this->admin)->post(route('github-tokens.store'), ['owner' => 'media24/svet24', 'token' => 'ghp_'.str_repeat('a', 36)])
        ->assertSessionHasErrors([
            'owner' => 'Enter the organisation or user name as it appears in github.com/{owner}.',
            'token' => 'Use a fine-grained token (it starts with github_pat_) with read-only access to Contents.',
        ]);

    expect(GithubToken::query()->count())->toBe(0);
});

it('removes a token and records it', function () {
    $token = GithubToken::factory()->create(['owner' => 'media24']);

    $this->actingAs($this->admin)->delete(route('github-tokens.destroy', $token))->assertRedirect();

    expect(GithubToken::query()->count())->toBe(0)
        ->and(Activity::query()->where('type', 'github_token.removed')->sole()->summary)->toBe('GitHub token for media24 removed');
});

it('tests every linked repository of the owner and remembers expiry and the first error', function () {
    $token = GithubToken::factory()->create(['owner' => 'media24', 'token' => PAT]);
    Application::factory()->create(['github_url' => 'https://github.com/media24/svet24']);
    Site::factory()->create(['github_url' => 'https://github.com/media24/old']);
    Site::factory()->create(['github_url' => 'https://github.com/northwind/shop']);
    $this->github
        ->repo('media24/svet24', ['composer.lock' => '{}', 'package-lock.json' => '{}', 'README.md' => '#'], PAT, new GithubRepository('main', false, now()->addDays(30)->startOfSecond()->toImmutable()))
        ->fail('media24/old', "Repository not found, or the GitHub token can't read it (404)");
    $activities = Activity::query()->count();

    $this->actingAs($this->admin)->postJson(route('github-tokens.test', $token))
        ->assertOk()
        ->assertJson([
            'ok' => false,
            'expires_at' => now()->addDays(30)->startOfSecond()->toIso8601String(),
            'repositories' => [
                ['name' => 'media24/old', 'ok' => false, 'error' => "Repository not found, or the GitHub token can't read it (404)"],
                ['name' => 'media24/svet24', 'ok' => true, 'default_branch' => 'main', 'lock_files' => ['composer.lock', 'package-lock.json']],
            ],
        ])
        ->assertJsonCount(2, 'repositories');

    expect($token->fresh()->last_error)->toBe("Repository not found, or the GitHub token can't read it (404)")
        ->and($token->fresh()->checked_at)->not->toBeNull()
        ->and($token->fresh()->expires_at->toIso8601String())->toBe(now()->addDays(30)->startOfSecond()->toIso8601String())
        ->and(Activity::query()->count())->toBe($activities);
});

it('says so when no site or application links a repository of the owner', function () {
    $token = GithubToken::factory()->create(['owner' => 'media24']);

    $this->actingAs($this->admin)->postJson(route('github-tokens.test', $token))
        ->assertOk()
        ->assertJson(['ok' => false, 'error' => 'No site or application links a repository of media24.']);
});
