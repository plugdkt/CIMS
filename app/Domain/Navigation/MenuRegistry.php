<?php

declare(strict_types=1);

namespace App\Domain\Navigation;

use App\Domain\Reporting\Services\DashboardService;
use App\Domain\Shared\ItemType;
use App\Models\Container;
use App\Models\Disposal;
use App\Models\ImsLot;
use App\Models\ImsReceipt;
use App\Models\Item;
use App\Models\Lab;
use App\Models\Loan;
use App\Models\LoanAsset;
use App\Models\Location;
use App\Models\Requisition;
use App\Models\StockLedger;
use App\Models\StockTake;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * ITM-2610-052 phase 1: the single place that says which home-page card exists, which tabs
 * sit inside it, and who may see them. The home page and the tab bar on every page read from
 * here, so a card and its menu can never disagree.
 *
 * Nothing in here grants access: every tab is gated by the same Policy/permission the page
 * itself enforces (hiding a link is not protection; each page still authorizes itself). No new
 * permission is introduced: `access-warehouse` and `access-backoffice` (AppServiceProvider)
 * are derived from existing abilities.
 *
 * @phpstan-type Tab array{label: string, url: string, routes: list<string>, query: array<string, string>, extra: list<string>}
 * @phpstan-type Badge array{tone: string, text: string}
 * @phpstan-type Card array{key: string, title: string, desc: string, url: string, tone: string, badges: list<Badge>}
 */
final class MenuRegistry
{
    public const CHEMICAL = 'chem';

    public const CONSUMABLE = 'supply';

    public const OPS = 'ops';

    public const ADMIN = 'adm';

    /** ITM-2610-051: the borrow / return card. */
    public const LOAN = 'loan';

    /** Order matters: when a route belongs to several sections the first visible one wins. */
    private const PRIORITY = [self::OPS, self::ADMIN, self::LOAN, self::CHEMICAL, self::CONSUMABLE];

    /** @var array<string, list<Tab>> menus already built during this request */
    private array $tabCache = [];

    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    /** Any ability that belongs to the warehouse side of the house (managers, dispensers, ADMIN). */
    public function canAccessWarehouse(User $user): bool
    {
        return $user->can('manage', Requisition::class)
            || $user->can('viewAny', StockTake::class)
            || $user->can('viewAny', Disposal::class)
            || $user->can('viewAdjustments', StockLedger::class)
            || $user->can('viewAny', ImsReceipt::class)
            || $user->can('manageMembers', Lab::class)
            || $user->can('viewAny', Location::class)
            || $user->can('report.view')
            || $user->can('item.manage');
    }

    public function canAccessBackoffice(User $user): bool
    {
        return $user->can('viewAny', User::class) || $user->can('viewAny', Lab::class);
    }

    /**
     * @return list<Card>
     */
    public function cardsFor(User $user, bool $withBadges = true): array
    {
        $cards = [];

        if ($user->can('create', Requisition::class)) {
            $cards[] = $this->card($user, self::CHEMICAL, $withBadges);
            $cards[] = $this->card($user, self::CONSUMABLE, $withBadges);
        }

        // ITM-2610-051: shown only when the user has at least one menu in it (card with no tab is dropped below).
        $cards[] = $this->card($user, self::LOAN, $withBadges);

        if ($this->canAccessWarehouse($user)) {
            $cards[] = $this->card($user, self::OPS, $withBadges);
        }

        if ($this->canAccessBackoffice($user)) {
            $cards[] = $this->card($user, self::ADMIN, $withBadges);
        }

        return array_values(array_filter($cards, fn (array $c) => $c['url'] !== ''));
    }

