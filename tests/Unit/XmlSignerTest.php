<?php

namespace Tests\Unit;

use App\Services\DpsXmlBuilder;
use App\Services\DTOs\ClienteDTO;
use App\Services\DTOs\DpsDTO;
use App\Services\DTOs\DpsIdentificacaoDTO;
use App\Services\DTOs\EmpresaDTO;
use App\Services\DTOs\ServicoDTO;
use App\Services\XmlSigner;
use NFePHP\Common\Certificate;
use Tests\Support\CriaCertificadoPfx;
use Tests\TestCase;

class XmlSignerTest extends TestCase
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

    private function criarXmlComInfDps(): string
    {
        $identificacao = new DpsIdentificacaoDTO(
            ambiente: 1,
            numero: '1',
            serie: '900',
            tipo: 'DPS',
            naturezaOperacao: '1',
            dataHoraEmissao: new \DateTime('2025-01-15 10:00:00'),
            municipioPrestacao: '3106200'
        );
        $empresa = new EmpresaDTO(
            cnpj: '07254304000124',
            inscricaoMunicipal: '1',
            razaoSocial: 'Teste',
            regimeTributario: '1',
            codigoMunicipio: '3106200',
            cnae: '6201501',
            codigoTributacaoMunicipal: '01.01',
            optanteSimples: true,
            certificadoCaminho: $this->pfxPath,
            certificadoSenha: 'secret'
        );
        $cliente = new ClienteDTO(
            documento: '12345678901',
            tipoDocumento: 'CPF',
            nome: 'Cliente',
            email: 'c@e.com',
            cep: '30130000',
            logradouro: 'Rua',
            numero: '1',
            bairro: 'Centro',
            cidadeCodigoIbge: '3106200',
            uf: 'MG'
        );
        $servico = new ServicoDTO(
            codigoServico: '01.01',
            descricao: 'Servico',
            valorServicos: 10.00,
            codigoTributacaoMunicipal: '01.01'
        );
        $dps = new DpsDTO($identificacao, $empresa, $cliente, $servico);
        $builder = new DpsXmlBuilder();
        return $builder->build($dps);
    }

    public function test_sign_retorna_xml_com_assinatura_e_declaracao(): void
    {
        $cert = Certificate::readPfx(file_get_contents($this->pfxPath), 'secret');
        $xml = $this->criarXmlComInfDps();
        $signer = new XmlSigner();

        $xmlAssinado = $signer->sign($xml, $cert);

        $this->assertStringContainsString('<?xml', $xmlAssinado);
        $this->assertStringContainsString('Signature', $xmlAssinado);
        $this->assertStringContainsString('SignedInfo', $xmlAssinado);
        $this->assertStringContainsString('infDPS', $xmlAssinado);
    }

    public function test_sign_normaliza_espacamento_antes_de_assinar(): void
    {
        $cert = Certificate::readPfx(file_get_contents($this->pfxPath), 'secret');
        $xml = "  <DPS xmlns=\"http://www.sped.fazenda.gov.br/nfse\">\n\t<infDPS Id=\"DPS001\"></infDPS>\n  </DPS>  ";
        $signer = new XmlSigner();

        $xmlAssinado = $signer->sign($xml, $cert);

        $this->assertStringStartsWith('<?xml', ltrim($xmlAssinado));
        $this->assertStringContainsString('Signature', $xmlAssinado);
    }
}
