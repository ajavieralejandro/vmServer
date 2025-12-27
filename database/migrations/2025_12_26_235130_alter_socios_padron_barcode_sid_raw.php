<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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

        // ¿Ya existe algún UNIQUE sobre la columna dni?
        $hasUniqueOnDni = DB::selectOne("
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name   = 'socios_padron'
              AND column_name  = 'dni'
              AND non_unique   = 0
            LIMIT 1
        ");

        if (!$hasUniqueOnDni) {
            DB::statement("ALTER TABLE socios_padron ADD UNIQUE KEY socios_padron_dni_unique (dni)");
        }
    }

    public function down(): void
    {
        // intencionalmente vacío
    }
};