    /**
     * What the layout needs for the current page: which section (if any) the page belongs to,
     * its tabs with the active one marked, whether to show the back-to-home button, and where
     * the logo should lead.
     *
     * @return array{section: string|null, title: string, tabs: list<array{label: string, url: string, active: bool}>, showBack: bool, homeUrl: string}
     */
    public function contextFor(User $user, Request $request): array
    {
        $cards = $this->cardsFor($user, false);
        $visible = array_column($cards, 'key');
        $multi = count($cards) > 1;
        $homeUrl = $multi || $cards === [] ? route('dashboard') : $cards[0]['url'];

        $none = ['section' => null, 'title' => '', 'tabs' => [], 'showBack' => $multi, 'homeUrl' => $homeUrl];
        $routeName = $request->route()?->getName();
        if ($routeName === 'dashboard') {
            // The home page has no sidebar and so nothing to go "back" to.
            return array_merge($none, ['showBack' => false]);
        }
        if ($routeName === null || $visible === []) {
            return $none;
        }

        $type = $this->typeForRequest($request);

        // B1: a manager opening someone else's requisition (from the queue) stays in the warehouse card.
        $requisition = $request->route('requisition');
        $managing = in_array($routeName, ['requisitions.show', 'requisitions.pdf'], true)
            && $requisition instanceof Requisition
            && $requisition->requester_id !== $user->id
            && $user->can('manage', Requisition::class);

        foreach (self::PRIORITY as $section) {
            if (! in_array($section, $visible, true)) {
                continue;
            }
            // The two requisition cards share routes; only the one for this page's kind is a candidate.
            if ($section === self::CHEMICAL && $type !== ItemType::CHEMICAL) {
                continue;
            }
            if ($section === self::CONSUMABLE && $type !== ItemType::CONSUMABLE) {
                continue;
            }

            // An approved / issued requisition belongs under "to issue" for someone who dispenses, not "waiting".
            $toIssue = $managing
                && in_array($requisition->status, ['APPROVED', 'PARTIALLY_ISSUED', 'ISSUED'], true)
                && $user->can('requisition.issue');

            $tabs = $this->tabsFor($user, $section, $managing, $toIssue);
            if (! $this->anyRouteMatches($tabs, $routeName)) {
                continue;
            }

            $marked = [];
            $matched = false;
            foreach ($tabs as $tab) {
                $active = ! $matched && $this->tabMatches($tab, $routeName, $request);
                $matched = $matched || $active;
                $marked[] = ['label' => $tab['label'], 'url' => $tab['url'], 'active' => $active];
            }

            return [
                'section' => $section,
                'title' => (string) __('nav.card_'.$section),
                'tabs' => $marked,
                'showBack' => $multi,
                'homeUrl' => $homeUrl,
            ];
        }

        return $none;
    }

    /**
     * @return list<Tab>
     */
    public function tabsFor(User $user, string $section, bool $managingRequisition = false, bool $managingToIssue = false): array
    {
        $key = $user->id.'|'.$section.'|'.($managingRequisition ? '1' : '0').'|'.($managingToIssue ? '1' : '0');

        return $this->tabCache[$key] ??= $this->buildTabs($user, $section, $managingRequisition, $managingToIssue);
    }

    /**
     * @return list<Tab>
     */
    private function buildTabs(User $user, string $section, bool $managingRequisition, bool $managingToIssue = false): array
    {
        // A section the user has no card for has no menu, whatever single abilities they hold.
        if ($section === self::OPS && ! $this->canAccessWarehouse($user)) {
            return [];
        }
        if ($section === self::ADMIN && ! $this->canAccessBackoffice($user)) {
            return [];
        }

        return match ($section) {
            self::CHEMICAL => $this->requesterTabs($user, ItemType::CHEMICAL),
            self::CONSUMABLE => $this->requesterTabs($user, ItemType::CONSUMABLE),
            self::OPS => $this->opsTabs($user, $managingRequisition, $managingToIssue),
            self::ADMIN => $this->adminTabs($user),
            self::LOAN => $this->loanTabs($user),
            default => [],
        };
    }

    /**
     * @return Card
     */
    private function card(User $user, string $section, bool $withBadges): array
    {
        $tabs = $this->tabsFor($user, $section);

        return [
            'key' => $section,
            'title' => (string) __('nav.card_'.$section),
            'desc' => (string) __('nav.card_'.$section.'_desc'),
            'url' => $tabs[0]['url'] ?? '',
            'tone' => match ($section) {
                self::OPS => 'ops',
                self::ADMIN => 'adm',
                default => 'main',
            },
            'badges' => $withBadges ? $this->badges($user, $section) : [],
        ];
    }

