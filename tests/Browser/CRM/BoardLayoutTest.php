<?php

declare(strict_types=1);

use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\TaskField;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Filament\Resources\TaskResource\Pages\TasksBoard;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Onboarding\CustomFieldRow;
use Relaticle\CustomFields\Facades\Entities;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(TasksBoard::class, OpportunitiesBoard::class);

it('scrolls a long column inside the board without scrolling the page', function (string $model, string $columnFieldCode, string $dateFieldCode, string $path): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $columnField = CustomField::query()->forEntity($model)->where('code', $columnFieldCode)->firstOrFail();
    $dateField = CustomField::query()->forEntity($model)->where('code', $dateFieldCode)->firstOrFail();
    $firstColumn = $columnField->options->first();

    $records = $model::factory()->recycle([$user, $workspace])->count(20)->create();

    $records->each(function (Task|Opportunity $record) use ($columnField, $firstColumn, $dateField): void {
        $record->saveCustomFieldValue($columnField, $firstColumn->getKey());
        $record->saveCustomFieldValue($dateField, now()->addWeek());
    });

    TenantContextService::setTenantId(null);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/{$path}")
        ->assertVisible("[data-card-id=\"{$records->first()->getKey()}\"]")
        ->assertNoJavaScriptErrors();

    $overflow = <<<'JS'
        (() => {
            const layout = document.querySelector('.fi-layout');
            const column = document.querySelector('.flowforge-column-content');

            return {
                pageScrolls: layout.scrollHeight > layout.clientHeight,
                columnScrolls: column.scrollHeight > column.clientHeight,
            };
        })();
    JS;

    expect($page->script($overflow))->toBe(['pageScrolls' => false, 'columnScrolls' => true]);
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value, TaskField::DUE_DATE->value, 'tasks/board'],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, OpportunityField::CLOSE_DATE->value, 'opportunities/board'],
]);

/**
 * The board page carries a heading of its own, under the topbar title it shares with
 * the list, and its caption sits on the line beneath it. Measured on the rendered
 * page rather than asserted from markup: the defect these numbers replace was a
 * caption that wrapped to two lines in the toolbar row, 672px wide, starting 16px
 * right of the view switcher and running toward the search field at 1440.
 */
it('names the board under the topbar title and puts its caption on its own line at 1440', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $ladder = boardChromeKocLadder($workspace);
    $samplePending = $ladder->options->firstWhere('name', 'sample_pending');

    $opportunity = Opportunity::factory()->recycle([$user, $workspace])->create(['name' => 'Pinned sample']);
    $opportunity->saveCustomFieldValue($ladder, $samplePending->getKey());

    TenantContextService::setTenantId(null);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/opportunities/board")
        ->assertVisible('.fi-board-header .fi-board-name')
        ->assertVisible('.fi-board-header .fi-board-caption')
        ->assertNoJavaScriptErrors();

    $metrics = <<<'JS'
        (() => {
            const box = el => { const b = el.getBoundingClientRect(); return { x: b.x, y: b.y, w: b.width, h: b.height }; };
            const style = el => getComputedStyle(el);
            const overlaps = (a, b) => a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;

            const name = document.querySelector('.fi-board-header .fi-board-name');
            const caption = document.querySelector('.fi-board-header .fi-board-caption');
            const search = document.querySelector('.fi-board-header .fi-ta-search-field');
            const topbar = document.querySelector('[data-page-heading] h1');

            const nameBox = name ? box(name) : null;
            const captionBox = caption ? box(caption) : null;

            return {
                boardName: name ? name.textContent.trim() : null,
                boardNameSize: name ? style(name).fontSize : null,
                boardNameWeight: name ? style(name).fontWeight : null,
                topbarTitle: topbar ? topbar.textContent.trim() : null,
                captionSize: caption ? style(caption).fontSize : null,
                captionLines: caption ? Math.round(captionBox.h / parseFloat(style(caption).lineHeight)) : null,
                captionBelowName: nameBox && captionBox ? captionBox.y >= nameBox.y + nameBox.h - 1 : null,
                captionOverlapsSearch: captionBox && search ? overlaps(captionBox, box(search)) : null,
                archiveGlyphs: Array.from(document.querySelectorAll('.flowforge-column svg path'))
                    .filter(path => (path.getAttribute('d') || '').startsWith('m20.25 7.5-.625 10.632a2.25'))
                    .length,
                emptyLaneTexts: Array.from(document.querySelectorAll('.flowforge-empty-column p')).map(p => p.textContent.trim()),
            };
        })();
    JS;

    $measured = $page->script($metrics);

    expect($measured['boardName'])->toBe('Ladder board')
        ->and($measured['boardNameSize'])->toBe('14px')
        ->and($measured['boardNameWeight'])->toBe('600')
        ->and($measured['topbarTitle'])->toBe('Opportunities')
        ->and($measured['captionSize'])->toBe('13px')
        ->and($measured['captionLines'])->toBeLessThanOrEqual(2)
        ->and($measured['captionBelowName'])->toBeTrue()
        ->and($measured['captionOverlapsSearch'])->toBeFalse()
        ->and($measured['archiveGlyphs'])->toBe(0)
        ->and($measured['emptyLaneTexts'])->toHaveCount(4)
        ->and(array_unique($measured['emptyLaneTexts']))->toHaveCount(4);
});

/**
 * A workspace that declares the collaboration ladder declares it the way the
 * operations workspace gets it: the field and its options are rows.
 */
function boardChromeKocLadder(Workspace $workspace): CustomField
{
    $declared = CustomField::query()
        ->withoutGlobalScopes()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::KOC_STAGE)
        ->where('tenant_id', $workspace->getKey())
        ->first();

    if ($declared instanceof CustomField) {
        return $declared;
    }

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

    return CustomField::query()
        ->withoutGlobalScopes()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::KOC_STAGE)
        ->where('tenant_id', $workspace->getKey())
        ->firstOrFail();
}
