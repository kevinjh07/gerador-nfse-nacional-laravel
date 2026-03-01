<?php

namespace App\Services;

use NFePHP\Common\Certificate;
use NFePHP\Common\Signer;

class XmlSigner
{
    /**
     * Assina a string XML usando a classe nativa Signer do NFePHP,
     * que é blindada contra a geração de IDs pfx... e não quebra o lacre C14N.
     */
    public function sign(string $xml, Certificate $certificate): string
    {
        // 1. Limpa o XML de qualquer espaço ou quebra de linha espúria antes de assinar
        $xml = str_replace(["\r\n", "\r", "\n", "\t"], '', $xml);
        $xml = preg_replace('/>\s+</', '><', trim($xml));

        // 2. Assina o XML usando o método ESTÁTICO nativo do NFePHP.
        // $canonical deve ser [true,false,null,null] (C14N inclusivo sem comentários).
        // Canonical[0]=true → C14N inclusivo. Canonical[1]=false → sem comentários.
        $xmlAssinado = Signer::sign(
            $certificate,
            $xml,
            'infDPS',
            'Id',
            OPENSSL_ALGO_SHA1,
            [true, false, null, null]
        );

        // 3. NFePHP retorna sem a declaração XML (LIBXML_NOXMLDECL); SEFIN exige a declaração para detectar UTF-8 (E6154).
        $xmlAssinado = ltrim($xmlAssinado);
        $xmlDecl = '<' . '?xml version="1.0" encoding="UTF-8"?' . '>';
        if (strpos($xmlAssinado, '<' . '?xml') !== 0) {
            $xmlAssinado = $xmlDecl . $xmlAssinado;
        }

        return $xmlAssinado;
    }
}