    /**
     * @return list<Badge>
     */
    private function badges(User $user, string $section): array
    {
        $badges = [];

        if ($section === self::CHEMICAL || $section === self::CONSUMABLE) {
            $type = $section === self::CHEMICAL ? ItemType::CHEMICAL : ItemType::CONSUMABLE;
            $mine = $this->dashboard->myOpenRequisitionsCount($user, $type);
            $badges[] = ['tone' => 'n', 'text' => (string) __('nav.badge_my_open', ['count' => $mine])];
            if ($user->can('requisition.approve_advisor')) {
                $waiting = $this->dashboard->adviseeWaitingCount($user, $type);
                if ($waiting > 0) {
                    $badges[] = ['tone' => 'w', 'text' => (string) __('nav.badge_advisee_waiting', ['count' => $waiting])];
                }
            }
            // Phase 2: one basket per kind, so each card shows its own basket's size.
            $cart = count((array) session(ItemType::cartKey($type), []));
            if ($cart > 0 && $user->can('create', Requisition::class)) {
                $badges[] = ['tone' => 'p', 'text' => (string) __('nav.badge_cart', ['count' => $cart])];
            }
        }

        if ($section === self::OPS) {
            // Phase 3: which kinds of stock this manager is responsible for (none = deny by default).
            if ($user->isBranchManager()) {
                $managed = $user->managedItemTypes();
                $badges[] = $managed === []
                    ? ['tone' => 'w', 'text' => (string) __('nav.badge_no_types')]
                    : ['tone' => 'n', 'text' => (string) __('nav.badge_managed_types', [
                        'types' => implode(' + ', array_map(fn (string $t) => (string) __('requisitions.request_type_'.$t), $managed)),
                    ])];
            }

            $approval = $this->dashboard->approvalQueueCount($user);
            if ($approval !== null) {
                $badges[] = ['tone' => 'w', 'text' => (string) __('nav.badge_approval', ['count' => $approval])];
            }
            $dispense = $this->dashboard->dispenseQueueCount($user);
            if ($dispense !== null) {
                $badges[] = ['tone' => 'p', 'text' => (string) __('nav.badge_dispense', ['count' => $dispense])];
            }
        }

        return $badges;
    }

    /**
     * @return list<Tab>
     */
    private function requesterTabs(User $user, string $type): array
    {
        $tabs = [];

        if ($user->can('create', Requisition::class)) {
            $tabs[] = $this->tab((string) __('nav.tab_shop'), route('shop.index', ['type' => $type]), ['shop.index', 'shop.show']);
            $tabs[] = $this->tab((string) __('nav.tab_cart'), route('shop.cart', ['type' => $type]), ['shop.cart']);
        }
        if ($user->can('viewAny', Requisition::class)) {
            $tabs[] = $this->tab((string) __('nav.tab_my_requisitions'), route('requisitions.index', ['type' => $type]), [
                'requisitions.index', 'requisitions.show', 'requisitions.create', 'requisitions.pdf',
            ]);
        }
        if ($type === ItemType::CHEMICAL && $user->can('item.view')) {
            $tabs[] = $this->tab((string) __('nav.chemical_lookup'), route('chemicals.lookup'), ['chemicals.lookup']);
        }
        if ($user->can('viewAny', Container::class) && ! $this->ownsOpsStockPages($user)) {
            $tabs[] = $this->tab((string) __('nav.tab_working_stock'), route('stock-in.index'), ['stock-in.*']);
        }
        // Read-only browsing of the branch's central store, for people without the manager's IMS access.
        if ($user->can('viewAny', ImsLot::class) && ! $user->can('ims.view')) {
            $tabs[] = $this->tab((string) __('nav.tab_ims_browse'), route('ims.lots.index'), ['ims.*']);
        }
        if ($user->can('viewAny', Item::class) && ! $user->can('item.manage') && ! $this->canAccessBackoffice($user)) {
            $tabs[] = $this->tab((string) __('nav.tab_items_view'), route('items.index'), ['items.*']);
        }

        return $tabs;
    }

