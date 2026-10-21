<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Resolves the TENANT-SCOPED identity that owns a platform-provisioned workspace.
 *
 * Fork addition — see NOTICE.
 *
 * The orchestrator derives the address from the tenant's workspace domain
 * (`admin@<slug>.<base domain>`), never from the human's signup email: Relaticle's
 * `users.email` is globally unique, so reusing a signup address would make the
 * second tenant owned by the same human fail its insert — the same rule the
 * ChatterMate provisioner already follows (`t_9f16c775`).
 *
 * Find-or-create, never create-on-the-fly-with-a-guess: a caller that can name an
 * arbitrary address must not be able to mint identities, so the address is
 * validated at the controller before it reaches here.
 */
final readonly class ResolveTenantOwner
{
    public function execute(string $email, ?string $name = null): User
    {
        $existing = User::query()->where('email', $email)->first();

        if ($existing instanceof User) {
            return $existing;
        }

        return User::query()->create([
            'name' => $name !== null && $name !== '' ? $name : Str::title(Str::before($email, '@')),
            'email' => $email,
            // No human ever signs in with this secret: the only way in is the
            // brokered one-time login (BrokerLoginController). A random value is
            // still stored rather than null so the account is never a
            // password-less row a future flow could mistake for "any password".
            'password' => Hash::make(Str::random(64)),
            // The panel gates on REQUIRE_EMAIL_VERIFICATION; a provisioned owner
            // has no mailbox to click through, and the platform vouches for the
            // tenant it just created.
            'email_verified_at' => now(),
        ]);
    }
}
