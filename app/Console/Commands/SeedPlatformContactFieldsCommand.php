<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Onboarding\PlatformContactFieldSet;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Seed the platform contact field set into every workspace, or into one (idempotent)')]
#[Signature('platform:seed-contact-fields
                            {--workspace= : Seed a single workspace id instead of all of them}')]
final class SeedPlatformContactFieldsCommand extends Command
{
    public function handle(): int
    {
        if ($this->option('workspace')) {
            $inserted = PlatformContactFieldSet::applyTo((string) $this->option('workspace'));

            $this->info("Seeded {$inserted} missing contact field(s) into workspace {$this->option('workspace')}.");

            return self::SUCCESS;
        }

        $result = PlatformContactFieldSet::applyToEveryTenant();

        $this->info("Checked {$result['tenants']} workspace(s); seeded {$result['inserted']} missing field row(s).");

        return self::SUCCESS;
    }
}
