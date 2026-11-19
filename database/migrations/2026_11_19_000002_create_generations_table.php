<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->string('size', 16);
            $table->string('quality', 16);
            $table->json('reference_images')->nullable();
            // Local lifecycle: pending -> queued -> processing -> succeeded|failed.
            // "pending" exists between row creation and a successful submit to
            // Pixelforge, so a webhook racing our POST already has a row to land on.
            $table->string('status', 20)->default('pending')->index();
            $table->string('pixelforge_id', 64)->nullable()->unique();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            // Estimated user charge (provider estimate + markup), known up front.
            $table->decimal('estimate_cost', 24, 12);
            // Actual amounts, known only once the generation succeeds.
            $table->decimal('provider_cost', 24, 12)->nullable();
            $table->decimal('charged_cost', 24, 12)->nullable();
            $table->json('error')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
