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

        if (!$padron || !is_array($padron)) {
            $this->error('No se pudo obtener el padrón (vacío o inválido).');
            return self::FAILURE;
        }

        $this->info('Registros recibidos: ' . count($padron));

        $chunkSize = max(1, (int) $this->option('chunk'));
        $now = now();

        $rows = [];

        foreach ($padron as $item) {
            if (!is_array($item)) {
                continue;
            }

            // DNI obligatorio
            $dni = isset($item['dni']) ? trim((string) $item['dni']) : null;
            if (!$dni) {
                continue;
            }

            /** ---------------- JSON: hab_controles ---------------- */
            $hab = $item['hab_controles'] ?? null;

            if (is_array($hab) || is_object($hab)) {
                $habJson = json_encode($hab, JSON_UNESCAPED_UNICODE);
            } elseif ($hab === null || $hab === '') {
                $habJson = null;
            } else {
                // viene tipo "201" => lo guardamos como JSON escalar
                $habJson = json_encode((string) $hab, JSON_UNESCAPED_UNICODE);
            }

            /** ---------------- JSON: raw ---------------- */
            $rawJson = json_encode($item, JSON_UNESCAPED_UNICODE);
            if ($rawJson === false) {
                // fallback ultra seguro
                $rawJson = json_encode(['error' => 'raw_encode_failed']);
            }

            $rows[] = [
                'dni'          => $dni,
                'sid'          => isset($item['sid']) ? (string) $item['sid'] : null,
                'apynom'       => $item['apynom'] ?? null,

                // barcode SIEMPRE string
                'barcode'      => isset($item['barcode']) ? (string) $item['barcode'] : null,

                // números normalizados
                'saldo'        => isset($item['saldo']) ? (float) $item['saldo'] : null,
                'semaforo'     => isset($item['semaforo']) ? (int) $item['semaforo'] : null,
                'ult_impago'   => isset($item['ult_impago']) ? (int) $item['ult_impago'] : null,
                'acceso_full'  => isset($item['acceso_full']) ? (int) $item['acceso_full'] : null,

                // JSON ya validado
                'hab_controles'=> $habJson,
                'raw'          => $rawJson,

                'updated_at'   => $now,
                'created_at'   => $now,
            ];
        }

        if (empty($rows)) {
            $this->warn('No hay filas válidas para insertar.');
            return self::SUCCESS;
        }

        $this->info('Upsert en DB...');
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            SocioPadron::upsert(
                $chunk,
                ['dni'],
                [
                    'sid',
                    'apynom',
                    'barcode',
                    'saldo',
                    'semaforo',
                    'ult_impago',
                    'acceso_full',
                    'hab_controles',
                    'raw',
                    'updated_at'
                ]
            );
            $this->output->write('.');
        }

        $this->newLine();
        $this->info('Sincronización finalizada OK.');

        return self::SUCCESS;
    }
}
