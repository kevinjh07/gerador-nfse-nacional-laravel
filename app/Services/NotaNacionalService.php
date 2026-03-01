<?php

namespace App\Services;

use App\Services\Contracts\NfseServiceInterface;
use App\Services\DTOs\EmpresaDTO;
use App\Services\DTOs\ClienteDTO;
use App\Services\DTOs\ServicoDTO;
use App\Services\DTOs\DpsDTO;
use App\Services\DTOs\DpsIdentificacaoDTO;
use NFePHP\Common\Certificate;

class NotaNacionalService implements NfseServiceInterface
{
    /**
     * Emissão real de NFSe: gera XML da DPS, assina com A1 e envia via mTLS (ambiente em config/nfse).
     * Retorna em caso de sucesso: chave_acesso, protocolo, id_dps, numero, serie, data_emissao, competencia.
     */
    public function emitirNota(EmpresaDTO $empresa, ClienteDTO $cliente, ServicoDTO $servico, string $numero, string $serie): array
    {
        try {
            $cert = $this->carregarCertificado($empresa);
            $dps = $this->criarDpsDTO($empresa, $cliente, $servico, $numero, $serie);
            $this->validarDps($dps, $cert);

            $xmlBuilder = new DpsXmlBuilder();
            $xml = $xmlBuilder->build($dps);

            $signer = new XmlSigner();
            $xmlAssinado = $signer->sign($xml, $cert);

            $apiClient = new NfseApiClient();
            $resultado = $apiClient->emitirDpsHomologacao($xmlAssinado);

            if ($resultado['sucesso'] ?? false) {
                $resultado['id_dps'] = $this->buildIdDps($dps);
                $resultado['numero'] = (int) preg_replace('/[^0-9]/', '', $dps->identificacao->numero);
                $resultado['serie'] = $serie;
                $resultado['data_emissao'] = $dps->identificacao->dataHoraEmissao;
                $resultado['competencia'] = $dps->identificacao->dataHoraEmissao->format('Y-m-d');
            }

            return $resultado;
        } catch (\Exception $e) {
            return [
                'sucesso' => false,
                'mensagem' => $e->getMessage(),
                'erros' => [['descricao' => $e->getMessage()]],
            ];
        }
    }

    private function carregarCertificado(EmpresaDTO $empresa): Certificate
    {
        if (!file_exists($empresa->certificadoCaminho)) {
            throw new \Exception("Certificado não encontrado em: {$empresa->certificadoCaminho}");
        }
        $content = file_get_contents($empresa->certificadoCaminho);
        return Certificate::readPfx($content, $empresa->certificadoSenha);
    }

    private function criarDpsDTO(EmpresaDTO $empresa, ClienteDTO $cliente, ServicoDTO $servico, string $numero, string $serie): DpsDTO
    {
        $tpAmb = (int) config('nfse.ambiente', 1);

        $identificacao = new DpsIdentificacaoDTO(
            ambiente: $tpAmb,
            numero: $numero,
            serie: $serie,
            tipo: 'DPS',
            naturezaOperacao: '1',
            dataHoraEmissao: now(),
            municipioPrestacao: $empresa->codigoMunicipio,
        );

        return new DpsDTO(
            identificacao: $identificacao,
            empresa: $empresa,
            cliente: $cliente,
            servico: $servico,
        );
    }

    private function buildIdDps(DpsDTO $dps): string
    {
        $cLocEmi = preg_replace('/[^0-9]/', '', $dps->empresa->codigoMunicipio) ?: '0000000';
        $numero = (string) (int) preg_replace('/[^0-9]/', '', $dps->identificacao->numero);
        $serie = (string) (int) preg_replace('/[^0-9]/', '', $dps->identificacao->serie);
        return 'DPS' . $cLocEmi . $dps->identificacao->ambiente .
            str_pad(preg_replace('/[^0-9]/', '', $dps->empresa->cnpj), 14, '0', STR_PAD_LEFT) .
            sprintf('%05d', $serie) . sprintf('%015d', $numero);
    }

    private function validarDps(DpsDTO $dps, Certificate $certificado): void
    {
        if ($dps->servico->valorServicos <= 0) {
            throw new \InvalidArgumentException('Valor do serviço deve ser maior que zero.');
        }

        $cnpjCertificado = $certificado->getCNPJ();
        $cnpjEmpresa = preg_replace('/[^0-9]/', '', $dps->empresa->cnpj);

        if (!empty($cnpjCertificado) && $cnpjCertificado !== $cnpjEmpresa) {
            throw new \InvalidArgumentException('CNPJ do certificado é diferente do CNPJ da empresa emissora.');
        }

        if (empty($dps->cliente->documento)) {
            throw new \InvalidArgumentException('Documento do tomador é obrigatório.');
        }
    }

    public function cancelar(string $idNota): bool { return true; }
    public function consultar(string $idNota): array { return []; }
}
