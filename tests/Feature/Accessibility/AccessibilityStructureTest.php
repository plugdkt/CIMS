<?php

// T-051 (NFR-07, WCAG 2.1 Level AA): regression guards for the two systemic issues
// found by a real axe-core audit — neither PHPStan nor Pint catches either class of
// bug (see CLAUDE.md's T-051 notes). These are structural checks, not a full a11y
// scan; the actual audit was done live in a browser against real rendered pages.

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

test('every scrollable region (overflow-x-auto/overflow-y-auto) is keyboard-focusable (WCAG 2.1.1)', function () {
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $contents = File::get($file->getPathname());

        preg_match_all('/<div\b[^>]*\boverflow-[xy]-auto\b[^>]*>/', $contents, $matches);

        foreach ($matches[0] as $tag) {
            if (! str_contains($tag, 'tabindex=')) {
                $offenders[] = $file->getRelativePathname().': '.$tag;
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('the item create form has a matching id for every visible label (WCAG 1.3.1/4.1.2)', function () {
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('code', 'LAB_MANAGER')->firstOrFail());

    $html = $this->actingAs($user)->get('/items/create')->getContent();

    preg_match_all('/<label\b[^>]*\bfor="([^"]+)"/', $html, $labelMatches);

    expect($labelMatches[1])->not->toBeEmpty();

    foreach ($labelMatches[1] as $targetId) {
        expect($html)->toContain('id="'.$targetId.'"');
    }
});
