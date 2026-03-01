<?php

namespace App\Services\DTOs;

class DpsDTO
{
    public function __construct(
        public DpsIdentificacaoDTO $identificacao,
        public EmpresaDTO $empresa,
        public ClienteDTO $cliente,
        public ServicoDTO $servico,
    ) {}
}
