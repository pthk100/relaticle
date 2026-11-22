<?php

declare(strict_types=1);

namespace App\Filament\Resources\OpportunityResource\Forms;

use App\Enums\CustomFields\OpportunityField;
use App\Filament\Components\Forms\RecordSelect;
use App\Models\CustomField;
use App\Models\Opportunity;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Relaticle\CustomFields\Facades\CustomFields;

final class OpportunityForm
{
    public static function get(Schema $schema): Schema
    {
        $mirrorsLadder = self::workspaceDeclaresLadder();

        $customFields = CustomFields::form();

        if ($mirrorsLadder) {
            $customFields->except([OpportunityField::KOC_STAGE->value]);
        }

        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->placeholder(__('filament/resources/opportunity.fields.name.placeholder'))
                    ->columnSpanFull(),
                RecordSelect::make('company_id')
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload()
                    ->columnSpan(2),
                RecordSelect::make('contact_id')
                    ->label(__('filament/resources/opportunity.fields.contact_id.label'))
                    ->relationship('contact', 'name')
                    ->searchable()
                    ->preload()
                    ->columnSpan(2),
                $customFields->build()->columnSpanFull()->columns(1),
                Placeholder::make('koc_stage_provenance')
                    ->hiddenLabel()
                    ->content(__('filament/resources/opportunity.fields.koc_stage.provenance'))
                    ->visible($mirrorsLadder),
            ])
            ->columns(4);
    }

    /**
     * A workspace that mirrors the platform's ladder never edits a collaboration's
     * rung here, so the field is left out and its origin is stated instead.
     */
    private static function workspaceDeclaresLadder(): bool
    {
        return CustomField::query()
            ->forEntity(Opportunity::class)
            ->where('code', OpportunityField::KOC_STAGE)
            ->exists();
    }
}
