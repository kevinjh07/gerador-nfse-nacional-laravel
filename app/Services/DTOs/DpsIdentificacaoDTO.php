<?php

namespace App\Services\DTOs;

use DateTimeInterface;

class DpsIdentificacaoDTO
{
    public function __construct(
        public int $ambiente,
        public string $numero,
        public string $serie,
        public string $tipo,
        public string $naturezaOperacao,
        public DateTimeInterface $dataHoraEmissao,
        public string $municipioPrestacao,
    ) {}
}
