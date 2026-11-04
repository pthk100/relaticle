<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The column arrives with the table on a fresh install, so this is often a no-op here.
        // It must still run and be recorded: a gated skip leaves this migration Pending forever.
        if (Schema::hasColumn('custom_field_options', 'settings')) {
            return;
        }

        Schema::table('custom_field_options', function (Blueprint $table): void {
            $table->json('settings')->nullable()->after('sort_order');
        });
    }
};
