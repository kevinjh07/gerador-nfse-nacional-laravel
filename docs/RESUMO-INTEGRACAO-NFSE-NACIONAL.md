# Resumo técnico da integração NFSe Nacional (conferência com documentação oficial)

Documento para cotejo com os PDFs da documentação do Sistema Nacional NFSe, com foco em identificar ajustes necessários para resolver o erro **E4007 – "Não foi possível obter o certificado de cliente."**

---

## 1. Objetivo da integração

- **Comando:** `php artisan fiscal:emitir-nota`
- **Fluxo:** Montar DPS (Declaração de Prestação de Serviço) → gerar XML → assinar com certificado A1 (XMLDSig) → enviar à API de emissão em ambiente de **homologação (produção restrita)** via **mTLS**.
- **Ambiente atual:** Produção restrita (homologação). Certificado e dados vêm do `.env`.

---

## 2. Certificado digital

- **Formato de entrada:** arquivo **PFX (PKCS#12)** com senha, caminho e senha definidos em `NFSE_CERT_CAMINHO` e `NFSE_CERT_SENHA`.
- **Uso do certificado:**
  1. **Assinatura do XML da DPS:** biblioteca NFePHP (`Certificate::readPfx`) + robrichards/xmlseclibs (XMLDSig). Certificado usado para assinar o elemento raiz do XML.
  2. **mTLS na chamada HTTP:** certificado e chave privada enviados na conexão TLS com o servidor da API.
- **Extração para mTLS:**
  - **Preferencial:** `openssl_pkcs12_read()` no conteúdo do PFX para obter `cert`, `pkey` e `extracerts`. Montamos um PEM de certificado = `cert` + todos os `extracerts` (cadeia). Chave exportada com `openssl_pkey_export()`.
  - **Fallback:** se `openssl_pkcs12_read` falhar, usamos apenas o certificado e a chave expostos pelo NFePHP (`publicKey` e `privateKey` em PEM).
- **Formato enviado no mTLS:** dois arquivos temporários em disco: um com o PEM do(s) certificado(s) (titular + cadeia quando há extracerts), outro com o PEM da chave privada (PRIVATE KEY ou RSA PRIVATE KEY). Quebras de linha normalizadas para `\n` (Unix).
- **Observação:** O mesmo certificado é aceito no login do portal https://www.nfse.gov.br/EmissorNacional/ (acesso com certificado digital), mas a API em homologação retorna E4007.

---

## 3. Montagem da DPS (XML)

- **Raiz do documento:** elemento `DPS` com filho `infDPS`.
- **Conteúdo de `infDPS`:**
  - **tpAmb:** valor numérico do ambiente (2 para homologação, vindo de `config('nfse.tpAmb.homologacao')`).
  - **dhEmi:** data/hora de emissão em `Y-m-d\TH:i:s` (sem timezone).
  - **ideDPS:** nDPS, serieDPS, tpDPS, natOp, cMunPrest.
  - **emit:** CNPJ (apenas dígitos), IM, xRazao, cMun, cnae, cTribMun, indSimples (S/N).
  - **tom:** xNome, identificacao (CPF ou CNPJ só dígitos), endereco (CEP, xLgr, nro, xBairro, cMun, UF).
  - **serv:** cServ, xDesc, cTribMun.
  - **valores:** vServPrest, vAliqISS (quando há), vISS (quando há), indISSRetido (S/N).
- **Encoding:** UTF-8. DOM sem formatação (sem espaços/indentação extra).
- **Referência de leiaute:** estrutura alinhada ao padrão nacional; nomes de tags e hierarquia seguem convenção SEFIN/ADN (ex.: ANEXO_I-SEFIN_ADN-DPS_NFSe-SNNFSe). Não há namespace no XML gerado (elementos sem prefixo).

---

## 4. Assinatura digital do XML (XMLDSig)

- **Biblioteca:** robrichards/xmlseclibs (XMLSecurityDSig, XMLSecurityKey).
- **Algoritmos:** canonicalização **EXC_C14N**, hash **SHA256**, assinatura **RSA-SHA256**.
- **Referência assinada:** elemento **infDPS** (não o raiz DPS), com `uri` = `#Id` do infDPS (ex.: `#DPS1SN`). Raiz `DPS` só com atributo `versao` (schema não aceita `Id` no raiz).
- **KeyInfo:** certificado X.509 incluído no bloco de assinatura (`add509Cert` com o PEM do certificado público).
- **Assinatura:** anexada como filho do elemento raiz do documento (assinatura envelopada).

---

## 5. Chamada à API de emissão

- **URL em uso (homologação):** `https://sefin.producaorestrita.nfse.gov.br/SefinNacional/nfse` (sem `/API`; configurável via `NFSE_URL_EMISSAO`).
- **Método:** POST.
- **Headers:** `Content-Type: application/json`, `Accept: application/json`.
- **Corpo da requisição (JSON):**
  - Único campo aceito: **`dpsXmlGZipB64`** — string em **Base64** do XML da DPS já assinado, **compactado com GZIP** (`gzencode` nível 9).
- **Cliente HTTP:** Laravel Http facade com mTLS (cert/key = PEM em `storage/app/certificados/`).
- **mTLS:**
  - Opções do cliente: `cert` = caminho do arquivo PEM do certificado (titular + cadeia quando disponível); `ssl_key` = caminho do arquivo PEM da chave privada.
  - **TLS:** `CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2`, `CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1` (forçar HTTP/1.1).
  - Verificação do certificado do servidor: habilitada (`verify => true`).
- **Timeout:** 30 segundos.

---

## 6. Resposta da API e erro E4007

- **Comportamento observado:** a aplicação recebe **HTTP 403** com corpo JSON contendo algo como:
  - `"Codigo": "E4007"`
  - `"Descricao": "Não foi possível obter o certificado de cliente."`
- **Testes realizados com curl (mesmos PEMs):**
  - Handshake TLS completa com sucesso (Client key exchange, Finished em ambos os lados).
  - Requisição enviada com `--cert` e `--key` (HTTP/1.1 e HTTP/2 testados).
  - Resposta continua 403 + E4007.
- **Conclusão operacional:** o certificado está sendo apresentado na camada TLS; a aplicação (IIS/ASP.NET) responde como se não tivesse certificado de cliente, sugerindo regra ou configuração do ambiente (ex.: whitelist, certificado não habilitado para API, ou problema no repasse do client cert pela infraestrutura).

---

## 7. Pontos a conferir na documentação (para resolver E4007)

Sugestão de tópicos para buscar nos PDFs e validar com a documentação oficial:

1. **Requisitos do certificado para a API (produção restrita / homologação)**  
   - Tipo (A1, A3), emissor (ICP-Brasil), e se há exigência de **cadastro/habilitação prévia** do certificado para uso via API (diferente do uso no Emissor Web).
   - Se o ambiente de homologação aceita qualquer certificado A1 ICP-Brasil ou se há **lista restrita** (whitelist por thumbprint, CN, etc.).

2. **Formato e uso do certificado no mTLS**  
   - Se a API exige **apenas o certificado do titular** ou **cadeia completa** (titular + intermediárias).
   - Se há exigência de **formato específico** (ex.: ordem dos certificados no PEM, ou envio em header adicional além do mTLS).

3. **URL e ambiente**  
   - Se a emissão em homologação deve usar **SEFIN** (`sefin.producaorestrita.nfse.gov.br/API/SefinNacional/nfse`) ou **ADN Contribuintes** (ex.: `adn.producaorestrita.nfse.gov.br/contribuintes/...`), e se o uso do certificado difere entre eles.

4. **Formato do corpo do POST**  
   - Nome exato do campo no JSON (confirmar se é `xml` ou outro, ex.: `dps`, `arquivo`, `conteudo`).
   - Se é obrigatório **GZip + Base64** (e em que condições) e se o campo `compactado` (ou equivalente) é exigido pela documentação.

5. **Código E4007 na documentação**  
   - Definição oficial de E4007 e **ações corretivas** indicadas (ex.: usar outro certificado, cadastrar o certificado em algum painel, usar outro endpoint ou ambiente).

6. **Leiaute do XML da DPS**  
   - Nome do elemento raiz (ex.: `DPS` vs outro nome ou namespace).
   - Obrigatoriedade de **namespaces** e **esquema XSD** a que o XML deve obedecer (para checar se nosso `DpsXmlBuilder` está alinhado).

7. **Assinatura XML (XMLDSig)**  
   - Algoritmos exigidos (canonicalização, hash, assinatura) e posicionamento da assinatura (elemento referenciado, inclusão de X509 no KeyInfo) conforme manual técnico.

---

## 8. Stack e ambiente

- **Runtime:** PHP 8.1 (container Docker).
- **Bibliotecas:** Laravel 8, Guzzle 7, NFePHP Common (certificado), robrichards/xmlseclibs (XMLDSig).
- **Configuração:** `config/nfse.php` + variáveis de ambiente (NFSE_* no `.env`).

---

---

## 9. Status atual e RNG9999

- **mTLS e payload:** URL sem `/API`, corpo `dpsXmlGZipB64` (GZIP+Base64), certificado PEM com cadeia completa — a API aceita a requisição e processa o XML.
- **Schema/assinatura:** `DPS versao="1.00"`, `infDPS Id="DPS1SN"`, assinatura com `Reference URI="#DPS1SN"`, namespace da assinatura sem prefixo (E6155 resolvido). Não há mais erro de schema (RNG6110) nem de prefixo.
- **Erro atual:** **RNG9999** — "Erro não catalogado" / "Ocorreu um erro inesperado" (HTTP 500). Indica exceção não tratada no servidor SEFIN ao processar o documento (após validação de schema e assinatura).

**Próximos passos sugeridos:**

1. **Conferir leiaute com a documentação oficial**
   - Baixar o **ANEXO_I** (DPS NFSe) e o **DPS_v1.00.xsd** em: https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual  
   - Verificar a **ordem exata dos elementos** dentro de `infDPS` (o XSD define sequência; ordem errada pode causar falha no parser do servidor).
   - Confirmar nomes de tags, obrigatoriedade de campos e formatos (datas, códigos, tamanhos).

2. **Contatar o suporte do Sistema Nacional NFSe**
   - Informar o código **RNG9999** e que o XML passa na validação de schema e assinatura.
   - Enviar um XML de exemplo (pode ofuscar dados sensíveis ou remover o bloco `SignatureValue`/certificado se solicitado) para análise do erro no backend.

3. **Debug local**
   - Com `NFSE_DEBUG_XML=true`, o XML enviado fica em `storage/logs/nfse-dps-enviado-*.xml` para comparação com exemplos oficiais ou envio ao suporte.

---

*Documento gerado para conferência com a documentação oficial do Sistema Nacional NFSe (gov.br/nfse).*
