<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ajustá tamaños si querés (64 suele sobrar)
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

        // Asegurá unique por dni (si no existe)
        // Si ya existe un unique con otro nombre, esto puede fallar: en ese caso lo vemos.
        DB::statement("ALTER TABLE socios_padron ADD UNIQUE KEY socios_padron_dni_unique (dni)");
    }

    public function down(): void
    {
        // No recomiendo volver a BIGINT porque perderías datos (barcodes gigantes).
        // Dejamos sin revert para evitar romper.
    }
};
