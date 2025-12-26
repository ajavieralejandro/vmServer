<?php

// app/Models/SocioPadron.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocioPadron extends Model
{
    protected $table = 'socios_padron';

    protected $fillable = [
        'dni',
        'sid',
        'apynom',
        'barcode',
        'saldo',
        'semaforo',
        'ult_impago',
        'acceso_full',
        'hab_controles',
        'raw',
    ];

    protected $casts = [
        // si guardás JSON en BD:
        'raw'           => 'array',
        'hab_controles' => 'array',

        'saldo'      => 'decimal:2',
        'semaforo'   => 'integer',
        'ult_impago' => 'integer',

        // mejor como bool (si en BD guardás 0/1, Laravel lo maneja igual)
        'acceso_full' => 'boolean',
    ];
}
