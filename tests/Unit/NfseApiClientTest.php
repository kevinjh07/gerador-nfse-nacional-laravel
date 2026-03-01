<?php

namespace Tests\Unit;

use App\Services\NfseApiClient;
use Illuminate\Support\Facades\Http;
use Tests\Support\CriaCertificadoPfx;
use Tests\TestCase;

class NfseApiClientTest extends TestCase
{
    private string $pfxPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pfxPath = CriaCertificadoPfx::criar('07254304000124', 'secret');
    }

    protected function tearDown(): void
    {
        if (isset($this->pfxPath) && is_file($this->pfxPath)) {
            @unlink($this->pfxPath);
        }
        parent::tearDown();
    }

    public function test_emitir_dps_sem_certificado_configurado_retorna_erro(): void
    {
        config(['nfse.cert_caminho' => null, 'nfse.cert_senha' => null]);
        $client = new NfseApiClient();

        $resultado = $client->emitirDpsHomologacao('<DPS/>');

        $this->assertFalse($resultado['sucesso']);
        $this->assertStringContainsString('Certificado não configurado', $resultado['mensagem']);
    }

    public function test_emitir_dps_senha_vazia_retorna_erro(): void
    {
        config(['nfse.cert_caminho' => $this->pfxPath, 'nfse.cert_senha' => '']);
        $client = new NfseApiClient();

        $resultado = $client->emitirDpsHomologacao('<DPS/>');

        $this->assertFalse($resultado['sucesso']);
        $this->assertStringContainsString('Certificado não configurado', $resultado['mensagem']);
    }

    public function test_emitir_dps_arquivo_pfx_inexistente_retorna_erro(): void
    {
        config(['nfse.cert_caminho' => __DIR__ . '/nao_existe.pfx', 'nfse.cert_senha' => 'x']);
        $client = new NfseApiClient();

        $resultado = $client->emitirDpsHomologacao('<DPS/>');

        $this->assertFalse($resultado['sucesso']);
        $this->assertStringContainsString('não encontrado', $resultado['mensagem']);
    }

    public function test_emitir_dps_pfx_senha_incorreta_retorna_erro(): void
    {
        config(['nfse.cert_caminho' => $this->pfxPath, 'nfse.cert_senha' => 'senha_errada']);
        $client = new NfseApiClient();

        $resultado = $client->emitirDpsHomologacao('<DPS/>');

        $this->assertFalse($resultado['sucesso']);
        $this->assertStringContainsString('pfx', strtolower($resultado['mensagem']));
    }

    public function test_emitir_dps_sucesso_parse_resposta_json(): void
    {
        config(['nfse.cert_caminho' => $this->pfxPath, 'nfse.cert_senha' => 'secret']);
        Http::fake([
            '*' => Http::response('{"chaveAcesso":"CHAVE123","protocolo":"PROT456","mensagem":"NFS-e recebida"}', 200, ['Content-Type' => 'application/json']),
        ]);

        $client = new NfseApiClient();
        $resultado = $client->emitirDpsHomologacao('<DPS xmlns="http://www.sped.fazenda.gov.br/nfse"><infDPS Id="DPS001"/></DPS>');

        $this->assertTrue($resultado['sucesso']);
        $this->assertSame('CHAVE123', $resultado['chave_acesso']);
        $this->assertSame('PROT456', $resultado['protocolo']);
    }

    public function test_emitir_dps_resposta_erro_http_400_parse_erro(): void
    {
        config(['nfse.cert_caminho' => $this->pfxPath, 'nfse.cert_senha' => 'secret']);
        Http::fake([
            '*' => Http::response('{"Codigo":"E0001","Descricao":"Erro de validação"}', 400),
        ]);

        $client = new NfseApiClient();
        $resultado = $client->emitirDpsHomologacao('<DPS/>');

        $this->assertFalse($resultado['sucesso']);
        $this->assertNotEmpty($resultado['erros']);
    }

    public function test_consultar_por_chave_acesso_sem_cert_retorna_erro(): void
    {
        config(['nfse.cert_caminho' => null, 'nfse.cert_senha' => null]);
        $client = new NfseApiClient();

        $resultado = $client->consultarPorChaveAcesso('12345678901234567890123456789012345678901234');

        $this->assertFalse($resultado['sucesso']);
        $this->assertStringContainsString('Certificado', $resultado['mensagem']);
    }

    public function test_consultar_por_chave_acesso_sucesso(): void
    {
        config(['nfse.cert_caminho' => $this->pfxPath, 'nfse.cert_senha' => 'secret']);
        Http::fake([
            '*' => Http::response('{"chaveAcesso":"CHAVE123","protocolo":"P1","mensagem":"OK"}', 200),
        ]);

        $client = new NfseApiClient();
        $resultado = $client->consultarPorChaveAcesso('CHAVE123');

        $this->assertTrue($resultado['sucesso']);
        $this->assertSame('CHAVE123', $resultado['chave_acesso']);
    }

    public function test_consultar_por_id_dps_sem_url_consulta_retorna_erro(): void
    {
        config(['nfse.url_consulta' => null]);
        $client = new NfseApiClient();

        $resultado = $client->consultarPorIdDps('DPS000000000000000000000000000000000000000000000001');

        $this->assertFalse($resultado['sucesso']);
        $this->assertStringContainsString('NFSE_URL_CONSULTA', $resultado['mensagem']);
    }

    public function test_consultar_por_id_dps_sem_cert_retorna_erro(): void
    {
        config(['nfse.url_consulta' => 'https://sefin.example.com/consulta', 'nfse.cert_caminho' => null, 'nfse.cert_senha' => null]);
        $client = new NfseApiClient();

        $resultado = $client->consultarPorIdDps('DPS001');

        $this->assertFalse($resultado['sucesso']);
    }

    public function test_parse_resposta_sucesso_body_vazio(): void
    {
        config(['nfse.cert_caminho' => $this->pfxPath, 'nfse.cert_senha' => 'secret']);
        Http::fake(['*' => Http::response('', 200)]);

        $client = new NfseApiClient();
        $resultado = $client->emitirDpsHomologacao('<DPS/>');

        $this->assertTrue($resultado['sucesso']);
        $this->assertArrayNotHasKey('chave_acesso', $resultado);
    }

    public function test_sanitiza_xml_remove_bom_e_trim(): void
    {
        config(['nfse.cert_caminho' => $this->pfxPath, 'nfse.cert_senha' => 'secret']);
        $xmlComBom = "\xEF\xBB\xBF  <DPS/>  ";
        Http::fake(['*' => Http::response('{"mensagem":"OK"}', 200)]);

        $client = new NfseApiClient();
        $resultado = $client->emitirDpsHomologacao($xmlComBom);

        $this->assertTrue($resultado['sucesso']);
    }

    public function test_emitir_dps_resposta_sucesso_xml_parse_chave_e_protocolo(): void
    {
        config(['nfse.cert_caminho' => $this->pfxPath, 'nfse.cert_senha' => 'secret']);
        $xmlResposta = '<?xml version="1.0"?><resposta><chaveAcesso>CHAVE-XML</chaveAcesso><protocolo>PROT-XML</protocolo></resposta>';
        Http::fake(['*' => Http::response($xmlResposta, 200)]);

        $client = new NfseApiClient();
        $resultado = $client->emitirDpsHomologacao('<DPS/>');

        $this->assertTrue($resultado['sucesso']);
        $this->assertSame('CHAVE-XML', $resultado['chave_acesso']);
        $this->assertSame('PROT-XML', $resultado['protocolo']);
    }

    public function test_parse_resposta_erro_body_vazio_retorna_http_status(): void
    {
        config(['nfse.cert_caminho' => $this->pfxPath, 'nfse.cert_senha' => 'secret']);
        Http::fake(['*' => Http::response('', 500)]);

        $client = new NfseApiClient();
        $resultado = $client->emitirDpsHomologacao('<DPS/>');

        $this->assertFalse($resultado['sucesso']);
        $this->assertNotEmpty($resultado['erros']);
    }
}
