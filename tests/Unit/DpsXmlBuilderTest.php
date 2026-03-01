<?php

namespace Tests\Unit;

use App\Services\DpsXmlBuilder;
use App\Services\DTOs\ClienteDTO;
use App\Services\DTOs\DpsDTO;
use App\Services\DTOs\DpsIdentificacaoDTO;
use App\Services\DTOs\EmpresaDTO;
use App\Services\DTOs\ServicoDTO;
use Tests\TestCase;

class DpsXmlBuilderTest extends TestCase
{
    private function createDpsDTO(?string $telefoneCliente = null): DpsDTO
    {
        $empresa = new EmpresaDTO(
            cnpj: '07254304000124',
            inscricaoMunicipal: '123456',
            razaoSocial: 'Empresa Teste',
            regimeTributario: '1',
            codigoMunicipio: '3106200',
            cnae: '6201501',
            codigoTributacaoMunicipal: '01.01',
            optanteSimples: true,
            logradouro: null,
            numero: null,
            bairro: null,
            cep: null,
            uf: null,
            telefone: null,
            email: null,
            certificadoCaminho: '/tmp/cert.pfx',
            certificadoSenha: 'secret'
        );

        $cliente = new ClienteDTO(
            documento: '12345678901',
            tipoDocumento: 'CPF',
            nome: 'Cliente Teste',
            email: 'cliente@teste.com',
            cep: '30130000',
            logradouro: 'Rua Teste',
            numero: '100',
            bairro: 'Centro',
            cidadeCodigoIbge: '3106200',
            uf: 'MG',
            telefone: $telefoneCliente
        );

        $servico = new ServicoDTO(
            codigoServico: '01.01',
            descricao: 'Servico de teste',
            valorServicos: 100.50,
            codigoTributacaoMunicipal: '01.01'
        );

        $identificacao = new DpsIdentificacaoDTO(
            ambiente: 1,
            numero: '80',
            serie: '900',
            tipo: 'DPS',
            naturezaOperacao: '1',
            dataHoraEmissao: new \DateTime('2025-01-15 10:30:00'),
            municipioPrestacao: '3106200'
        );

        return new DpsDTO(
            identificacao: $identificacao,
            empresa: $empresa,
            cliente: $cliente,
            servico: $servico
        );
    }

    public function test_build_gera_xml_com_elementos_obrigatorios(): void
    {
        $builder = new DpsXmlBuilder();
        $dps = $this->createDpsDTO();

        $xml = $builder->build($dps);

        $this->assertStringContainsString('<?xml', $xml);
        $this->assertStringContainsString('<DPS ', $xml);
        $this->assertStringContainsString('versao="1.01"', $xml);
        $this->assertStringContainsString('infDPS', $xml);
        $this->assertStringContainsString('Id="DPS31062001', $xml);
        $this->assertStringContainsString('00900', $xml);
        $this->assertStringContainsString('000000000000080', $xml);
        $this->assertStringContainsString('<tpAmb>1</tpAmb>', $xml);
        $this->assertStringContainsString('<nDPS>80</nDPS>', $xml);
        $this->assertStringContainsString('<serie>900</serie>', $xml);
        $this->assertStringContainsString('<CNPJ>07254304000124</CNPJ>', $xml);
        $this->assertStringContainsString('<CPF>12345678901</CPF>', $xml);
        $this->assertStringContainsString('<xNome>CLIENTE TESTE</xNome>', $xml);
        $this->assertStringContainsString('<email>cliente@teste.com</email>', $xml);
        $this->assertStringContainsString('<vServ>100.50</vServ>', $xml);
        $this->assertStringContainsString('http://www.sped.fazenda.gov.br/nfse', $xml);
    }

    public function test_build_com_cliente_cnpj_usa_tag_cnpj_no_tomador(): void
    {
        $dps = $this->createDpsDTO();
        $dps->cliente->documento = '07254304000124';
        $dps->cliente->tipoDocumento = 'CNPJ';

        $builder = new DpsXmlBuilder();
        $xml = $builder->build($dps);

        $this->assertStringContainsString('<CNPJ>07254304000124</CNPJ>', $xml);
        $this->assertStringNotContainsString('<CPF>', $xml);
    }

    public function test_build_sem_telefone_cliente_nao_inclui_node_fone(): void
    {
        $builder = new DpsXmlBuilder();
        $dps = $this->createDpsDTO(null);

        $xml = $builder->build($dps);

        $this->assertStringNotContainsString('<fone>', $xml);
    }

    public function test_build_com_telefone_cliente_inclui_fone_somente_digitos(): void
    {
        $builder = new DpsXmlBuilder();
        $dps = $this->createDpsDTO('(31) 99999-8888');

        $xml = $builder->build($dps);

        $this->assertStringContainsString('<fone>31999998888</fone>', $xml);
    }

    public function test_build_municipio_vazio_usa_0000000_no_id(): void
    {
        $dps = $this->createDpsDTO();
        $dps->empresa->codigoMunicipio = '';

        $builder = new DpsXmlBuilder();
        $xml = $builder->build($dps);

        $this->assertStringContainsString('Id="DPS0000000', $xml);
    }

    public function test_build_competencia_e_dhEmi_no_fuso_brasilia(): void
    {
        $builder = new DpsXmlBuilder();
        $dps = $this->createDpsDTO();

        $xml = $builder->build($dps);

        $this->assertMatchesRegularExpression('/<dhEmi>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}-\d{2}:\d{2}<\/dhEmi>/', $xml);
        $this->assertStringContainsString('<dCompet>2025-01-15</dCompet>', $xml);
    }

    public function test_build_descricao_servico_normalizada_sem_acentos(): void
    {
        $dps = $this->createDpsDTO();
        $dps->servico->descricao = 'Serviço de consultoria jurídica';

        $builder = new DpsXmlBuilder();
        $xml = $builder->build($dps);

        $this->assertStringContainsString('SERVICO DE CONSULTORIA JURIDICA', $xml);
    }
}
