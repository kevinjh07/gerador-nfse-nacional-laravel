<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NfseEmitida extends Model
{
    protected $table = 'nfse_emitidas';

    protected $fillable = [
        'numero',
        'serie',
        'id_dps',
        'chave_acesso',
        'protocolo',
        'ambiente',
        'data_emissao',
        'competencia',
        'valor_servico',
        'prestador_cnpj',
        'tomador_documento',
        'tomador_nome',
        'descricao_servico',
    ];

    protected $casts = [
        'data_emissao' => 'datetime',
        'competencia' => 'date',
        'valor_servico' => 'decimal:2',
        'numero' => 'integer',
        'ambiente' => 'integer',
    ];
}
