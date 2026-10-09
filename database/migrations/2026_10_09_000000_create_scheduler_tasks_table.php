<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(config('scheduling.database.connection'))
            ->create(config('scheduling.database.table'), function (Blueprint $table): void {
                $table->string('id', 100)->primary();
                $table->text('definition');
            });
    }

    public function down(): void
    {
        Schema::connection(config('scheduling.database.connection'))->dropIfExists(config('scheduling.database.table'));
    }
};
