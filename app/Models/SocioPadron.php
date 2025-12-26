<?php

// app/Models/SocioPadron.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocioPadron extends Model
{
  protected $table = 'socios_padron';

  protected $fillable = [
    'dni','sid','apynom','barcode','saldo','semaforo','ult_impago','acceso_full','hab_controles','raw'
  ];

  protected $casts = [
    'raw' => 'array',
    'saldo' => 'decimal:2',
    'semaforo' => 'integer',
    'ult_impago' => 'integer',
    'acceso_full' => 'integer',
  ];
}
