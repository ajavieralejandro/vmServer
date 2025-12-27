<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cambios de columnas
        DB::statement("
            ALTER TABLE socios_padron
            MODIFY dni VARCHAR(16) NOT NULL,
            MODIFY sid VARCHAR(64) NULL,
            MODIFY barcode VARCHAR(64) NULL,
            MODIFY saldo DECIMAL(12,2) NULL,
            MODIFY semaforo INT NULL,
            MODIFY ult_impago INT NULL,
            MODIFY acceso_full INT NULL,
            MODIFY hab_controles JSON NULL,
            MODIFY raw JSON NULL
        ");

        // Crear UNIQUE(dni) solo si no existe
        $exists = DB::select("
            SHOW INDEX FROM socios_padron
            WHERE Key_name = 'socios_padron_dni_unique'
        ");

        if (empty($exists)) {
            Schema::table('socios_padron', function (Blueprint $table) {
                $table->unique('dni', 'socios_padron_dni_unique');
            });
        }
    }

    public function down(): void
    {
        // No revertimos tipos ni índices:
        // - Podría perderse información (barcode/sid)
        // - Evitamos romper datos históricos
    }
};