    /**
     * @return list<Tab>
     */
    private function opsTabs(User $user, bool $managingRequisition = false, bool $managingToIssue = false): array
    {
        // A manager opening a requisition from the queue stays inside the warehouse card.
        $requisitionPages = $managingRequisition ? ['requisitions.show', 'requisitions.pdf'] : [];
        // ...under "waiting", or under "to issue" when it is already approved and the viewer dispenses.
        $waitingPages = $managingToIssue ? [] : $requisitionPages;
        $issuePages = $managingToIssue || ! ($user->can('requisition.view_all') || $user->can('requisition.approve_scientist')) ? $requisitionPages : [];

        $tabs = [$this->tab((string) __('nav.tab_overview'), route('warehouse.overview'), ['warehouse.overview'])];

        // approvals.md K (2026-11-04): scientists approve, so they get the "waiting" tab too.
        $seesWaiting = $user->can('requisition.view_all') || $user->can('requisition.approve_scientist');
        if ($seesWaiting) {
            $tabs[] = $this->tab((string) __('nav.tab_waiting'), route('requisitions.manage', ['tab' => 'waiting']), ['requisitions.manage'], ['tab' => 'waiting'], $waitingPages);
        }
        if ($user->can('manage', Requisition::class)) {
            $tabs[] = $this->tab(
                (string) __('nav.tab_to_issue'),
                route('requisitions.manage', ['tab' => 'to_issue']),
                ['requisitions.manage'],
                ['tab' => 'to_issue'],
                ['requisitions.issue.*', ...$issuePages],
            );
        }
        if ($user->can('viewAny', StockTake::class)) {
            $tabs[] = $this->tab((string) __('nav.stock_takes'), route('stock-takes.index'), ['stock-takes.*']);
        }
        if ($user->can('viewAny', Disposal::class)) {
            $tabs[] = $this->tab((string) __('nav.disposals'), route('disposals.index'), ['disposals.*']);
        }
        if ($user->can('viewAdjustments', StockLedger::class)) {
            $tabs[] = $this->tab((string) __('nav.adjustments'), route('adjustments.index'), ['adjustments.*']);
        }
        if ($user->can('viewAny', ImsReceipt::class)) {
            $tabs[] = $this->tab((string) __('nav.tab_ims'), route('ims.lots.index'), ['ims.receipts.*', 'ims.lots.*', 'ims.places.*']);
        }
        if ($this->ownsOpsStockPages($user) && $user->can('viewAny', Container::class)) {
            $tabs[] = $this->tab((string) __('nav.tab_working_stock_ops'), route('stock-in.index'), ['stock-in.*']);
        }
        if ($user->can('item.manage')) {
            $tabs[] = $this->tab((string) __('nav.tab_items'), route('items.index'), ['items.*']);
        }
        if ($user->can('viewAny', Location::class)) {
            $tabs[] = $this->tab((string) __('nav.locations'), route('locations.index'), ['locations.*']);
        }
        if ($user->can('manageMembers', Lab::class)) {
            $tabs[] = $this->tab((string) __('nav.lab_members'), route('labs.members'), ['labs.members']);
        }
        // ADMIN reaches the reports from the back-office card instead (every branch).
        if ($user->can('report.view') && ! $this->canAccessBackoffice($user)) {
            $tabs[] = $this->tab((string) __('nav.reports'), route('reports.index'), ['reports.*']);
        }

        return $tabs;
    }

    /**
     * ITM-2610-051 steps 1-2: the borrower's pages (mine, request), the approval queue and the branch list, then the
     * lending register and the availability page; hand-out and returns join in later steps. Each tab is gated by the
     * same LoanPolicy / LoanAssetPolicy ability the page enforces. The "no branch yet" page belongs to the first tab.
     *
     * @return list<Tab>
     */
    private function loanTabs(User $user): array
    {
        $tabs = [];

        if ($user->can('browseOwn', Loan::class)) {
            $tabs[] = $this->tab((string) __('nav.tab_loan_mine'), route('loans.mine'), ['loans.mine', 'loans.show', 'loans.edit', 'loans.pending-lab']);
        }
        if ($user->can('createLoan', Loan::class)) {
            $tabs[] = $this->tab((string) __('nav.tab_loan_new'), route('loans.create'), ['loans.create']);
        }
        if ($user->can('browseQueue', Loan::class)) {
            $tabs[] = $this->tab((string) __('nav.tab_loan_queue'), route('loans.queue'), ['loans.queue']);
        }
        if ($user->can('browseBranch', Loan::class)) {
            $tabs[] = $this->tab((string) __('nav.tab_loan_branch'), route('loans.branch'), ['loans.branch']);
        }
        if ($user->can('browseAvailable', LoanAsset::class)) {
            $tabs[] = $this->tab((string) __('nav.tab_loan_available'), route('loans.available'), ['loans.available']);
        }
        if ($user->can('browseRegistry', LoanAsset::class)) {
            $tabs[] = $this->tab((string) __('nav.tab_loan_registry'), route('loans.assets.index'), ['loans.assets.*']);
        }

        return $tabs;
    }

