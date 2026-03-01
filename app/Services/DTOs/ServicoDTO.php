<?php

namespace App\Services\DTOs;

class ServicoDTO
{
    public function __construct(
        public string $codigoServico,
        public string $descricao,
        public float $valorServicos,
        public string $codigoTributacaoMunicipal,
        public ?float $aliquotaIss = null,
        public bool $issRetido = false,
        public ?float $valorIss = null,
    ) {}
}
