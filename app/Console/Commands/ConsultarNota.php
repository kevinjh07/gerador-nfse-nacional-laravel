<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\NfseApiClient;

class ConsultarNota extends Command
{
    protected $signature = 'nfse:consultar
                            {valor? : idDPS (começa com DPS) ou chave de acesso (só dígitos)}
                            {--chave= : Força consulta por chave de acesso}
                            {--id= : Força consulta por idDPS}';

    protected $description = 'Consulta NFSe pelo idDPS (começa com DPS) ou pela chave de acesso (dígitos). Auto-detecta o tipo.';

    private const MODO_CHAVE = 'chave';
    private const MODO_ID = 'id';

    public function handle(NfseApiClient $client): int
    {
        $exitCode = $this->validarArgumentos();
        if ($exitCode !== null) {
            return $exitCode;
        }

        [$modo, $identificador] = $this->resolverModoEIdentificador();
        $this->informarTipoConsulta($modo, $identificador);

        $resultado = $modo === self::MODO_CHAVE
            ? $client->consultarPorChaveAcesso($identificador)
            : $client->consultarPorIdDps($identificador);

        if ($resultado['sucesso'] ?? false) {
            $this->exibirSucesso($resultado);
        } else {
            $this->exibirFalha($resultado);
            $this->exibirDicaUrlConsulta($resultado);
        }

        return 0;
    }

    /**
     * Verifica se foi informado valor ou opções. Retorna 1 se faltar argumento, null se ok.
     */
    private function validarArgumentos(): ?int
    {
        $valor = $this->argument('valor');
        $chaveOpt = $this->option('chave');
        $idOpt = $this->option('id');

        if (empty($valor) && empty($chaveOpt) && empty($idOpt)) {
            $this->error('Informe o idDPS ou a chave de acesso como argumento.');
            $this->exibirExemplosUso();
            return 1;
        }

        return null;
    }

    private function exibirExemplosUso(): void
    {
        $this->line('Exemplos:');
        $this->line('  php artisan nfse:consultar DPS000000000000000000000000000000000000000000000001');
        $this->line('  php artisan nfse:consultar 00000000000000000000000000000000000000000000000000');
        $this->line('  php artisan nfse:consultar --chave=00000000000000000000000000000000...');
        $this->line('  php artisan nfse:consultar --id=DPS0000000...');
    }

    /**
     * Define modo (chave ou id) e o identificador com base nas opções e no argumento.
     *
     * @return array{0: string, 1: string} [modo, identificador]
     */
    private function resolverModoEIdentificador(): array
    {
        $valor = $this->argument('valor');
        $chaveOpt = $this->option('chave');
        $idOpt = $this->option('id');

        if (! empty($chaveOpt)) {
            return [self::MODO_CHAVE, $chaveOpt];
        }
        if (! empty($idOpt)) {
            return [self::MODO_ID, $idOpt];
        }
        if (strncasecmp((string) $valor, 'DPS', 3) === 0) {
            return [self::MODO_ID, $valor];
        }

        return [self::MODO_CHAVE, $valor];
    }

    private function informarTipoConsulta(string $modo, string $identificador): void
    {
        if ($modo === self::MODO_CHAVE) {
            $this->info('Consultando NFSe pela chave de acesso: ' . $identificador);
        } else {
            $this->info('Consultando DPS pelo idDPS: ' . $identificador);
        }
    }

    private function exibirSucesso(array $resultado): void
    {
        $this->info('Consulta realizada com sucesso.');
        $this->line('---------------------------------------------');

        $campos = $this->extrairCamposResposta($resultado);
        foreach ($campos as $chave => $valor) {
            $this->line($chave . ': ' . $valor);
        }

        $this->line('---------------------------------------------');
    }

    /**
     * Extrai todos os campos da resposta da API em formato chave => valor (string).
     * Se houver resposta_bruta (JSON), decodifica e exibe todos os campos; senão usa o array resultado.
     */
    private function extrairCamposResposta(array $resultado): array
    {
        $bruta = $resultado['resposta_bruta'] ?? null;
        if ($bruta !== null && $bruta !== '') {
            $decoded = json_decode($bruta, true);
            if (is_array($decoded)) {
                return $this->formatarCamposParaExibicao($decoded);
            }
            $xml = @simplexml_load_string($bruta);
            if ($xml !== false) {
                $json = json_decode(json_encode($xml), true);
                if (is_array($json)) {
                    return $this->formatarCamposParaExibicao($json);
                }
            }
        }

        return $this->formatarCamposParaExibicao($resultado);
    }

    /**
     * Converte array em pares chave: valor (valores aninhados em JSON ou notação ponto).
     */
    private function formatarCamposParaExibicao(array $dados, string $prefixo = ''): array
    {
        $saida = [];
        foreach ($dados as $chave => $valor) {
            if ($chave === 'resposta_bruta' || $chave === 'nfseXmlGZipB64') {
                continue;
            }
            $chaveExibir = $prefixo !== '' ? $prefixo . '.' . $chave : $chave;
            if (is_array($valor)) {
                $saida = array_merge(
                    $saida,
                    $this->formatarCamposParaExibicao($valor, $chaveExibir)
                );
            } else {
                $saida[$chaveExibir] = $valor === null ? '' : (string) $valor;
            }
        }
        return $saida;
    }

    private function exibirFalha(array $resultado): void
    {
        $this->error('Falha na consulta.');
        $this->line('Mensagem: ' . ($resultado['mensagem'] ?? 'Erro não informado.'));

        if (! empty($resultado['erros'] ?? [])) {
            $this->line('Detalhes dos erros:');
            foreach ($resultado['erros'] as $erro) {
                $texto = $this->formatarErro($erro);
                $this->line('- ' . $texto);
            }
        }

        if (! empty($resultado['resposta_bruta'] ?? null)) {
            $this->newLine();
            $this->line('Resposta bruta da API (para debug):');
            $this->line($resultado['resposta_bruta']);
        }
    }

    /**
     * @param array|string $erro
     */
    private function formatarErro($erro): string
    {
        if (! is_array($erro)) {
            return (string) $erro;
        }
        $codigo = $erro['codigo'] ?? '';
        $descricao = $erro['descricao'] ?? json_encode($erro);
        return $codigo !== '' ? $codigo . ' - ' . $descricao : $descricao;
    }

    private function exibirDicaUrlConsulta(array $resultado): void
    {
        if (! str_contains($resultado['mensagem'] ?? '', 'NFSE_URL_CONSULTA')) {
            return;
        }
        $this->newLine();
        $this->line('Defina NFSE_URL_CONSULTA no .env com o endpoint oficial de consulta por idDPS.');
    }
}