    /**
     * @return list<Tab>
     */
    private function adminTabs(User $user): array
    {
        $tabs = [];

        if ($user->can('viewAny', User::class)) {
            $tabs[] = $this->tab((string) __('nav.users_roles'), route('admin.users.index'), ['admin.users.*']);
        }
        if ($user->can('viewAny', Lab::class)) {
            $tabs[] = $this->tab((string) __('nav.labs'), route('admin.labs.index'), ['admin.labs.*']);
        }
        if ($user->can('viewAny', Item::class)) {
            $tabs[] = $this->tab((string) __('nav.tab_items'), route('items.index'), ['items.*']);
        }
        if ($user->can('report.view')) {
            $tabs[] = $this->tab((string) __('nav.reports'), route('reports.index'), ['reports.*']);
        }

        return $tabs;
    }

    /** Working-stock pages sit in the warehouse card only for those who run the stock (not for a plain dispenser). */
    private function ownsOpsStockPages(User $user): bool
    {
        return $user->can('ims.manage') || $user->can('stocktake.manage');
    }

    /**
     * @param  list<string>  $routes
     * @param  array<string, string>  $query  query values the page must carry for the tab to be marked
     * @param  list<string>  $extra  further routes that belong to this tab and are marked without any query check
     * @return Tab
     */
    private function tab(string $label, string $url, array $routes, array $query = [], array $extra = []): array
    {
        return ['label' => $label, 'url' => $url, 'routes' => $routes, 'query' => $query, 'extra' => $extra];
    }

    /** @param list<Tab> $tabs */
    private function anyRouteMatches(array $tabs, string $routeName): bool
    {
        foreach ($tabs as $tab) {
            foreach ([...$tab['routes'], ...$tab['extra']] as $pattern) {
                if (Str::is($pattern, $routeName)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param Tab $tab */
    private function tabMatches(array $tab, string $routeName, Request $request): bool
    {
        foreach ($tab['extra'] as $pattern) {
            if (Str::is($pattern, $routeName)) {
                return true;
            }
        }

        $routeHit = false;
        foreach ($tab['routes'] as $pattern) {
            if (Str::is($pattern, $routeName)) {
                $routeHit = true;
                break;
            }
        }
        if (! $routeHit) {
            return false;
        }

        // A page that carries no (or another) value for the tab's query part marks nothing:
        // e.g. requisitions.manage?tab=all is not "waiting" or "to issue".
        foreach ($tab['query'] as $key => $value) {
            if ($request->query($key) !== $value) {
                return false;
            }
        }

        return true;
    }

    /** Which kind of stock this request is about, deciding between the two requisition cards. */
    private function typeForRequest(Request $request): string
    {
        $query = $request->query('type');
        if (is_string($query) && ItemType::isRequisitionKind($query)) {
            return $query;
        }

        $item = $request->route('item');
        if ($item instanceof Item) {
            // A science (loan) item has no requisition card of its own: it counts with the consumable one here.
            $kind = ItemType::forItem($item);

            return ItemType::isRequisitionKind($kind) ? $kind : ItemType::CONSUMABLE;
        }

        $requisition = $request->route('requisition');
        if ($requisition instanceof Requisition) {
            // Judged by the lines' real categories, not the box the requester ticked; a legacy
            // mixed requisition (any chemical line) stays with the chemical card.
            $hasChemicalLine = $requisition->items()
                ->whereHas('item.category', fn ($c) => \App\Models\ItemCategory::constrainKind($c, ItemType::CHEMICAL))
                ->exists();
            if ($hasChemicalLine) {
                return ItemType::CHEMICAL;
            }
            if ($requisition->items()->exists()) {
                return ItemType::CONSUMABLE;
            }

            // No lines yet (fresh draft): fall back to what was ticked.
            $types = $requisition->request_type;

            return in_array('CHEMICAL', $types, true) || $types === []
                ? ItemType::CHEMICAL
                : ItemType::CONSUMABLE;
        }

        return ItemType::CHEMICAL;
    }
}
