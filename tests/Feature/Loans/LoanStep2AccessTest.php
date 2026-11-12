<?php

use App\Domain\Navigation\MenuRegistry;
use App\Models\Loan;
use App\Models\Permission;
use App\Policies\LoanPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/*
 * ITM-2610-051 step 2: access to the new pages by URL (hiding a tab is not protection), the menu each role gets, and
 * the one policy trap: AppServiceProvider's Gate::before returns TRUE when the ability NAME equals a permission code
 * the user holds, which would skip every branch check. So LoanPolicy methods are named createLoan / viewLoan /
 * managerDecideLoan ... and nothing may call can('loan.approve', $loan).
 *
 * (Written without being run: see the report to the secretary.)
 */

// --- the policy trap -------------------------------------------------------------------------------------------

test('no LoanPolicy method is named like a permission code (Gate::before would then skip the branch checks)', function () {
    $codes = Permission::pluck('code')->all();
    $methods = array_map('strtolower', get_class_methods(LoanPolicy::class));

    foreach ($codes as $code) {
        expect($methods)->not->toContain(strtolower($code));
        expect($methods)->not->toContain(strtolower(str_replace('.', '_', $code)));
    }
});

test('why the names differ: a scientist of ANOTHER branch passes can(\'loan.approve\', $loan) through Gate::before, and is refused by the real abilities', function () {
    [$lab, $asset] = loanBranch();
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 1);
    $foreign = scientistUser(['lab_id' => makeLab()->id]);

    // Pins today's Gate::before behaviour (the reason for the naming rule). If this line ever fails, re-read LoanPolicy's header.
    expect($foreign->can('loan.approve', $loan))->toBeTrue();

    foreach (['viewLoan', 'managerDecideLoan', 'cancelLoan', 'updateLoan', 'submitLoan', 'advisorDecideLoan'] as $ability) {
        expect($foreign->can($ability, $loan))->toBeFalse($ability);
    }
});

test('no code in the app asks the Gate with a loan.* permission code AND a model (that would bypass the branch checks)', function () {
    $hits = [];
    foreach ([app_path(), resource_path('views'), base_path('routes')] as $dir) {
        foreach (File::allFiles($dir) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $text = (string) file_get_contents($file->getPathname());
            $found = preg_match('/(?:can|cannot|authorize|allows|denies|inspect)\(\s*[\'"]loan\.[a-z_]+[\'"]\s*,/', $text) === 1
                || preg_match('/@(?:can|cannot)\(\s*[\'"]loan\.[a-z_]+[\'"]\s*,/', $text) === 1
                || preg_match('/[\'"]can:loan\.[a-z_]+,/', $text) === 1;
            if ($found) {
                $hits[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }
    }

    expect($hits)->toBe([]);
});

// --- the pages by URL ----------------------------------------------------------------------------------------------

dataset('loan step 2 pages', function () {
    $open = 200;
    $deny = 403;

    // route name => [student, scientist, auditor (= LAB_MANAGER), admin, nocard]; every user has a branch and a complete profile
    $matrix = [
        'loans.mine' => [$open, $open, $open, $open, $deny],
        'loans.create' => [$open, $open, $open, $open, $deny],
        'loans.queue' => [$deny, $open, $open, $deny, $deny],
        'loans.branch' => [$deny, $deny, $open, $open, $deny],
    ];

    foreach ($matrix as $routeName => $answers) {
        foreach (['student', 'scientist', 'auditor', 'admin', 'nocard'] as $i => $kind) {
            yield "{$routeName} as {$kind}" => [$routeName, $kind, $answers[$i]];
        }
    }
});

test('every step-2 page answers each role exactly as designed, asked directly by URL', function (string $routeName, string $kind, int $expected) {
    $user = navUser($kind, ['person_type' => $kind === 'student' ? 'STUDENT' : 'STAFF']);

    $this->actingAs($user)->get(route($routeName))->assertStatus($expected);
})->with('loan step 2 pages');

test('every step-2 page sends a guest to the login page, and so do the document routes', function () {
    foreach (['loans.mine', 'loans.create', 'loans.queue', 'loans.branch', 'loans.pending-lab'] as $name) {
        $this->get(route($name))->assertRedirect(route('login'));
    }

    [$lab, $asset] = loanBranch();
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 1);
    $this->get(route('loans.show', $loan))->assertRedirect(route('login'));
    $this->get(route('loans.edit', $loan))->assertRedirect(route('login'));
    $this->post(route('loans.store'), loanPayload([$asset->ulid => '1']))->assertRedirect(route('login'));
    $this->post(route('loans.cancel', $loan))->assertRedirect(route('login'));
    $this->post(route('loans.manager-decide', $loan), ['decision' => 'APPROVE'])->assertRedirect(route('login'));
    $this->post(route('loans.advisor-decide', $loan), ['decision' => 'APPROVE'])->assertRedirect(route('login'));
    expect($loan->fresh()->status)->toBe('SUBMITTED');
});

test('the document routes answer a user who holds no loan permission at all with 403 (and create nothing)', function () {
    [$lab, $asset] = loanBranch();
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 1);
    $nocard = navUser('nocard', ['lab_id' => $lab->id]);

    $this->actingAs($nocard)->get(route('loans.show', $loan))->assertForbidden();
    $this->actingAs($nocard)->post(route('loans.store'), loanPayload([$asset->ulid => '1']))->assertForbidden();
    $this->actingAs($nocard)->post(route('loans.cancel', $loan), ['reason' => 'ทดสอบสิทธิ์'])->assertForbidden();
    expect(Loan::count())->toBe(1);
});

