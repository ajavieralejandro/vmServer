<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
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

        // si existe el índice con ese nombre, lo borro
        $idx = DB::selectOne("SHOW INDEX FROM socios_padron WHERE Key_name = 'socios_padron_dni_unique' LIMIT 1");
        if ($idx) {
            DB::statement("ALTER TABLE socios_padron DROP INDEX socios_padron_dni_unique");
        }

        // lo creo de nuevo (limpio)
        DB::statement("ALTER TABLE socios_padron ADD UNIQUE KEY socios_padron_dni_unique (dni)");
    }

    public function down(): void {}
};
