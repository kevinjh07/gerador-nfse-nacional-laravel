<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\NfseApiClient;

class ConsultarNota extends Command
{
    protected $signature = 'fiscal:consultar-nota
                            {valor? : idDPS (começa com DPS) ou chave de acesso (só dígitos)}
                            {--chave= : Força consulta por chave de acesso}
                            {--id= : Força consulta por idDPS}';

    protected $description = 'Consulta NFSe pelo idDPS (começa com DPS) ou pela chave de acesso (dígitos). Auto-detecta o tipo.';

    public function handle(NfseApiClient $client): int
    {
        $valor = $this->argument('valor');
        $chaveOpt = $this->option('chave');
        $idOpt = $this->option('id');

        if (empty($valor) && empty($chaveOpt) && empty($idOpt)) {
            $this->error('Informe o idDPS ou a chave de acesso como argumento.');
            $this->line('Exemplos:');
            $this->line('  php artisan fiscal:consultar-nota DPS000000000000000000000000000000000000000000000001');
            $this->line('  php artisan fiscal:consultar-nota 00000000000000000000000000000000000000000000000000');
            $this->line('  php artisan fiscal:consultar-nota --chave=00000000000000000000000000000000...');
            $this->line('  php artisan fiscal:consultar-nota --id=DPS0000000...');
            return 1;
        }

        // Resolve chave ou idDPS: opções explícitas têm prioridade; fallback: auto-detecção pelo prefixo.
        if (!empty($chaveOpt)) {
            $modo = 'chave';
            $identificador = $chaveOpt;
        } elseif (!empty($idOpt)) {
            $modo = 'id';
            $identificador = $idOpt;
        } elseif (strncasecmp((string) $valor, 'DPS', 3) === 0) {
            $modo = 'id';
            $identificador = $valor;
        } else {
            $modo = 'chave';
            $identificador = $valor;
        }

        if ($modo === 'chave') {
            $this->info('Consultando NFSe pela chave de acesso: ' . $identificador);
            $resultado = $client->consultarPorChaveAcesso($identificador);
        } else {
            $this->info('Consultando DPS pelo idDPS: ' . $identificador);
            $resultado = $client->consultarPorIdDps($identificador);
        }

        if ($resultado['sucesso'] ?? false) {
            $this->info('Consulta realizada com sucesso.');
            $this->line('---------------------------------------------');
            $this->line('Mensagem: ' . ($resultado['mensagem'] ?? ''));

            if (!empty($resultado['chave_acesso'] ?? null)) {
                $this->line('Chave de Acesso: ' . $resultado['chave_acesso']);
            }

            if (!empty($resultado['protocolo'] ?? null)) {
                $this->line('Protocolo: ' . $resultado['protocolo']);
            }

            $this->line('---------------------------------------------');
        } else {
            $this->error('Falha na consulta.');
            $this->line('Mensagem: ' . ($resultado['mensagem'] ?? 'Erro não informado.'));

            if (!empty($resultado['erros'] ?? [])) {
                $this->line('Detalhes dos erros:');
                foreach ($resultado['erros'] as $erro) {
                    if (is_array($erro)) {
                        $codigo = $erro['codigo'] ?? '';
                        $descricao = $erro['descricao'] ?? json_encode($erro);
                        $this->line('- ' . $codigo . ($codigo !== '' ? ' - ' : '') . $descricao);
                    } else {
                        $this->line('- ' . $erro);
                    }
                }
            }

            if (!empty($resultado['resposta_bruta'] ?? null)) {
                $this->newLine();
                $this->line('Resposta bruta da API (para debug):');
                $this->line($resultado['resposta_bruta']);
            }

            if (str_contains($resultado['mensagem'] ?? '', 'NFSE_URL_CONSULTA')) {
                $this->newLine();
                $this->line('Defina NFSE_URL_CONSULTA no .env com o endpoint oficial de consulta por idDPS.');
            }
        }

        return 0;
    }
}
