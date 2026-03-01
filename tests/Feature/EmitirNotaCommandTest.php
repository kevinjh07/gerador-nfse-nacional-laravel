<?php

namespace Tests\Feature;

use App\Models\NfseSequencia;
use App\Services\NotaNacionalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CriaCertificadoPfx;
use Tests\TestCase;

class EmitirNotaCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $pfxPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pfxPath = CriaCertificadoPfx::criar('07254304000124', 'secret');
        $this->setEnvVars();
    }

    protected function tearDown(): void
    {
        if (isset($this->pfxPath) && is_file($this->pfxPath)) {
            @unlink($this->pfxPath);
        }
        parent::tearDown();
    }

    private function setEnvVars(): void
    {
        $path = $this->pfxPath;
        if (str_contains($path, '\\')) {
            $path = str_replace('\\', '/', $path);
        }
        putenv("NFSE_CERT_CAMINHO={$path}");
        putenv('NFSE_CERT_SENHA=secret');
        putenv('NFSE_EMPRESA_CNPJ=07254304000124');
        putenv('NFSE_EMPRESA_MUNICIPIO=3106200');
        putenv('NFSE_EMPRESA_IM=123456');
        putenv('NFSE_EMPRESA_RAZAO=Empresa Teste');
        putenv('NFSE_EMPRESA_REGIME=1');
        putenv('NFSE_EMPRESA_CNAE=6201501');
        putenv('NFSE_EMPRESA_CTRIB_MUN=01.01');
        putenv('NFSE_EMPRESA_OPTANTE_SIMPLES=true');
        putenv('NFSE_CLIENTE_DOCUMENTO=12345678901');
        putenv('NFSE_CLIENTE_NOME=Cliente Teste');
        putenv('NFSE_CLIENTE_EMAIL=cliente@teste.com');
        putenv('NFSE_SERVICO_CODIGO=01.01');
        putenv('NFSE_SERVICO_DESCRICAO=Servico');
        putenv('NFSE_SERVICO_VALOR=100.50');
        putenv('NFSE_SERVICO_CTRIB_MUN=01.01');
    }

    public function test_sem_nfse_cert_caminho_retorna_erro_exit_1(): void
    {
        $this->limparEnv('NFSE_CERT_CAMINHO');
        putenv('NFSE_CERT_CAMINHO=');
        putenv('NFSE_CERT_SENHA=secret');
        $_ENV['NFSE_CERT_SENHA'] = 'secret';

        $this->artisan('fiscal:emitir-nota')->assertExitCode(1);
    }

    public function test_sem_nfse_cert_senha_retorna_erro(): void
    {
        $this->limparEnv('NFSE_CERT_SENHA');
        putenv('NFSE_CERT_SENHA=');

        $this->artisan('fiscal:emitir-nota')->assertExitCode(1);
    }

    public function test_sem_variavel_obrigatoria_cliente_retorna_erro(): void
    {
        $this->limparEnv('NFSE_CLIENTE_NOME');
        putenv('NFSE_CLIENTE_NOME=');

        $this->artisan('fiscal:emitir-nota')->assertExitCode(1);
    }

    private function limparEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    public function test_emissao_sucesso_persiste_nfse_emitida_e_exibe_sucesso(): void
    {
        $this->mock(NotaNacionalService::class, function ($mock) {
            $mock->shouldReceive('emitirNota')
                ->once()
                ->andReturn([
                    'sucesso' => true,
                    'mensagem' => 'NFS-e recebida.',
                    'chave_acesso' => 'CHAVE123',
                    'protocolo' => 'PROT456',
                    'id_dps' => 'DPS001',
                    'numero' => 1,
                    'serie' => '900',
                    'data_emissao' => new \DateTime('2025-01-15 10:00:00'),
                    'competencia' => '2025-01-15',
                ]);
        });

        $this->artisan('fiscal:emitir-nota')->assertExitCode(0);

        $this->assertDatabaseHas('nfse_emitidas', ['numero' => 1, 'serie' => '900', 'chave_acesso' => 'CHAVE123']);
    }

    public function test_emissao_falha_nao_persiste_e_exibe_falha(): void
    {
        $this->mock(NotaNacionalService::class, function ($mock) {
            $mock->shouldReceive('emitirNota')
                ->once()
                ->andReturn([
                    'sucesso' => false,
                    'mensagem' => 'Erro de validação',
                    'erros' => [['descricao' => 'Campo inválido']],
                ]);
        });

        $this->artisan('fiscal:emitir-nota')->assertExitCode(0);

        $this->assertDatabaseCount('nfse_emitidas', 0);
    }

    public function test_emissao_sucesso_obtem_proximo_numero_da_serie(): void
    {
        putenv('NFSE_SERIE_DPS=901');
        $_ENV['NFSE_SERIE_DPS'] = '901';
        $_SERVER['NFSE_SERIE_DPS'] = '901';
        NfseSequencia::create(['serie' => '901', 'proximo_numero' => 3]);

        $this->mock(NotaNacionalService::class, function ($mock) {
            $mock->shouldReceive('emitirNota')
                ->once()
                ->with(
                    \Mockery::type(\App\Services\DTOs\EmpresaDTO::class),
                    \Mockery::type(\App\Services\DTOs\ClienteDTO::class),
                    \Mockery::type(\App\Services\DTOs\ServicoDTO::class),
                    '3',
                    '901'
                )
                ->andReturn(['sucesso' => true, 'mensagem' => 'OK', 'id_dps' => 'DPS001', 'numero' => 3, 'serie' => '901', 'data_emissao' => new \DateTime, 'competencia' => date('Y-m-d')]);
        });

        $this->artisan('fiscal:emitir-nota')->assertExitCode(0);
    }
}
