<?php

declare(strict_types=1);

use App\Enums\CustomFields\OpportunityField;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\User;
use App\Models\Workspace;
use App\Onboarding\CustomFieldRow;
use Relaticle\CustomFields\Facades\Entities;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(OpportunitiesBoard::class);

/**
 * The ladder the operations workspace declares, built the way the seeder builds
 * it. Five rungs, so the board has five lanes and the last one only exists off
 * the right edge of a laptop window.
 */
function boardLaneOverflowLadder(User $user): CustomField
{
    /** @var Workspace $workspace */
    $workspace = $user->currentWorkspace;

    TenantContextService::setTenantId($workspace->getKey());

    $rows = CustomFieldRow::build(
        (string) $workspace->getKey(),
        Entities::getEntity(Opportunity::class)?->getAlias() ?? Opportunity::class,
        OpportunityField::KOC_STAGE,
        now()->toImmutable(),
    );

    CustomField::query()->insert($rows['field']);

    if ($rows['options'] !== []) {
        CustomFieldOption::query()->insert($rows['options']);
    }

    $field = CustomField::query()
        ->withoutGlobalScopes()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::KOC_STAGE->value)
        ->where('tenant_id', $workspace->getKey())
        ->firstOrFail();

    // A rung with a collaboration on it, so the page renders the ladder rather
    // than the "nothing here yet" block.
    $opportunity = Opportunity::factory()->recycle([$user, $workspace])->create();
    $opportunity->saveCustomFieldValue($field, $field->options->first()->getKey());

    TenantContextService::setTenantId(null);

    return $field;
}

/**
 * Finds the lane row by structure, then walks it with the page's own paging
 * control and reports what an operator could actually reach. A headless browser
 * hides native scrollbars, so the control is the only evidence there is: this
 * probe never measures a scrollbar the harness cannot paint.
 */
const BOARD_LANE_OVERFLOW_PROBE = <<<'JS'
    (async () => {
        const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
        const lane = document.querySelector('.flowforge-column');

        if (!lane) {
            return { error: 'the board rendered no lane' };
        }

        const row = lane.parentElement;
        const lanes = Array.from(row.children).filter((child) => child.classList.contains('flowforge-column'));
        const forward = () => document.querySelector('[data-lane-row-next]');
        const back = () => document.querySelector('[data-lane-row-back]');
        const visible = (element) => element instanceof HTMLElement && element.offsetParent !== null;

        const measured = {
            lanes: lanes.length,
            laneRowScrolls: row.scrollWidth > row.clientWidth,
            forwardBeforePaging: visible(forward()),
            backBeforePaging: visible(back()),
            pages: 0,
            moved: 0,
            lastLaneReachable: false,
            forwardAfterPaging: false,
            backAfterPaging: false,
            documentOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };

        while (measured.pages < 12 && visible(forward())) {
            const before = row.scrollLeft;

            forward().click();
            measured.pages++;

            await sleep(80);

            if (row.scrollLeft > before) {
                measured.moved++;
            }
        }

        const last = lanes[lanes.length - 1].getBoundingClientRect();
        measured.lastLaneReachable = last.left >= -0.5 && last.right <= window.innerWidth + 0.5;
        measured.forwardAfterPaging = visible(forward());
        measured.backAfterPaging = visible(back());

        return measured;
    })();
    JS;

it('keeps every ladder lane reachable, and says so while one is out of view', function (int $width): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    boardLaneOverflowLadder($user);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize($width, 900)
        ->navigate("/app/{$workspace->slug}/opportunities/board")
        ->assertVisible('.flowforge-column >> nth=0')
        ->assertNoJavaScriptErrors();

    $measured = $page->script(BOARD_LANE_OVERFLOW_PROBE);

    expect($measured['lanes'])->toBe(5)
        ->and($measured['laneRowScrolls'])->toBeTrue()
        // While lanes sit past the edge the board offers a way to them, and says
        // nothing about lanes behind the start it is not hiding.
        ->and($measured['forwardBeforePaging'])->toBeTrue()
        ->and($measured['backBeforePaging'])->toBeFalse()
        // Pressing it actually moves the row, one lane at a time.
        ->and($measured['moved'])->toBeGreaterThan(0)
        // The fifth rung can be brought fully into view, so a record dragged to
        // it neither vanishes nor needs a gesture nobody was told about.
        ->and($measured['lastLaneReachable'])->toBeTrue()
        // At the end the control stops promising lanes that are not there, and
        // the way back is offered instead.
        ->and($measured['forwardAfterPaging'])->toBeFalse()
        ->and($measured['backAfterPaging'])->toBeTrue()
        // The overflow belongs to the lane row, never to the page.
        ->and($measured['documentOverflow'])->toBe(0);
})->with([1440, 1600]);
