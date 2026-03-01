<?php

return [
    'ambiente' => env('NFSE_AMBIENTE', 'homologacao'),

    'urls' => [
        'homologacao' => env('NFSE_BASE_URL_HOMOLOGACAO', 'https://adn.producaorestrita.nfse.gov.br'),
        'producao' => env('NFSE_BASE_URL_PRODUCAO', 'https://adn.nfse.gov.br'),
    ],

    'tpAmb' => [
        'homologacao' => 2,
        'producao' => 1,
    ],

    /*
    | URL completa de emissão. Homologação (Produção Restrita): SEFIN Nacional (sem /API).
    */
    'url_emissao' => env('NFSE_URL_EMISSAO', 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional/nfse'),

    /*
    | URL de consulta por idDPS (opcional). Se definida, permite verificar se a DPS já foi processada
    | após um timeout no envio (ex.: NFSE_URL_CONSULTA ou endpoint oficial de consulta por idDPS).
    */
    'url_consulta' => env('NFSE_URL_CONSULTA'),

    /*
    | Certificado A1: .pfx para assinatura do XML e para mTLS (extração em tempo de execução).
    */
    'cert_caminho' => env('NFSE_CERT_CAMINHO'),
    'cert_senha' => env('NFSE_CERT_SENHA'),

    /*
    | Emissão em JSON: API SEFIN exige application/json. XML é enviado em Base64.
    | Se a API exigir GZip+Base64, defina NFSE_EMISSAO_GZIP_BASE64=true no .env.
    */
    'emissao_gzip_base64' => env('NFSE_EMISSAO_GZIP_BASE64', false),

    /*
    | Debug mTLS: quando true, salva cert e key (extraídos do .pfx) em storage/app para testar com curl.
    */
    'debug_mtls' => env('NFSE_DEBUG_MTLS', false),

    /*
    | Debug XML: quando true, grava o XML enviado em storage/logs/nfse-dps-enviado-{Y-m-d-His}.xml
    | para inspeção ou envio ao suporte SEFIN (ex.: erro RNG9999).
    */
    'debug_xml' => env('NFSE_DEBUG_XML', false),
];
