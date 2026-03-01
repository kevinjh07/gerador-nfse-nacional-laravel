<?php

namespace Tests\Unit;

use App\Models\NfseEmitida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NfseEmitidaTest extends TestCase
{
    use RefreshDatabase;

    public function test_fillable_e_casts(): void
    {
        $nota = NfseEmitida::create([
            'numero' => 1,
            'serie' => '900',
            'id_dps' => 'DPS310620010725430400012400090000000000000001',
            'chave_acesso' => 'CHAVE123',
            'protocolo' => 'PROT456',
            'ambiente' => 1,
            'data_emissao' => '2025-01-15 10:00:00',
            'competencia' => '2025-01-15',
            'valor_servico' => 100.50,
            'prestador_cnpj' => '07254304000124',
            'tomador_documento' => '12345678901',
            'tomador_nome' => 'Cliente Teste',
            'descricao_servico' => 'Servico de teste',
        ]);

        $this->assertSame(1, $nota->numero);
        $this->assertSame('900', $nota->serie);
        $this->assertSame('CHAVE123', $nota->chave_acesso);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $nota->data_emissao);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $nota->competencia);
        $this->assertSame('100.50', (string) $nota->valor_servico);
    }

    public function test_campos_opcionais_nullable(): void
    {
        $nota = NfseEmitida::create([
            'numero' => 2,
            'serie' => '900',
            'id_dps' => 'DPS001',
            'chave_acesso' => null,
            'protocolo' => null,
            'ambiente' => 1,
            'data_emissao' => now(),
            'competencia' => now()->toDateString(),
            'valor_servico' => 50,
            'prestador_cnpj' => '07254304000124',
            'tomador_documento' => '12345678901',
            'tomador_nome' => 'Cliente',
            'descricao_servico' => null,
        ]);

        $this->assertNull($nota->chave_acesso);
        $this->assertNull($nota->protocolo);
        $this->assertNull($nota->descricao_servico);
    }
}
