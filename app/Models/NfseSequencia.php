<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class NfseSequencia extends Model
{
    protected $table = 'nfse_sequencia';

    protected $primaryKey = 'serie';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'serie',
        'proximo_numero',
    ];

    protected $casts = [
        'proximo_numero' => 'integer',
    ];

    /**
     * Reserva e retorna o próximo número da DPS para a série (com lock para evitar duplicidade).
     */
    public static function proximoNumeroPara(string $serie): int
    {
        return (int) DB::transaction(function () use ($serie) {
            $row = static::where('serie', $serie)->lockForUpdate()->first();
            if ($row === null) {
                $row = static::create([
                    'serie' => $serie,
                    'proximo_numero' => 1,
                ]);
            }
            $numero = $row->proximo_numero;
            $row->increment('proximo_numero');
            return $numero;
        });
    }
}
