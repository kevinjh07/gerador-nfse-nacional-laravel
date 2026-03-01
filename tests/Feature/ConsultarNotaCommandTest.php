<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Support\CriaCertificadoPfx;
use Tests\TestCase;

class ConsultarNotaCommandTest extends TestCase
{
    private string $pfxPath;

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

    public function test_sem_argumento_exibe_erro_e_exemplos(): void
    {
        $this->artisan('fiscal:consultar-nota')->assertExitCode(1);
        $output = Artisan::output();
        $this->assertStringContainsString('Informe o idDPS ou a chave de acesso', $output);
        $this->assertStringContainsString('Exemplos:', $output);
    }

    public function test_argumento_id_dps_consulta_por_id(): void
    {
        config(['nfse.url_consulta' => 'https://sefin.example.com/consulta']);
        Http::fake([
            '*' => Http::response('{"chaveAcesso":"CHAVE123","mensagem":"OK"}', 200),
        ]);

        $this->artisan('fiscal:consultar-nota', ['valor' => 'DPS000000000000000000000000000000000000000000000001'])->assertExitCode(0);
        $output = Artisan::output();
        $this->assertStringContainsString('Consultando DPS pelo idDPS', $output);
        $this->assertStringContainsString('Consulta realizada com sucesso', $output);
    }

    public function test_argumento_chave_consulta_por_chave(): void
    {
        Http::fake([
            '*' => Http::response('{"chaveAcesso":"CHAVE123","mensagem":"OK"}', 200),
        ]);

        $this->artisan('fiscal:consultar-nota', ['valor' => '00000000000000000000000000000000000000000000000000'])->assertExitCode(0);
        $output = Artisan::output();
        $this->assertStringContainsString('Consultando NFSe pela chave de acesso', $output);
        $this->assertStringContainsString('Consulta realizada com sucesso', $output);
    }

    public function test_opcao_chave_forca_consulta_por_chave(): void
    {
        Http::fake(['*' => Http::response('{"mensagem":"OK"}', 200)]);

        $this->artisan('fiscal:consultar-nota', ['--chave' => 'CHAVE123'])->assertExitCode(0);
        $this->assertStringContainsString('chave de acesso', Artisan::output());
    }

    public function test_opcao_id_forca_consulta_por_id(): void
    {
        config(['nfse.url_consulta' => 'https://sefin.example.com/consulta']);
        Http::fake(['*' => Http::response('{"mensagem":"OK"}', 200)]);

        $this->artisan('fiscal:consultar-nota', ['--id' => 'DPS001'])->assertExitCode(0);
        $this->assertStringContainsString('idDPS', Artisan::output());
    }

    public function test_consulta_falha_exibe_mensagem_e_detalhes(): void
    {
        Http::fake([
            '*' => Http::response('{"Codigo":"E404","Descricao":"Não encontrado"}', 404),
        ]);

        $this->artisan('fiscal:consultar-nota', ['valor' => 'CHAVE_INEXISTENTE'])->assertExitCode(0);
        $this->assertStringContainsString('Falha na consulta', Artisan::output());
    }

    public function test_consulta_falha_por_url_consulta_nao_configurada_exibe_dica(): void
    {
        config(['nfse.url_consulta' => null]);
        $this->artisan('fiscal:consultar-nota', ['--id' => 'DPS001'])->assertExitCode(0);
        $output = Artisan::output();
        $this->assertStringContainsString('Falha na consulta', $output);
        $this->assertStringContainsString('NFSE_URL_CONSULTA', $output);
    }
}