// --- the menu -------------------------------------------------------------------------------------------------------

test('the loan card has exactly the tabs the user may open (steps 2-5): borrowers, advisors, approvers / counter staff, managers, ADMIN', function () {
    $tabs = fn ($user) => array_column(app(MenuRegistry::class)->tabsFor($user, MenuRegistry::LOAN), 'label');
    $l = fn (string ...$keys) => array_map(fn (string $k) => (string) __('nav.tab_loan_'.$k), $keys);

    foreach (['student', 'staff'] as $kind) {
        expect($tabs(navUser($kind)))->toBe($l('mine', 'new'), $kind);
    }
    expect($tabs(navUser('advisor')))->toBe($l('mine', 'new', 'queue'));
    // Step 3: "hand-out / return" (`desk`) is for holders of loan.view_queue who have a branch: the scientists and the
    // warehouse managers (who work the counter), and an ADMIN who has a branch (who only reads it: the page shows a
    // "view only" note and no buttons, and LoanAccess::isHandlerFor refuses an ADMIN-only account).
    expect($tabs(navUser('scientist')))->toBe($l('mine', 'new', 'queue', 'desk', 'available'));
    foreach (['lab_manager', 'auditor'] as $kind) {
        expect($tabs(navUser($kind)))->toBe($l('mine', 'new', 'queue', 'desk', 'branch', 'available', 'registry', 'reports'), $kind);
    }
    // Step 5: "reports" (`loan.report`) comes last, for the warehouse managers and ADMIN only.
    expect($tabs(navUser('admin')))->toBe($l('mine', 'new', 'desk', 'branch', 'available', 'registry', 'reports'));
    expect($tabs(navUser('nocard')))->toBe([]);
    // Borrower roles never get the counter tab, whatever else they hold.
    foreach (['student', 'staff', 'advisor'] as $kind) {
        expect($tabs(navUser($kind)))->not->toContain((string) __('nav.tab_loan_desk'), $kind);
    }

    // No branch: borrowing needs one, so the approver / counter / branch tabs go; ADMIN needs none for the lists
    // (but gets no counter tab without a branch: the counter is always one branch's).
    expect($tabs(navUser('scientist', ['lab_id' => null])))->toBe($l('mine', 'new'));
    // The reports tab stays for a manager with no branch: LoanPolicy::browseReports needs only `loan.report`; the page
    // then shows empty figures (LoanReportScope), which LoanReportTest pins.
    expect($tabs(navUser('auditor', ['lab_id' => null])))->toBe($l('mine', 'new', 'reports'));
    expect($tabs(navUser('admin', ['lab_id' => null])))->toBe($l('mine', 'new', 'branch', 'available', 'registry', 'reports'));
    // Nobody else holds `loan.report`.
    foreach (['student', 'staff', 'advisor', 'scientist'] as $kind) {
        expect($tabs(navUser($kind)))->not->toContain((string) __('nav.tab_loan_reports'), $kind);
    }
});

