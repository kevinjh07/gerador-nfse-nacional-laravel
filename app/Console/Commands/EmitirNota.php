<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\NotaNacionalService;
use App\Services\DTOs\EmpresaDTO;
use App\Services\DTOs\ClienteDTO;
use App\Services\DTOs\ServicoDTO;
use App\Models\NfseSequencia;
use App\Models\NfseEmitida;

class EmitirNota extends Command
{
    protected $signature = 'fiscal:emitir-nota';

    protected $description = 'Emite NFSe Nacional (ambiente de homologação por padrão)';

    private const VARIAVEIS_OBRIGATORIAS = [
        'NFSE_EMPRESA_CNPJ' => 'CNPJ da empresa',
        'NFSE_EMPRESA_MUNICIPIO' => 'Código IBGE do município da empresa',
        'NFSE_CLIENTE_DOCUMENTO' => 'CPF/CNPJ do cliente',
        'NFSE_CLIENTE_NOME' => 'Nome do cliente',
        'NFSE_CLIENTE_EMAIL' => 'E-mail do cliente',
    ];

    public function handle(NotaNacionalService $service): int
    {
        $this->info('Iniciando Emissão de NFSe (Padrão Nacional)');

        $exitCode = $this->validarConfiguracao();
        if ($exitCode !== null) {
            return $exitCode;
        }

        $empresa = $this->buildEmpresaDTO();
        $cliente = $this->buildClienteDTO();
        $servico = $this->buildServicoDTO();

        $serie = $this->obterSerie();
        $numero = NfseSequencia::proximoNumeroPara($serie);
        $resultado = $service->emitirNota($empresa, $cliente, $servico, (string) $numero, $serie);

        if ($resultado['sucesso'] ?? false) {
            $this->persistirNotaEmitida($resultado, $empresa, $cliente, $servico);
            $this->exibirSucesso($resultado);
        } else {
            $this->exibirFalha($resultado);
            $this->exibirDicasFalha($resultado);
        }

        return 0;
    }

    /**
     * Valida certificado e variáveis obrigatórias do .env. Retorna 1 em caso de erro, null se ok.
     */
    private function validarConfiguracao(): ?int
    {
        $caminho = env('NFSE_CERT_CAMINHO');
        if (empty($caminho)) {
            $this->error('NFSE_CERT_CAMINHO não está definido no .env. Configure o caminho do certificado A1 (.pfx).');
            return 1;
        }

        $senha = env('NFSE_CERT_SENHA');
        if ($senha === null || $senha === '') {
            $this->error('NFSE_CERT_SENHA não está definida no .env. Configure a senha do certificado A1.');
            return 1;
        }

        foreach (self::VARIAVEIS_OBRIGATORIAS as $var => $label) {
            $valor = env($var);
            if ($valor === null || trim((string) $valor) === '') {
                $this->error("{$label} é obrigatório. Defina {$var} no .env.");
                return 1;
            }
        }

        return null;
    }

