<?php

namespace Tests\Unit;

use App\Services\DTOs\ClienteDTO;
use App\Services\DTOs\EmpresaDTO;
use App\Services\DTOs\ServicoDTO;
use App\Services\NotaNacionalService;
use Illuminate\Support\Facades\Http;
use Tests\Support\CriaCertificadoPfx;
use Tests\TestCase;

class NotaNacionalServiceTest extends TestCase
{
    private string $pfxPath;

    private function empresaDTO(string $cnpj = '07254304000124', string $certPath = null): EmpresaDTO
    {
        return new EmpresaDTO(
            cnpj: $cnpj,
            inscricaoMunicipal: '123456',
            razaoSocial: 'Empresa Teste',
            regimeTributario: '1',
            codigoMunicipio: '3106200',
            cnae: '6201501',
            codigoTributacaoMunicipal: '01.01',
            optanteSimples: true,
            certificadoCaminho: $certPath ?? $this->pfxPath,
            certificadoSenha: 'secret'
        );
    }

    private function clienteDTO(string $documento = '12345678901'): ClienteDTO
    {
        return new ClienteDTO(
            documento: $documento,
            tipoDocumento: 'CPF',
            nome: 'Cliente Teste',
            email: 'cliente@teste.com',
            cep: '30130000',
            logradouro: 'Rua Teste',
            numero: '100',
            bairro: 'Centro',
            cidadeCodigoIbge: '3106200',
            uf: 'MG'
        );
    }

    private function servicoDTO(float $valor = 100.50): ServicoDTO
    {
        return new ServicoDTO(
            codigoServico: '01.01',
            descricao: 'Servico de teste',
            valorServicos: $valor,
            codigoTributacaoMunicipal: '01.01'
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->pfxPath = CriaCertificadoPfx::criar('07254304000124', 'secret');
        config(['nfse.cert_caminho' => $this->pfxPath, 'nfse.cert_senha' => 'secret']);
    }

    protected function tearDown(): void
    {
        if (isset($this->pfxPath) && is_file($this->pfxPath)) {
            @unlink($this->pfxPath);
        }
        parent::tearDown();
    }

    public function test_emitir_nota_certificado_nao_encontrado_retorna_falha(): void
    {
        $empresa = $this->empresaDTO('07254304000124', __DIR__ . '/arquivo_inexistente.pfx');
        $service = new NotaNacionalService();

        $resultado = $service->emitirNota($empresa, $this->clienteDTO(), $this->servicoDTO(), '1', '900');

        $this->assertFalse($resultado['sucesso']);
        $this->assertStringContainsString('Certificado não encontrado', $resultado['mensagem']);
    }

    public function test_emitir_nota_valor_zero_retorna_falha(): void
    {
        $service = new NotaNacionalService();
        $resultado = $service->emitirNota(
            $this->empresaDTO(),
            $this->clienteDTO(),
            $this->servicoDTO(0),
            '1',
            '900'
        );

        $this->assertFalse($resultado['sucesso']);
        $this->assertStringContainsString('Valor do serviço deve ser maior que zero', $resultado['mensagem']);
    }

    public function test_emitir_nota_valor_negativo_retorna_falha(): void
    {
        $service = new NotaNacionalService();
        $resultado = $service->emitirNota(
            $this->empresaDTO(),
            $this->clienteDTO(),
            $this->servicoDTO(-1.00),
            '1',
            '900'
        );

        $this->assertFalse($resultado['sucesso']);
        $this->assertStringContainsString('Valor do serviço deve ser maior que zero', $resultado['mensagem']);
    }

    public function test_emitir_nota_documento_tomador_vazio_retorna_falha(): void
    {
        $service = new NotaNacionalService();
        $cliente = $this->clienteDTO('');
        $resultado = $service->emitirNota($this->empresaDTO(), $cliente, $this->servicoDTO(), '1', '900');

        $this->assertFalse($resultado['sucesso']);
        $this->assertStringContainsString('Documento do tomador é obrigatório', $resultado['mensagem']);
    }

    public function test_emitir_nota_cnpj_certificado_diferente_empresa_retorna_falha(): void
    {
        $pfxOutroCnpj = CriaCertificadoPfx::criar('11111111000191', 'secret');
        try {
            $empresa = $this->empresaDTO('07254304000124', $pfxOutroCnpj);
            $service = new NotaNacionalService();
            $resultado = $service->emitirNota($empresa, $this->clienteDTO(), $this->servicoDTO(), '1', '900');
            $this->assertFalse($resultado['sucesso']);
            $this->assertStringContainsString('CNPJ do certificado é diferente', $resultado['mensagem']);
        } finally {
            if (is_file($pfxOutroCnpj)) {
                @unlink($pfxOutroCnpj);
            }
        }
    }

    public function test_emitir_nota_sucesso_preenche_id_dps_numero_serie_data_competencia(): void
    {
        Http::fake([
            '*' => Http::response('{"chaveAcesso":"CHAVE123","protocolo":"PROT456","mensagem":"OK"}', 200, ['Content-Type' => 'application/json']),
        ]);

        $service = new NotaNacionalService();
        $resultado = $service->emitirNota(
            $this->empresaDTO(),
            $this->clienteDTO(),
            $this->servicoDTO(),
            '80',
            '900'
        );

        $this->assertTrue($resultado['sucesso']);
        $this->assertArrayHasKey('id_dps', $resultado);
        $this->assertStringStartsWith('DPS', $resultado['id_dps']);
        $this->assertSame(80, $resultado['numero']);
        $this->assertSame('900', $resultado['serie']);
        $this->assertArrayHasKey('data_emissao', $resultado);
        $this->assertArrayHasKey('competencia', $resultado);
    }

    public function test_emitir_nota_falha_api_retorna_sucesso_false_e_mensagem(): void
    {
        Http::fake([
            '*' => Http::response('{"Codigo":"E0001","Descricao":"Erro de validação"}', 400, ['Content-Type' => 'application/json']),
        ]);

        $service = new NotaNacionalService();
        $resultado = $service->emitirNota($this->empresaDTO(), $this->clienteDTO(), $this->servicoDTO(), '1', '900');

        $this->assertFalse($resultado['sucesso']);
        $this->assertNotEmpty($resultado['mensagem']);
        $this->assertNotEmpty($resultado['erros'] ?? []);
    }

    public function test_cancelar_retorna_true(): void
    {
        $service = new NotaNacionalService();
        $this->assertTrue($service->cancelar('id-qualquer'));
    }

    public function test_consultar_retorna_array_vazio(): void
    {
        $service = new NotaNacionalService();
        $r = $service->consultar('id-qualquer');
        $this->assertIsArray($r);
        $this->assertEmpty($r);
    }
}
