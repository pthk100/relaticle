<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds the identity that owns platform-provisioned workspaces (fork addition, see NOTICE).
 *
 * Required rather than optional: a fresh install has zero users, and ProvisionWorkspace
 * deliberately refuses to invent one, so POST /api/v1/workspaces cannot succeed until this
 * has run. Idempotent: running it twice updates rather than duplicating.
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
            'name' => is_string($name) && $name !== '' ? $name : 'The platform',
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        // Never overwrite an existing password. This identity authenticates over API tokens,
        // and silently rotating its credential on every run would be a surprise.
        if ($isNew) {
            $user->password = Hash::make($this->option('password') ?: Str::password(32));
        }

        $user->save();

        // Every workspace must be able to hold a credential for this identity: the
        // orchestrator's stored credential acts as the platform, and
        // SetApiWorkspaceContext requires its holder to belong (t_ca323f91). Attaching
        // here is the sweep over workspaces that predate the change; provisioning and
        // adoption attach at their own seams, and the token route attaches on demand.
        $attached = 0;
        $present = 0;

        Workspace::query()->orderBy('id')->each(function (Workspace $workspace) use ($user, &$attached, &$present): void {
            if ($workspace->user_id === $user->getKey() || $workspace->users()->whereKey($user->getKey())->exists()) {
                $present++;

                return;
            }

            $workspace->users()->attach($user->getKey(), ['role' => WorkspaceRole::Member->value]);
            $attached++;
        });

        $this->info($isNew
            ? "Created platform service user [{$email}]."
            : "Platform service user [{$email}] already existed; nothing else changed.");

        $this->line("Workspace membership: {$attached} attached, {$present} already present.");

        $this->line('It owns provisioned workspaces and authenticates over API tokens; it is not intended to sign in to the panel.');

        return self::SUCCESS;
    }
}
