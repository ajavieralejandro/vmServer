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

    $this->info('Registros recibidos: '.count($padron));

    $chunkSize = (int)$this->option('chunk') ?: 500;

    $rows = [];
    foreach ($padron as $item) {
      // Normalización básica
      $dni = isset($item['dni']) ? trim((string)$item['dni']) : null;
      if (!$dni) continue;

      $rows[] = [
        'dni'          => $dni,
        'sid'          => isset($item['sid']) ? (string)$item['sid'] : null,
        'apynom'       => $item['apynom'] ?? null,
        'barcode'      => $item['barcode'] ?? null,
        'saldo'        => isset($item['saldo']) ? (float)$item['saldo'] : null,
        'semaforo'     => isset($item['semaforo']) ? (int)$item['semaforo'] : null,
        'ult_impago'   => isset($item['ult_impago']) ? (int)$item['ult_impago'] : null,
        'acceso_full'  => isset($item['acceso_full']) ? (int)$item['acceso_full'] : null,
        'hab_controles'=> $item['hab_controles'] ?? null,
        'raw'          => $item,
        'updated_at'   => now(),
        'created_at'   => now(),
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
