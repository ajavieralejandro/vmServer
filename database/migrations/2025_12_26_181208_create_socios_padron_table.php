<?php
// database/migrations/xxxx_xx_xx_create_socios_padron_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('socios_padron', function (Blueprint $table) {
      $table->id();

      $table->string('dni', 20)->unique();     // "42462163"
      $table->string('sid', 50)->nullable();   // "44940"
      $table->string('apynom', 255)->nullable();// "OLIVETO JULIETA"
      $table->string('barcode', 255)->nullable();

      $table->decimal('saldo', 12, 2)->nullable(); // "-69300.00"
      $table->unsignedInteger('semaforo')->nullable(); // "10"
      $table->unsignedInteger('ult_impago')->nullable();
      $table->unsignedTinyInteger('acceso_full')->nullable(); // viene "0"/"1"
      $table->string('hab_controles', 50)->nullable();

      $table->json('raw')->nullable(); // por si mañana agregan campos

      $table->timestamps();
    });
  }

  public function down(): void {
    Schema::dropIfExists('socios_padron');
  }
};
