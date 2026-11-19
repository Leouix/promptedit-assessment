<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // All money is US dollars. Fractions of a cent matter, so we store
            // exact decimals (scale 12 covers provider costs in nanodollars
            // plus the 25% markup) and never use floats.
            $table->decimal('credits', 24, 12)->default(0)->after('password');
            $table->boolean('is_admin')->default(false)->after('credits');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['credits', 'is_admin']);
        });
    }
};