    private function resolverCaminhoCertificado(): string
    {
        $caminho = env('NFSE_CERT_CAMINHO');
        $ehAbsoluto = str_starts_with($caminho, '/')
            || (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:/', $caminho));
        return $ehAbsoluto ? $caminho : base_path($caminho);
    }

    private function buildEmpresaDTO(): EmpresaDTO
    {
        return new EmpresaDTO(
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
            certificadoCaminho: $this->resolverCaminhoCertificado(),
            certificadoSenha: env('NFSE_CERT_SENHA')
        );
    }

    private function buildClienteDTO(): ClienteDTO
    {
        return new ClienteDTO(
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
    }

    private function buildServicoDTO(): ServicoDTO
    {
        $aliquota = env('NFSE_SERVICO_ALIQUOTA_ISS');
        $valorIss = env('NFSE_SERVICO_VALOR_ISS');

        return new ServicoDTO(
            codigoServico: env('NFSE_SERVICO_CODIGO'),
            descricao: env('NFSE_SERVICO_DESCRICAO'),
            valorServicos: (float) env('NFSE_SERVICO_VALOR'),
            codigoTributacaoMunicipal: env('NFSE_SERVICO_CTRIB_MUN'),
            aliquotaIss: $aliquota !== null ? (float) $aliquota : 0.02,
            issRetido: (bool) env('NFSE_SERVICO_ISS_RETIDO', false),
            valorIss: $valorIss !== null ? (float) $valorIss : 1.00
        );
    }

    private function obterSerie(): string
    {
        return (string) (env('NFSE_SERIE_DPS', '900') ?: '900');
    }

    private function persistirNotaEmitida(
        array $resultado,
        EmpresaDTO $empresa,
        ClienteDTO $cliente,
        ServicoDTO $servico
    ): void {
        NfseEmitida::create([
            'numero' => $resultado['numero'],
            'serie' => $resultado['serie'],
            'id_dps' => $resultado['id_dps'],
            'chave_acesso' => $resultado['chave_acesso'] ?? null,
            'protocolo' => $resultado['protocolo'] ?? null,
            'ambiente' => (int) config('nfse.ambiente', 1),
            'data_emissao' => $resultado['data_emissao'],
            'competencia' => $resultado['competencia'],
            'valor_servico' => $servico->valorServicos,
            'prestador_cnpj' => preg_replace('/[^0-9]/', '', $empresa->cnpj),
            'tomador_documento' => preg_replace('/[^0-9]/', '', $cliente->documento),
            'tomador_nome' => $cliente->nome,
            'descricao_servico' => $servico->descricao,
        ]);
    }

    private function exibirSucesso(array $resultado): void
    {
        $this->info('NFSe emitida com sucesso!');
        $this->line('---------------------------------------------');
        $this->line('Mensagem: ' . ($resultado['mensagem'] ?? ''));

        if (! empty($resultado['chave_acesso'] ?? null)) {
            $this->line('Chave de Acesso: ' . $resultado['chave_acesso']);
        }
        if (! empty($resultado['protocolo'] ?? null)) {
            $this->line('Protocolo: ' . $resultado['protocolo']);
        }
        $this->line('---------------------------------------------');
    }

    private function exibirFalha(array $resultado): void
    {
        $this->error('Falha na emissão.');
        $this->line('Mensagem: ' . ($resultado['mensagem'] ?? 'Erro não informado.'));

        if (! empty($resultado['erros'] ?? [])) {
            $this->line('Detalhes dos erros:');
            foreach ($resultado['erros'] as $erro) {
                $texto = is_array($erro)
                    ? ($erro['codigo'] ?? '') . ' - ' . ($erro['descricao'] ?? json_encode($erro))
                    : (string) $erro;
                $this->line('- ' . $texto);
            }
        }

        if (! empty($resultado['resposta_bruta'] ?? null)) {
            $this->newLine();
            $this->line('Resposta bruta da API (para debug):');
            $this->line($resultado['resposta_bruta']);
        }
    }

    private function exibirDicasFalha(array $resultado): void
    {
        $mensagemCompleta = ($resultado['mensagem'] ?? '') . ' '
            . implode(' ', array_map(
                function ($e) {
                    return is_array($e) ? ($e['descricao'] ?? '') : (string) $e;
                },
                $resultado['erros'] ?? []
            ));

        if (str_contains($mensagemCompleta, 'E4007') || str_contains($mensagemCompleta, 'certificado de cliente')) {
            $this->newLine();
            $this->line('Para diagnosticar mTLS: defina NFSE_DEBUG_MTLS=true no .env e rode o comando de novo.');
            $certPath = storage_path('app/mtls-debug-cert.pem');
            $keyPath = storage_path('app/mtls-debug-key.pem');
            if (file_exists($certPath) && file_exists($keyPath)) {
                $this->line('Depois teste com curl dentro do container (evita problema de aspas no Windows):');
                $this->line('  docker compose exec app sh /var/www/html/scripts/test-mtls-curl.sh');
            }
        }

        if (str_contains($mensagemCompleta, 'RNG9999') || str_contains($mensagemCompleta, 'Erro não catalogado')) {
            $this->newLine();
            $this->line('RNG9999 indica falha inesperada no servidor SEFIN. Para inspecionar o XML enviado:');
            $this->line('  Defina NFSE_DEBUG_XML=true no .env e rode de novo. O XML será salvo em storage/logs/.');
            $this->line('  Confira o arquivo contra o schema oficial ou envie ao suporte NFSe Nacional.');
        }
    }
}