test('every role that holds loan.view_own has the loan card on the home page and its first tab is "things I borrowed"', function () {
    foreach (['student', 'staff', 'advisor', 'scientist', 'auditor', 'lab_manager', 'admin'] as $kind) {
        $cards = collect(homeCards($this->actingAs(navUser($kind))->get('/')->assertOk()->getContent()))->keyBy('title');

        expect($cards->has(__('nav.card_loan')))->toBeTrue($kind);
        expect($cards[__('nav.card_loan')]['href'])->toBe(route('loans.mine'), $kind);
    }
    expect(array_column(homeCards($this->actingAs(navUser('nocard'))->get('/')->assertOk()->getContent()), 'title'))->not->toContain(__('nav.card_loan'));
});

test('each step-2 page opens inside the loan card with its own tab marked, once', function () {
    $manager = navUser('auditor', ['person_type' => 'STAFF']);
    $draft = draftLoan($manager->fresh(), loanExtraAsset(\App\Models\Lab::findOrFail($manager->lab_id), 3), 1);

    foreach ([
        [route('loans.mine'), 'tab_loan_mine'],
        [route('loans.show', $draft), 'tab_loan_mine'],
        [route('loans.edit', $draft), 'tab_loan_mine'],
        [route('loans.create'), 'tab_loan_new'],
        [route('loans.queue'), 'tab_loan_queue'],
        [route('loans.branch'), 'tab_loan_branch'],
    ] as [$url, $activeKey]) {
        $nav = layoutNav($this->actingAs($manager)->get($url)->assertOk()->getContent());

        expect($nav['title'])->toBe(__('nav.card_loan'), $url);
        $active = array_values(array_filter($nav['tabs'], fn ($t) => $t['active']));
        expect($active)->toHaveCount(1);
        expect($active[0]['label'])->toBe(__('nav.'.$activeKey), $url);
    }
});

test('the "waiting for a branch" page sits in the loan card under "things I borrowed"', function () {
    $user = navUser('staff', ['lab_id' => null]);

    $nav = layoutNav($this->actingAs($user)->get(route('loans.pending-lab'))->assertOk()->getContent());

    expect($nav['title'])->toBe(__('nav.card_loan'));
    $active = array_values(array_filter($nav['tabs'], fn ($t) => $t['active']));
    expect($active)->toHaveCount(1)->and($active[0]['label'])->toBe(__('nav.tab_loan_mine'));
});

// --- accessibility of the new pages ----------------------------------------------------------------------------------

test('every step-2 page has a matching id for each label, and scrollable tables are keyboard-focusable', function () {
    [$lab, $asset] = loanBranch();
    $manager = auditorUser(['lab_id' => $lab->id, 'person_type' => 'STAFF']);
    $loan = submittedLoan(staffUser(['lab_id' => $lab->id]), $asset, 1);

    $pages = [
        route('loans.mine'), route('loans.create'), route('loans.queue'), route('loans.branch'), route('loans.show', $loan),
    ];
    foreach ($pages as $url) {
        $xp = htmlXPath($this->actingAs($manager)->get($url)->assertOk()->getContent());

        foreach ($xp->query('//label[@for]') as $label) {
            $target = $label->getAttribute('for');
            expect($xp->query('//*[@id="'.$target.'"]')->length)->toBeGreaterThanOrEqual(1, "{$url}: label for={$target} has no target");
        }
        foreach ($xp->query('//*[contains(@class, "overflow-x-auto")]') as $scroller) {
            expect($scroller->hasAttribute('tabindex'))->toBeTrue("{$url}: scrollable region without tabindex");
        }
    }
});
