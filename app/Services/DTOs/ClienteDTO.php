<?php

namespace App\Services\DTOs;

class ClienteDTO
{
    public function __construct(
        public string $documento,
        public string $tipoDocumento,
        public string $nome,
        public string $email,
        public string $cep,
        public string $logradouro,
        public string $numero,
        public string $bairro,
        public string $cidadeCodigoIbge,
        public string $uf,
        public ?string $inscricaoMunicipal = null,
        public ?string $telefone = null,
    ) {}
}
