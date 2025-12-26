<?php

// app/Console/Commands/SyncPadronSocios.php
namespace App\Console\Commands;

use App\Models\SocioPadron;
use App\Services\SociosApi;
use Illuminate\Console\Command;

class SyncPadronSocios extends Command
{
    protected $signature = 'socios:sync-padron {--chunk=500}';
    protected $description = 'Sincroniza el padrón completo de socios en la DB local';

    public function handle(SociosApi $api): int
    {
        $this->info('Descargando padrón...');
        $padron = $api->getPadronFull();

        if (!$padron) {
            $this->error('No se pudo obtener el padrón (result vacío o error).');
            return self::FAILURE;
        }

        $this->info('Registros recibidos: ' . count($padron));

        $chunkSize = (int) $this->option('chunk') ?: 500;

        $now = now();

        $rows = [];
        foreach ($padron as $item) {
            // Normalización básica
            $dni = isset($item['dni']) ? trim((string) $item['dni']) : null;
            if (!$dni) continue;

            $habControles = $item['hab_controles'] ?? null;

            // Clave: en upsert masivo NO se aplican casts del modelo => encodear JSON acá
            $rows[] = [
                'dni'           => $dni,
                'sid'           => isset($item['sid']) ? (string) $item['sid'] : null,
                'apynom'        => $item['apynom'] ?? null,

                // Importante: barcode puede ser enorme => guardalo como string siempre
                'barcode'       => isset($item['barcode']) ? (string) $item['barcode'] : null,

                'saldo'         => array_key_exists('saldo', $item) ? (float) $item['saldo'] : null,
                'semaforo'      => array_key_exists('semaforo', $item) ? (int) $item['semaforo'] : null,
                'ult_impago'    => array_key_exists('ult_impago', $item) ? (int) $item['ult_impago'] : null,
                'acceso_full'   => array_key_exists('acceso_full', $item) ? (int) $item['acceso_full'] : null,

                // JSON seguro (si viene array => encode; si viene null/string => lo dejamos)
                'hab_controles' => is_array($habControles)
                    ? json_encode($habControles, JSON_UNESCAPED_UNICODE)
                    : $habControles,

                // Guardar snapshot completo del item como JSON
                'raw'           => json_encode($item, JSON_UNESCAPED_UNICODE),

                'updated_at'    => $now,
                'created_at'    => $now,
            ];
        }

        $this->info('Upsert en DB...');
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            SocioPadron::upsert(
                $chunk,
                ['dni'], // unique key
                ['sid','apynom','barcode','saldo','semaforo','ult_impago','acceso_full','hab_controles','raw','updated_at']
            );
            $this->output->write('.');
        }

        $this->newLine();
        $this->info('Listo.');
        return self::SUCCESS;
    }
}
