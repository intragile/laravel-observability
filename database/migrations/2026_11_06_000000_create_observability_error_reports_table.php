<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observability_error_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('exception_type', 255);
            // Stored already redacted: SQL tails with bound values never reach this column.
            $table->text('message');
            $table->string('code', 64)->nullable();
            // sha1(exception_type . "\n" . message), used to group identical errors.
            $table->char('fingerprint', 40)->index();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observability_error_reports');
    }
};
