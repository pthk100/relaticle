<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\McnCollaborationFieldsSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Seed the KOC collaboration fields into the operations workspace and prune every other one')]
#[Signature('platform:seed-collaboration-fields
                            {--workspace= : Seed this one workspace id instead of the MCN_OPERATIONS_WORKSPACES map}')]
final class SeedCollaborationFieldsCommand extends Command
{
    public function handle(): int
    {
        $workspace = trim((string) $this->option('workspace'));

        $seeder = $this->laravel->make(McnCollaborationFieldsSeeder::class)
            ->setContainer($this->laravel)
            ->setCommand($this);

        $seeder->__invoke($workspace === '' ? [] : ['workspace' => $workspace]);

        return self::SUCCESS;
    }
}
