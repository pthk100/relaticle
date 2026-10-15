<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds the identity that owns platform-provisioned workspaces (fork addition — see NOTICE).
 *
 * Required rather than optional: a fresh install has zero users, and ProvisionWorkspace
 * deliberately refuses to invent one, so POST /api/v1/workspaces cannot succeed until this
 * has run. Idempotent — running it twice updates rather than duplicating.
 */
#[Description('Create the platform service user that owns workspaces created via the provisioning API')]
#[Signature('platform:service-user
                            {--password= : Password for the seeded user; a random one is generated when omitted}')]
final class EnsurePlatformServiceUserCommand extends Command
{
    public function handle(): int
    {
        $email = config('relaticle.platform.service_user_email');
        $name = config('relaticle.platform.service_user_name');

        if (! is_string($email) || $email === '') {
            $this->error('relaticle.platform.service_user_email is not configured.');

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $isNew = ! $user->exists;

        $user->fill([
            'name' => is_string($name) && $name !== '' ? $name : 'Platform Provisioning',
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        // Never overwrite an existing password. This identity authenticates over API tokens,
        // and silently rotating its credential on every run would be a surprise.
        if ($isNew) {
            $user->password = Hash::make($this->option('password') ?: Str::password(32));
        }

        $user->save();

        $this->info($isNew
            ? "Created platform service user [{$email}]."
            : "Platform service user [{$email}] already existed; nothing else changed.");

        $this->line('It owns provisioned workspaces and authenticates over API tokens; it is not intended to sign in to the panel.');

        return self::SUCCESS;
    }
}
