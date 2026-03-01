<?php

namespace App\Services\DTOs;

class EmpresaDTO
{
    public function __construct(
        public string $cnpj,
        public string $inscricaoMunicipal,
        public string $razaoSocial,
        public string $regimeTributario,
        public string $codigoMunicipio,
        public string $cnae,
        public string $codigoTributacaoMunicipal,
        public bool $optanteSimples,
        public ?string $logradouro = null,
        public ?string $numero = null,
        public ?string $bairro = null,
        public ?string $cep = null,
        public ?string $uf = null,
        public ?string $telefone = null,
        public ?string $email = null,
        public string $certificadoCaminho,
        public string $certificadoSenha,
    ) {}
}
