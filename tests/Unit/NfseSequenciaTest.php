<?php

namespace Tests\Unit;

use App\Models\NfseSequencia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NfseSequenciaTest extends TestCase
{
    use RefreshDatabase;

    public function test_proximo_numero_para_serie_nova_cria_registro_e_retorna_1(): void
    {
        $numero = NfseSequencia::proximoNumeroPara('801');

        $this->assertSame(1, $numero);
        $this->assertDatabaseHas('nfse_sequencia', ['serie' => '801', 'proximo_numero' => 2]);
    }

    public function test_proximo_numero_para_serie_existente_incrementa(): void
    {
        NfseSequencia::create(['serie' => '900', 'proximo_numero' => 5]);

        $this->assertSame(5, NfseSequencia::proximoNumeroPara('900'));
        $this->assertSame(6, NfseSequencia::proximoNumeroPara('900'));
        $this->assertSame(7, NfseSequencia::proximoNumeroPara('900'));

        $this->assertDatabaseHas('nfse_sequencia', ['serie' => '900', 'proximo_numero' => 8]);
    }

    public function test_series_diferentes_tem_contagem_independente(): void
    {
        $a1 = NfseSequencia::proximoNumeroPara('A');
        $b1 = NfseSequencia::proximoNumeroPara('B');
        $a2 = NfseSequencia::proximoNumeroPara('A');

        $this->assertSame(1, $a1);
        $this->assertSame(1, $b1);
        $this->assertSame(2, $a2);
    }
}
