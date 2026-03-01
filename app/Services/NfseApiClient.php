<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NfseApiClient
{
    /**
     * URL de emissão em Produção Restrita (Homologação). Sem /API (firewall corta a cadeia mTLS).
     */
    private const URL_EMISSAO_HOMOLOGACAO = 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional/nfse';

    /**
     * Envia a DPS (XML assinado) para o endpoint de homologação do NFSe Nacional via mTLS.
     * Usa o .pfx (NFSE_CERT_CAMINHO + NFSE_CERT_SENHA); extrai cert/key em tempo de execução para mTLS.
     *
     * @param string $xmlAssinado XML da DPS já assinado (XMLDSig)
     * @return array{sucesso: bool, mensagem: string, chave_acesso?: string, protocolo?: string, erros?: array, resposta_bruta?: string}
     */
    public function emitirDpsHomologacao(string $xmlAssinado): array
    {
        $mtls = $this->resolveMtlsPaths();
        if (isset($mtls['error'])) {
            return $mtls['error'];
        }
        $certPath = $mtls['cert_path'];
        $keyPath = $mtls['key_path'];
        $tempFiles = $mtls['temp_files'];
        $url = config('nfse.url_emissao') ?: self::URL_EMISSAO_HOMOLOGACAO;

        if (!is_readable($certPath) || !is_readable($keyPath)) {
            $this->unlinkTempFiles($tempFiles);
            return [
                'sucesso' => false,
                'mensagem' => 'Certificado ou chave PEM não encontrados ou não legíveis.',
                'erros' => [['descricao' => "Cert: {$certPath} | Key: {$keyPath}"]],
            ];
        }

        try {
            $xmlAssinado = $this->sanitizarXmlParaEnvio($xmlAssinado);

            if (config('nfse.debug_xml', false)) {
                $logPath = storage_path('logs/nfse-dps-enviado-' . date('Y-m-d-His') . '.xml');
                @file_put_contents($logPath, $xmlAssinado);
                Log::info('NFSe XML enviado gravado em ' . $logPath);
            }

            $xmlGz = gzencode($xmlAssinado, 9);
            $base64 = base64_encode($xmlGz);
            $bodyJson = ['dpsXmlGZipB64' => $base64];

            $response = Http::withOptions([
                'verify' => true,
                'cert' => $certPath,
                'ssl_key' => $keyPath,
                'curl' => [
                    \CURLOPT_SSLVERSION => \CURL_SSLVERSION_TLSv1_2,
                    \CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_1_1,
                ],
            ])
                ->acceptJson()
                ->timeout(60)
                ->post($url, $bodyJson);

            $status = $response->status();
            $body = $response->body();

            if ($response->successful()) {
                $this->unlinkTempFiles($tempFiles);
                return $this->parseRespostaSucesso($body);
            }

            $resultado = $this->parseRespostaErro($status, $body, $url);
            $resultado['resposta_bruta'] = $body;
            Log::warning('NFSe API retornou erro', [
                'status' => $status,
                'url' => $url,
                'body' => $body,
            ]);
            $this->unlinkTempFiles($tempFiles);
            return $resultado;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('NFSe API falha de conexão', ['message' => $e->getMessage()]);
            $this->unlinkTempFiles($tempFiles);
            return [
                'sucesso' => false,
                'mensagem' => 'Falha de conexão com o servidor NFSe: ' . $e->getMessage(),
                'erros' => [['descricao' => $e->getMessage()]],
            ];
        } catch (\Exception $e) {
            Log::error('NFSe API exceção', ['message' => $e->getMessage()]);
            $this->unlinkTempFiles($tempFiles);
            return [
                'sucesso' => false,
                'mensagem' => $e->getMessage(),
                'erros' => [['descricao' => $e->getMessage()]],
            ];
        }
    }

    /**
     * Consulta a NFSe pela chave de acesso emitida.
     * Endpoint SEFIN Nacional: GET {URL_EMISSAO}/{chaveAcesso}
     * (mesmo path base da emissão, método GET, conforme documentação oficial).
     *
     * @param string $chaveAcesso Chave de acesso retornada pela SEFIN na emissão
     * @return array{sucesso: bool, mensagem: string, chave_acesso?: string, protocolo?: string, erros?: array, resposta_bruta?: string}
     */
    public function consultarPorChaveAcesso(string $chaveAcesso): array
    {
        // A consulta por chave usa o mesmo endpoint base da emissão (POST /nfse), mas com GET /nfse/{chave}.
        $urlEmissao = config('nfse.url_emissao') ?: self::URL_EMISSAO_HOMOLOGACAO;
        $urlBase = rtrim($urlEmissao, '/');

        $mtls = $this->resolveMtlsPaths();
        if (isset($mtls['error'])) {
            return $mtls['error'];
        }
        $certPath = $mtls['cert_path'];
        $keyPath = $mtls['key_path'];
        $tempFiles = $mtls['temp_files'];
        if (!is_readable($certPath) || !is_readable($keyPath)) {
            $this->unlinkTempFiles($tempFiles);
            return [
                'sucesso' => false,
                'mensagem' => 'Certificado ou chave PEM não encontrados para mTLS.',
                'erros' => [['descricao' => 'Defina NFSE_CERT_CAMINHO e NFSE_CERT_SENHA no .env (arquivo .pfx).']],
            ];
        }

        $url = $urlBase . '/' . ltrim($chaveAcesso, '/');

        try {
            $response = Http::withOptions([
                'verify' => true,
                'cert' => $certPath,
                'ssl_key' => $keyPath,
                'curl' => [
                    \CURLOPT_SSLVERSION => \CURL_SSLVERSION_TLSv1_2,
                    \CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_1_1,
                ],
            ])
                ->acceptJson()
                ->timeout(60)
                ->get($url);

            $body = $response->body();
            if ($response->successful()) {
                $this->unlinkTempFiles($tempFiles);
                $resultado = $this->parseRespostaSucesso($body);
                $resultado['chave_acesso'] = $resultado['chave_acesso'] ?? $chaveAcesso;
                $resultado['resposta_bruta'] = $body;
                return $resultado;
            }
            $this->unlinkTempFiles($tempFiles);
            $resultado = $this->parseRespostaErro($response->status(), $body, $url);
            $resultado['resposta_bruta'] = $body;
            return $resultado;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->unlinkTempFiles($tempFiles);
            Log::error('NFSe API consulta chave - falha de conexão', ['message' => $e->getMessage(), 'chave' => $chaveAcesso]);
            return [
                'sucesso' => false,
                'mensagem' => 'Falha de conexão na consulta: ' . $e->getMessage(),
                'erros' => [['descricao' => $e->getMessage()]],
            ];
        } catch (\Exception $e) {
            $this->unlinkTempFiles($tempFiles);
            Log::error('NFSe API consulta chave - exceção', ['message' => $e->getMessage(), 'chave' => $chaveAcesso]);
            return [
                'sucesso' => false,
                'mensagem' => $e->getMessage(),
                'erros' => [['descricao' => $e->getMessage()]],
            ];
        }
    }

    /**
     * Consulta o status da DPS pelo idDPS (ex.: após timeout no envio, para verificar se a nota já foi processada).
     * Requer NFSE_URL_CONSULTA configurado no .env. Usa o mesmo mTLS e timeout de 60 segundos.
     *
     * @param string $idDps Id da DPS (ex.: DPS000000000000000000000000000000000000000000000001)
     * @return array{sucesso: bool, mensagem: string, chave_acesso?: string, protocolo?: string, erros?: array, resposta_bruta?: string}
     */
    public function consultarPorIdDps(string $idDps): array
    {
        $urlConsulta = config('nfse.url_consulta') ?: env('NFSE_URL_CONSULTA');
        if (empty($urlConsulta)) {
            return [
                'sucesso' => false,
                'mensagem' => 'URL de consulta por idDPS não configurada (NFSE_URL_CONSULTA).',
                'erros' => [['descricao' => 'Defina NFSE_URL_CONSULTA no .env com o endpoint oficial de consulta.']],
            ];
        }

        $mtls = $this->resolveMtlsPaths();
        if (isset($mtls['error'])) {
            return $mtls['error'];
        }
        $certPath = $mtls['cert_path'];
        $keyPath = $mtls['key_path'];
        $tempFiles = $mtls['temp_files'];
        if (!is_readable($certPath) || !is_readable($keyPath)) {
            $this->unlinkTempFiles($tempFiles);
            return [
                'sucesso' => false,
                'mensagem' => 'Certificado ou chave PEM não encontrados para mTLS.',
                'erros' => [['descricao' => 'Defina NFSE_CERT_CAMINHO e NFSE_CERT_SENHA no .env (arquivo .pfx).']],
            ];
        }

        try {
            $response = Http::withOptions([
                'verify' => true,
                'cert' => $certPath,
                'ssl_key' => $keyPath,
                'curl' => [
                    \CURLOPT_SSLVERSION => \CURL_SSLVERSION_TLSv1_2,
                    \CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_1_1,
                ],
            ])
                ->acceptJson()
                ->timeout(60)
                ->get($urlConsulta, ['idDPS' => $idDps]);

            $body = $response->body();
            if ($response->successful()) {
                $this->unlinkTempFiles($tempFiles);
                return $this->parseRespostaSucesso($body);
            }
            $this->unlinkTempFiles($tempFiles);
            return $this->parseRespostaErro($response->status(), $body, $urlConsulta);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->unlinkTempFiles($tempFiles);
            Log::error('NFSe API consulta - falha de conexão', ['message' => $e->getMessage(), 'idDps' => $idDps]);
            return [
                'sucesso' => false,
                'mensagem' => 'Falha de conexão na consulta: ' . $e->getMessage(),
                'erros' => [['descricao' => $e->getMessage()]],
            ];
        } catch (\Exception $e) {
            $this->unlinkTempFiles($tempFiles);
            Log::error('NFSe API consulta - exceção', ['message' => $e->getMessage(), 'idDps' => $idDps]);
            return [
                'sucesso' => false,
                'mensagem' => $e->getMessage(),
                'erros' => [['descricao' => $e->getMessage()]],
            ];
        }
    }

    /**
     * Extrai certificado e chave do .pfx para mTLS (arquivos temporários).
     *
     * @return array{cert_path: string, key_path: string, temp_files: string[]}|array{error: array}
     */
    private function resolveMtlsPaths(): array
    {
        $certCaminho = config('nfse.cert_caminho');
        $certSenha = config('nfse.cert_senha');
        if (empty($certCaminho) || $certSenha === null || $certSenha === '') {
            return [
                'error' => [
                    'sucesso' => false,
                    'mensagem' => 'Certificado não configurado para mTLS.',
                    'erros' => [['descricao' => 'Defina NFSE_CERT_CAMINHO e NFSE_CERT_SENHA no .env (arquivo .pfx).']],
                ],
            ];
        }

        $pfxPath = $this->isAbsolutePath($certCaminho) ? $certCaminho : base_path($certCaminho);
        if (!is_readable($pfxPath)) {
            return [
                'error' => [
                    'sucesso' => false,
                    'mensagem' => 'Arquivo .pfx não encontrado ou não legível.',
                    'erros' => [['descricao' => "Arquivo esperado: {$pfxPath} (NFSE_CERT_CAMINHO)."]],
                ],
            ];
        }

        $pkcs12 = file_get_contents($pfxPath);
        $certs = [];
        if (!openssl_pkcs12_read($pkcs12, $certs, $certSenha)) {
            return [
                'error' => [
                    'sucesso' => false,
                    'mensagem' => 'Não foi possível ler o .pfx (senha incorreta ou arquivo inválido).',
                    'erros' => [['descricao' => 'Verifique NFSE_CERT_SENHA no .env.']],
                ],
            ];
        }

        $certPemContent = $certs['cert'] ?? '';
        $keyPemContent = $certs['pkey'] ?? '';
        if ($certPemContent === '' || $keyPemContent === '') {
            return [
                'error' => [
                    'sucesso' => false,
                    'mensagem' => 'Conteúdo do .pfx inválido (cert ou chave não encontrados).',
                    'erros' => [['descricao' => 'Verifique o arquivo .pfx.']],
                ],
            ];
        }

        $tmpDir = sys_get_temp_dir();
        $prefix = 'nfse_mtls_' . getmypid() . '_';
        $tmpCert = $tmpDir . DIRECTORY_SEPARATOR . $prefix . 'cert.pem';
        $tmpKey = $tmpDir . DIRECTORY_SEPARATOR . $prefix . 'key.pem';
        file_put_contents($tmpCert, $certPemContent);
        file_put_contents($tmpKey, $keyPemContent);
        @chmod($tmpCert, 0600);
        @chmod($tmpKey, 0600);

        return [
            'cert_path' => $tmpCert,
            'key_path' => $tmpKey,
            'temp_files' => [$tmpCert, $tmpKey],
        ];
    }

    private function unlinkTempFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/')) {
            return true;
        }
        return PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }

    /**
     * Sanitiza o XML antes de compactar/enviar: remove BOM UTF-8, trim e xmlns="" vazios
     * para evitar falha de desserialização no servidor (RNG9999).
     */
    private function sanitizarXmlParaEnvio(string $xml): string
    {
        $xml = preg_replace("/^\xEF\xBB\xBF/", '', $xml);
        $xml = trim($xml);
        $xml = preg_replace('/\s+xmlns=""/', '', $xml);
        return $xml;
    }

    private function parseRespostaSucesso(string $body): array
    {
        $resultado = [
            'sucesso' => true,
            'mensagem' => 'NFS-e recebida com sucesso pelo ambiente de homologação.',
        ];

        if ($body === '') {
            return $resultado;
        }

        $json = json_decode($body, true);
        if (is_array($json)) {
            $resultado['chave_acesso'] = $json['chaveAcesso'] ?? $json['chave_acesso'] ?? null;
            $resultado['protocolo'] = $json['protocolo'] ?? null;
            if (!empty($json['mensagem'])) {
                $resultado['mensagem'] = $json['mensagem'];
            }
            return $resultado;
        }

        libxml_use_internal_errors(true);
        $doc = @simplexml_load_string($body);
        if ($doc !== false) {
            $chave = $doc->xpath('//*[local-name()="chaveAcesso"]');
            if (!empty($chave)) {
                $resultado['chave_acesso'] = trim((string) $chave[0]);
            }
            $protocolo = $doc->xpath('//*[local-name()="protocolo"]');
            if (!empty($protocolo)) {
                $resultado['protocolo'] = trim((string) $protocolo[0]);
            }
        }

        return $resultado;
    }

    private function parseRespostaErro(int $status, string $body, string $url = ''): array
    {
        $erros = [];
        if ($body !== '') {
            $json = json_decode($body, true);
            if (is_array($json)) {
                $codigo = $json['Codigo'] ?? $json['codigo'] ?? null;
                $msg = $json['Descricao'] ?? $json['descricao'] ?? $json['message'] ?? $json['mensagem'] ?? null;
                if ($msg !== null || $codigo !== null) {
                    $txt = ($codigo !== null ? "[{$codigo}] " : '') . ($msg ?? '');
                    $erros[] = ['descricao' => is_string($txt) ? $txt : json_encode($json)];
                }
                foreach ($json['erros'] ?? $json['errors'] ?? [] as $e) {
                    $erros[] = ['descricao' => is_array($e) ? ($e['Descricao'] ?? $e['mensagem'] ?? $e['message'] ?? json_encode($e)) : (string) $e];
                }
            }
            if (empty($erros)) {
                $doc = @simplexml_load_string($body);
                if ($doc !== false) {
                    $mensagens = $doc->xpath('//*[local-name()="mensagem" or local-name()="descricao" or local-name()="message"]');
                    foreach ($mensagens as $msg) {
                        $erros[] = ['descricao' => trim((string) $msg)];
                    }
                }
            }
            if (empty($erros)) {
                $erros[] = ['descricao' => $body];
            }
        } else {
            $erros[] = ['descricao' => "HTTP {$status}"];
        }

        if ($status === 404 && $url !== '') {
            $erros[] = ['descricao' => "Endpoint chamado: {$url}. Confira a documentação oficial para o path correto."];
        }

        return [
            'sucesso' => false,
            'mensagem' => 'A API NFSe retornou erro.',
            'erros' => $erros,
        ];
    }
}
