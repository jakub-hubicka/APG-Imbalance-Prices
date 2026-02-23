<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('imbalance_prices', function (Blueprint $table) {
            $table->timestampTz('time');
            $table->string('eic', 16);
            $table->decimal('price_up', 10, 3)->nullable();
            $table->decimal('price_down', 10, 3)->nullable();
            $table->char('currency', 3);
            $table->string('unit', 10)->nullable();
            $table->string('source');

            $table->primary(['time', 'eic', 'currency', 'source'], 'imbalance_prices_pkey1');
            $table->index('currency', 'idx_imbalance_currency');
            $table->index(['eic', 'time'], 'idx_imbalance_eic_time');
        });

        DB::statement('CREATE INDEX imbalance_prices_time_idx1 ON imbalance_prices ("time" DESC)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('imbalance_prices');
    }
};
