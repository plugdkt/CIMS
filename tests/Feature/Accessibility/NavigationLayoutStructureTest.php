<?php

// ITM-2610-052 phase 1 (plan 1.4, 6.1): structural regression guards for the new page chrome that
// replaced the sidebar. Same spirit as AccessibilityStructureTest (T-051): these are structural
// checks on the HTML, not a full a11y scan. Real-browser checks on a phone-width screen still have
// to be done by a person (the T-036 lesson: layout problems are not caught by headless tests).

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

test('every scrollable region, whatever the tag, is keyboard-focusable (WCAG 2.1.1); the older check covers <div> only', function () {
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        preg_match_all('/<(?:div|nav|ul|ol|section|aside|main|table|form)\b[^>]*\boverflow-[xy]-(?:auto|scroll)\b[^>]*>/', File::get($file->getPathname()), $matches);

        foreach ($matches[0] as $tag) {
            if (! str_contains($tag, 'tabindex=')) {
                $offenders[] = $file->getRelativePathname().': '.$tag;
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('the section menu is a labelled <aside> holding a labelled <nav> of plain links with exactly one aria-current', function () {
    $html = $this->actingAs(navUser('auditor'))->get(route('stock-takes.index'))->assertOk()->getContent();
    $xp = htmlXPath($html);

    $asides = $xp->query('//aside');
    expect($asides->length)->toBe(1);
    $aside = $asides->item(0);
    expect($aside->getAttribute('id'))->toBe('app-sidebar');
    expect($aside->getAttribute('aria-label'))->toBe(__('nav.sidebar_label'));

    // The aside is the scrollable region (a long menu on a short phone screen), so it carries
    // the overflow and the keyboard focus; the <nav> inside is a plain list of links.
    expect($aside->getAttribute('class'))->toContain('overflow-y-auto');
    expect($aside->getAttribute('tabindex'))->toBe('0');

    $navs = $xp->query('.//nav[@aria-label="'.__('nav.tabs_label').'"]', $aside);
    expect($navs->length)->toBe(1);
    $nav = $navs->item(0);
    expect($nav->getAttribute('class'))->not->toContain('overflow-');
    expect($nav->hasAttribute('tabindex'))->toBeFalse();
    expect($xp->query('.//*[contains(@class, "overflow-")]', $nav)->length)->toBe(0);

    // Menu entries are links, not buttons or divs (middle-click, open in new tab, screen-reader link lists).
    expect($xp->query('.//a', $nav)->length)->toBeGreaterThan(2);
    expect($xp->query('.//button', $nav)->length)->toBe(0);
    foreach ($xp->query('.//a', $nav) as $a) {
        expect($a->getAttribute('href'))->not->toBe('');
        expect(trim($a->textContent))->not->toBe('');
    }

    // Exactly one marked current, and it is the page we are on.
    $current = $xp->query('.//a[@aria-current]', $nav);
    expect($current->length)->toBe(1);
    expect($current->item(0)->getAttribute('aria-current'))->toBe('page');
    expect(trim($current->item(0)->textContent))->toBe(__('nav.stock_takes'));
});

test('the menu works without JavaScript: the sidebar starts as an ordinary block, the drawer is only layered on by the script', function () {
    $xp = htmlXPath($this->actingAs(navUser('auditor'))->get(route('reports.index'))->assertOk()->getContent());

    // Initial HTML (before any script runs): no drawer positioning on the aside, so the menu is plain content.
    $tokens = preg_split('/\s+/', trim($xp->query('//aside[@id="app-sidebar"]')->item(0)->getAttribute('class')));
    foreach ($tokens as $token) {
        expect($token)->not->toContain('fixed')->not->toContain('invisible')->not->toContain('translate-x');
    }

    // The open button is a real <button>, hidden until the script makes the drawer available.
    $open = $xp->query('//*[@id="drawer-open"]');
    expect($open->length)->toBe(1);
    expect($open->item(0)->nodeName)->toBe('button');
    expect(preg_split('/\s+/', trim($open->item(0)->getAttribute('class'))))->toContain('hidden');
    expect($open->item(0)->getAttribute('aria-expanded'))->toBe('false');
    expect($open->item(0)->getAttribute('aria-controls'))->toBe('app-sidebar');
    expect($open->item(0)->getAttribute('aria-label'))->toBe(__('nav.open_menu'));
});

test('a skip link is the first link on the page, leads to <main id="main-content">, and the page column exists', function () {
    foreach ([route('dashboard'), route('reports.index')] as $url) {
        $xp = htmlXPath($this->actingAs(navUser('auditor'))->get($url)->assertOk()->getContent());

        $first = $xp->query('//body//a[1]');
        expect($first->item(0)->getAttribute('href'))->toBe('#main-content', $url);
        expect(trim($first->item(0)->textContent))->toBe(__('nav.skip_to_content'));

        expect($xp->query('//main[@id="main-content"]')->length)->toBe(1, $url);
        expect($xp->query('//*[@id="page-column"]')->length)->toBe(1, $url);
        expect($xp->query('//*[@id="main-content"]')->length)->toBe(1, "{$url}: duplicate id");
    }
});

test('the warehouse overview explains itself when the viewer has nothing to show on it', function () {
    // Can open the warehouse card (stock takes) but neither decides nor dispenses requisitions and has no report.view.
    $user = userWithPermissions(['stocktake.manage'], ['lab_id' => makeLab()->id, 'profile_completed_at' => now()]);
    $this->actingAs($user)->get(route('warehouse.overview'))->assertOk()->assertSee(__('home.overview_empty'));

    // A dispenser has a (possibly zero) queue number, so no explanation is shown.
    $this->actingAs(navUser('scientist'))->get(route('warehouse.overview'))->assertOk()->assertDontSee(__('home.overview_empty'));
});

test('the phone drawer button reports its state, names the sidebar it controls and has an accessible name', function () {
    $xp = htmlXPath($this->actingAs(navUser('auditor'))->get(route('reports.index'))->assertOk()->getContent());

    $open = $xp->query('//header//button[@id="drawer-open"]');
    expect($open->length)->toBe(1);
    expect($open->item(0)->getAttribute('aria-expanded'))->toBe('false');
    expect($open->item(0)->getAttribute('aria-controls'))->toBe('app-sidebar');
    expect($open->item(0)->getAttribute('aria-label'))->toBe(__('nav.open_menu'));

    // The id it points at exists, and the drawer can be closed by a button with its own name.
    expect($xp->query('//aside[@id="app-sidebar"]')->length)->toBe(1);
    $close = $xp->query('//aside//button[@id="drawer-close"]');
    expect($close->length)->toBe(1);
    expect($close->item(0)->getAttribute('aria-label'))->toBe(__('nav.close_menu'));
});

test('the "back to home" control sits in the sidebar, is a link to the home page, and shows only where there is a home page to go back to', function () {
    // Multi-card account: present once, in the sidebar, a link (not a button), pointing at the home page.
    $xp = htmlXPath($this->actingAs(navUser('auditor'))->get(route('reports.index'))->assertOk()->getContent());
    $text = '[contains(normalize-space(.), "'.__('nav.back_home').'")]';
    $back = $xp->query('//aside//a'.$text);

    expect($back->length)->toBe(1);
    expect($back->item(0)->getAttribute('href'))->toBe(route('dashboard'));
    expect($xp->query('//aside//button'.$text)->length)->toBe(0);
    expect($xp->query('//main//a'.$text)->length)->toBe(0);   // no longer in the page body

    // Single-card account: no back button anywhere (its home is the card).
    $single = userWithPermissions(['requisition.issue'], ['lab_id' => makeLab()->id, 'profile_completed_at' => now()]);
    $singleXp = htmlXPath($this->actingAs($single)->get(route('warehouse.overview'))->assertOk()->getContent());
    expect($singleXp->query('//a'.$text)->length)->toBe(0);
});

test('the home page itself has no sidebar: no <aside>, no drawer button, no "back to home" (it would link to the page you are on)', function () {
    foreach (['auditor', 'admin', 'student'] as $kind) {
        $home = htmlXPath($this->actingAs(navUser($kind))->get(route('dashboard'))->assertOk()->getContent());

        expect($home->query('//aside')->length)->toBe(0, "{$kind}: <aside> on the home page");
        expect($home->query('//*[@id="drawer-open"]')->length)->toBe(0, "{$kind}: drawer button on the home page");
        expect($home->query('//a[contains(normalize-space(.), "'.__('nav.back_home').'")]')->length)->toBe(0, "{$kind}: back button on the home page");
    }
});

test('the old checkbox/peer drawer is gone from every kind of page, and a page inside a card has exactly one sidebar', function () {
    foreach (['student', 'auditor', 'admin'] as $kind) {
        $user = navUser($kind);
        foreach (['items.index', 'requisitions.index', 'notifications.index'] as $routeName) {
            $xp = htmlXPath($this->actingAs($user)->get(route($routeName))->assertOk()->getContent());

            expect($xp->query('//input[@type="checkbox" and contains(@class, "peer")]')->length)->toBe(0, "{$kind} {$routeName}: leftover drawer toggle");
            expect($xp->query('//aside')->length)->toBe(1, "{$kind} {$routeName}: sidebar count");
        }
    }
});

test('the header keeps the bell, "my data" and logout on every page, each with an accessible name', function () {
    foreach (['dashboard', 'items.index', 'warehouse.overview'] as $routeName) {
        $xp = htmlXPath($this->actingAs(navUser('auditor'))->get(route($routeName))->assertOk()->getContent());

        $bell = $xp->query('//header//a[@href="'.route('notifications.index').'"]');
        expect($bell->length)->toBe(1);
        expect($bell->item(0)->getAttribute('aria-label'))->toBe(__('notifications.index_title'));

        expect($xp->query('//header//a[@href="'.route('account.my-data').'"]')->length)->toBe(1);

        $logout = $xp->query('//header//form[@action="'.route('logout').'"]//button');
        expect($logout->length)->toBe(1);
        expect(trim($logout->item(0)->textContent))->toBe(__('nav.logout'));
    }
});

test('the home page has one level-1 heading, and every card link has readable text and decorative-only icons', function () {
    $xp = htmlXPath($this->actingAs(navUser('admin'))->get('/')->assertOk()->getContent());

    expect($xp->query('//main//h1')->length)->toBe(1);

    $cards = $xp->query('//main//a[contains(concat(" ", normalize-space(@class), " "), " rounded-xl ")]');
    expect($cards->length)->toBe(4);
    foreach ($cards as $card) {
        expect(trim((string) $xp->evaluate('string(.//span[contains(@class, "text-base")][1])', $card)))->not->toBe('');
        foreach ($xp->query('.//svg', $card) as $svg) {
            expect($svg->getAttribute('aria-hidden'))->toBe('true');
        }
    }
});

test('the page chrome renders the same landmarks on a Blade page and on a Livewire page', function () {
    // items.create is a plain Blade view wrapped in <x-layout>; requisitions.index is a Livewire full-page component.
    foreach ([route('items.create'), route('requisitions.index'), route('stock-takes.index')] as $url) {
        $xp = htmlXPath($this->actingAs(navUser('auditor'))->get($url)->assertOk()->getContent());

        expect($xp->query('//header')->length)->toBe(1, $url);
        expect($xp->query('//main')->length)->toBe(1, $url);
        expect($xp->query('//nav[@aria-label="'.__('nav.tabs_label').'"]')->length)->toBeLessThanOrEqual(1, $url);
    }
});
