<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\NotaNacionalService;
use App\Services\DTOs\EmpresaDTO;
use App\Services\DTOs\ClienteDTO;
use App\Services\DTOs\ServicoDTO;

class EmitirNota extends Command
{
    /**
     * Comando para emissão real de NFSe (ambiente configurado em config/nfse, padrão homologação).
     */
    protected $signature = 'fiscal:emitir-nota';

    protected $description = 'Emite NFSe Nacional (ambiente de homologação por padrão)';

    public function handle(NotaNacionalService $service)
    {
        $this->info('Iniciando Emissão de NFSe (Padrão Nacional)');

        $caminhoCertificado = env('NFSE_CERT_CAMINHO');
        if (empty($caminhoCertificado)) {
            $this->error('NFSE_CERT_CAMINHO não está definido no .env. Configure o caminho do certificado A1 (.pfx).');
            return 1;
        }
        if (! str_starts_with($caminhoCertificado, '/') && ! (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:/', $caminhoCertificado))) {
            $caminhoCertificado = base_path($caminhoCertificado);
        }

        $senhaCertificado = env('NFSE_CERT_SENHA');
        if ($senhaCertificado === null || $senhaCertificado === '') {
            $this->error('NFSE_CERT_SENHA não está definida no .env. Configure a senha do certificado A1.');
            return 1;
        }

        $obrigatorios = [
            'NFSE_EMPRESA_CNPJ' => 'CNPJ da empresa',
            'NFSE_EMPRESA_MUNICIPIO' => 'Código IBGE do município da empresa',
            'NFSE_CLIENTE_DOCUMENTO' => 'CPF/CNPJ do cliente',
            'NFSE_CLIENTE_NOME' => 'Nome do cliente',
            'NFSE_CLIENTE_EMAIL' => 'E-mail do cliente',
        ];
        foreach ($obrigatorios as $var => $label) {
            $valor = env($var);
            if ($valor === null || trim((string) $valor) === '') {
                $this->error("{$label} é obrigatório. Defina {$var} no .env.");
                return 1;
            }
        }

        $empresa = new EmpresaDTO(
            cnpj: env('NFSE_EMPRESA_CNPJ'),
            inscricaoMunicipal: env('NFSE_EMPRESA_IM'),
            razaoSocial: env('NFSE_EMPRESA_RAZAO'),
            regimeTributario: env('NFSE_EMPRESA_REGIME'),
            codigoMunicipio: env('NFSE_EMPRESA_MUNICIPIO'),
            cnae: env('NFSE_EMPRESA_CNAE'),
            codigoTributacaoMunicipal: env('NFSE_EMPRESA_CTRIB_MUN'),
            optanteSimples: (bool) env('NFSE_EMPRESA_OPTANTE_SIMPLES'),
            logradouro: env('NFSE_EMPRESA_LOGRADOURO'),
            numero: env('NFSE_EMPRESA_NUMERO'),
            bairro: env('NFSE_EMPRESA_BAIRRO'),
            cep: env('NFSE_EMPRESA_CEP'),
            uf: env('NFSE_EMPRESA_UF'),
            telefone: env('NFSE_EMPRESA_TELEFONE'),
            email: env('NFSE_EMPRESA_EMAIL'),
            certificadoCaminho: $caminhoCertificado,
            certificadoSenha: $senhaCertificado
        );

        $cliente = new ClienteDTO(
            documento: env('NFSE_CLIENTE_DOCUMENTO'),
            tipoDocumento: env('NFSE_CLIENTE_TIPO_DOCUMENTO', 'CPF'),
            nome: env('NFSE_CLIENTE_NOME'),
            email: env('NFSE_CLIENTE_EMAIL'),
            cep: env('NFSE_CLIENTE_CEP') ?? '',
            logradouro: env('NFSE_CLIENTE_LOGRADOURO') ?? '',
            numero: env('NFSE_CLIENTE_NUMERO') ?? '',
            bairro: env('NFSE_CLIENTE_BAIRRO') ?? '',
            cidadeCodigoIbge: env('NFSE_CLIENTE_MUNICIPIO') ?? '',
            uf: env('NFSE_CLIENTE_UF') ?? '',
            inscricaoMunicipal: env('NFSE_CLIENTE_IM'),
            telefone: env('NFSE_CLIENTE_TELEFONE')
        );

        $servico = new ServicoDTO(
            codigoServico: env('NFSE_SERVICO_CODIGO'),
            descricao: env('NFSE_SERVICO_DESCRICAO'),
            valorServicos: (float) env('NFSE_SERVICO_VALOR'),
            codigoTributacaoMunicipal: env('NFSE_SERVICO_CTRIB_MUN'),
            aliquotaIss: env('NFSE_SERVICO_ALIQUOTA_ISS') !== null
                ? (float) env('NFSE_SERVICO_ALIQUOTA_ISS')
                : 0.02,
            issRetido: (bool) env('NFSE_SERVICO_ISS_RETIDO', false),
            valorIss: env('NFSE_SERVICO_VALOR_ISS') !== null
                ? (float) env('NFSE_SERVICO_VALOR_ISS')
                : 1.00
        );

        $resultado = $service->emitirNota($empresa, $cliente, $servico);

        if ($resultado['sucesso'] ?? false) {
            $this->info('NFSe emitida com sucesso!');
            $this->line('---------------------------------------------');
            $this->line('Mensagem: ' . ($resultado['mensagem'] ?? ''));

            if (!empty($resultado['chave_acesso'] ?? null)) {
                $this->line('Chave de Acesso: ' . $resultado['chave_acesso']);
            }

            if (!empty($resultado['protocolo'] ?? null)) {
                $this->line('Protocolo: ' . $resultado['protocolo']);
            }

            $this->line('---------------------------------------------');
        } else {
            $this->error('Falha na emissão.');
            $this->line('Mensagem: ' . ($resultado['mensagem'] ?? 'Erro não informado.'));

            if (!empty($resultado['erros'] ?? [])) {
                $this->line('Detalhes dos erros:');
                foreach ($resultado['erros'] as $erro) {
                    if (is_array($erro)) {
                        $this->line('- ' . ($erro['codigo'] ?? '') . ' - ' . ($erro['descricao'] ?? json_encode($erro)));
                    } else {
                        $this->line('- ' . $erro);
                    }
                }
            }

            if (!empty($resultado['resposta_bruta'] ?? null)) {
                $this->newLine();
                $this->line('Resposta bruta da API (para debug):');
                $this->line($resultado['resposta_bruta']);
            }

            $msg = $resultado['mensagem'] . ' ' . implode(' ', array_map(function ($e) {
                return is_array($e) ? ($e['descricao'] ?? '') : (string) $e;
            }, $resultado['erros'] ?? []));
            if (str_contains($msg, 'E4007') || str_contains($msg, 'certificado de cliente')) {
                $this->newLine();
                $this->line('Para diagnosticar mTLS: defina NFSE_DEBUG_MTLS=true no .env e rode o comando de novo.');
                $certPath = storage_path('app/mtls-debug-cert.pem');
                $keyPath = storage_path('app/mtls-debug-key.pem');
                if (file_exists($certPath) && file_exists($keyPath)) {
                    $this->line('Depois teste com curl dentro do container (evita problema de aspas no Windows):');
                    $this->line('  docker compose exec app sh /var/www/html/scripts/test-mtls-curl.sh');
                }
            }
            if (str_contains($msg, 'RNG9999') || str_contains($msg, 'Erro não catalogado')) {
                $this->newLine();
                $this->line('RNG9999 indica falha inesperada no servidor SEFIN. Para inspecionar o XML enviado:');
                $this->line('  Defina NFSE_DEBUG_XML=true no .env e rode de novo. O XML será salvo em storage/logs/.');
                $this->line('  Confira o arquivo contra o schema oficial ou envie ao suporte NFSe Nacional.');
            }
        }

        return 0;
    }
}
