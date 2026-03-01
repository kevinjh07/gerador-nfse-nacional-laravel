<?php

namespace Tests\Support;

/**
 * Cria um arquivo .pfx temporário para testes (certificado autoassinado).
 * O CN do certificado pode ser definido (ex.: CNPJ para validar getCNPJ no NFePHP).
 */
final class CriaCertificadoPfx
{
    /**
     * Gera um .pfx e retorna o caminho do arquivo. O certificado terá subject CN = $commonName.
     *
     * @return string Caminho do arquivo .pfx temporário
     */
    public static function criar(string $commonName = '07254304000124', string $senha = 'secret'): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new \RuntimeException('Falha ao criar chave: ' . openssl_error_string());
        }

        $dn = ['commonName' => $commonName];
        $csr = openssl_csr_new($dn, $key);
        if ($csr === false) {
            throw new \RuntimeException('Falha ao criar CSR: ' . openssl_error_string());
        }

        $x509 = openssl_csr_sign($csr, null, $key, 1);
        if ($x509 === false) {
            throw new \RuntimeException('Falha ao assinar certificado');
        }

        openssl_x509_export($x509, $certPem);
        openssl_pkey_export($key, $keyPem);

        $pfxContent = '';
        if (!openssl_pkcs12_export($certPem, $pfxContent, $keyPem, $senha)) {
            throw new \RuntimeException('Falha ao exportar PKCS12: ' . openssl_error_string());
        }

        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nfse_test_' . uniqid() . '.pfx';
        file_put_contents($tmp, $pfxContent);
        return $tmp;
    }
}
