<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('socios_padron', function (Blueprint $table) {
            // Si ya existen como string/int, las pasás a JSON
            $table->json('hab_controles')->nullable()->change();
            $table->json('raw')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('socios_padron', function (Blueprint $table) {
            $table->text('hab_controles')->nullable()->change();
            $table->longText('raw')->nullable()->change();
        });
    }
};
