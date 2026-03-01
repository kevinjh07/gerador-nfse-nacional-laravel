# Emissor de NFSe (Padrão Nacional SEFIN)

Esta prova de conceito tem como objetivo validar a integração com o novo padrão da **Nota Fiscal de Serviço Eletrônica (NFSe) Nacional**. O foco principal é a manipulação de Certificados Digitais A1, estruturação de dados via DTOs, geração e assinatura do XML da DPS (Declaração de Prestação de Serviço) e envio à API SEFIN em ambiente de homologação.

## 🚀 Tecnologias e Stack

* **Framework:** Laravel 8.x
* **Linguagem:** PHP 8.1 (Imagens oficiais CLI)
* **Banco de Dados:** MySQL 8.0
* **Ambiente:** Docker & Docker Compose
* **Bibliotecas Críticas:** * `nfephp-org/sped-common`: Para manipulação de certificados ICP-Brasil.
    * `Extensões PHP`: `bcmath`, `soap`, `openssl`, `pdo_mysql`.

## 🛠️ Configuração do Ambiente Docker

O ambiente foi customizado via `Dockerfile` para incluir o **Composer** e as bibliotecas de sistema necessárias para a assinatura digital e comunicação com o governo.

1.  **Clone o repositório:**
    ```bash
    git clone https://github.com/seu-usuario/gerador-nfse-nacional-laravel.git
    cd gerador-nfse-nacional-laravel
    ```

2.  **Configuração Inicial:**
    ```bash
    cp .env.example .env
    ```
    *Nota: Certifique-se de que `DB_HOST=mysql` e `DB_PORT=3306` estejam no seu `.env`.*

3.  **Build e Inicialização:**
    ```bash
    docker compose up -d --build
    ```

4.  **Dependências e Chaves:**
    ```bash
    docker compose exec app composer install
    docker compose exec app php artisan key:generate
    ```

## 📋 Comandos Artisan (fiscal)

| Comando | Descrição |
|--------|-----------|
| `fiscal:emitir-nota` | Emite NFSe Nacional (homologação por padrão). Gera DPS em XML, assina e envia à API SEFIN. |
| `fiscal:consultar-nota [valor]` | Consulta NFSe por **idDPS** (começa com `DPS`) ou por **chave de acesso** (só dígitos). Auto-detecta o tipo. Opções: `--chave=` e `--id=` para forçar o modo. |

**Exemplos com Docker:**

```bash
# Emitir nota
docker compose exec app php artisan fiscal:emitir-nota

# Consultar por idDPS (argumento posicional; use o id retornado na emissão)
docker compose exec app php artisan fiscal:consultar-nota DPS000000000000000000000000000000000000000000000001

# Consultar por chave de acesso (argumento posicional; auto-detecta)
docker compose exec app php artisan fiscal:consultar-nota 00000000000000000000000000000000000000000000000000

# Forçar consulta por chave ou por id
docker compose exec app php artisan fiscal:consultar-nota --chave=00000000000000000000000000000000...
docker compose exec app php artisan fiscal:consultar-nota --id=DPS0000000...
```

## 📤 Emissão (homologação)

Para emitir NFSe no ambiente de homologação, todos os dados vêm do `.env` (copie de `.env.example` e preencha):

1. **Certificado no container:** Coloque o arquivo `.pfx` em `storage/app/certificados/` no seu projeto (a pasta é montada no container).

2. **Configure o `.env`** — obrigatórios para emissão:
   - **Certificado:** `NFSE_CERT_CAMINHO`, `NFSE_CERT_SENHA`
   - **Empresa:** `NFSE_EMPRESA_CNPJ`, `NFSE_EMPRESA_MUNICIPIO` (código IBGE) e demais dados da empresa
   - **Cliente:** `NFSE_CLIENTE_DOCUMENTO` (CPF/CNPJ), `NFSE_CLIENTE_NOME`, `NFSE_CLIENTE_EMAIL` e demais dados do tomador
   - **Serviço:** código, descrição, valor etc. (veja `NFSE_SERVICO_*` no `.env.example`)

   Exemplo mínimo da parte NFSe:
   ```env
   NFSE_CERT_CAMINHO=storage/app/certificados/SEU_ARQUIVO.pfx
   NFSE_CERT_SENHA=sua_senha
   NFSE_EMPRESA_CNPJ=...
   NFSE_EMPRESA_MUNICIPIO=...
   NFSE_CLIENTE_DOCUMENTO=...
   NFSE_CLIENTE_NOME=...
   NFSE_CLIENTE_EMAIL=...
   ```

3. **Rode o comando dentro do container:**
   ```bash
   docker compose exec app php artisan fiscal:emitir-nota
   ```

*Caminho em `NFSE_CERT_CAMINHO` pode ser relativo ao projeto (ex.: `storage/app/certificados/cert.pfx`) ou absoluto. Dentro do Docker o working dir é `/var/www/html`, então o relativo é resolvido corretamente.*

**Certificado para emissão:** só o **.pfx** é obrigatório (`NFSE_CERT_CAMINHO` + `NFSE_CERT_SENHA`). Ele é usado para assinar o XML da DPS e para mTLS na chamada à API (extração em tempo de execução).

### Consulta por idDPS ou por chave de acesso

Após um envio (ou timeout), é possível consultar se a DPS já foi processada pelo servidor:

- **Por idDPS:** atributo `Id` do elemento `infDPS` no XML. Endpoint: `GET {NFSE_URL_CONSULTA}?idDPS=...`
- **Por chave de acesso:** chave numérica da NFSe. Endpoint: `GET {NFSE_URL_EMISSAO}/{chaveAcesso}` (conforme documentação SEFIN Nacional)

1. **Configure no `.env`** as URLs (emissão já usada pelo `fiscal:emitir-nota`; consulta por idDPS usa a URL de consulta). O `.env.example` já traz os padrões de homologação:
   ```env
   NFSE_URL_EMISSAO=https://sefin.producaorestrita.nfse.gov.br/SefinNacional/nfse
   NFSE_URL_CONSULTA=https://sefin.producaorestrita.nfse.gov.br/SefinNacional/consulta
   ```

2. **Execute** (substitua pelo idDPS ou pela chave da sua NFSe):
   ```bash
   docker compose exec app php artisan fiscal:consultar-nota DPS000000000000000000000000000000000000000000000001
   docker compose exec app php artisan fiscal:consultar-nota 00000000000000000000000000000000000000000000000000
   ```

O comando usa o mesmo mTLS (cert/key) e timeout de 60 segundos. Se a URL necessária não estiver definida, o comando informa e orienta a configurar.

**Se retornar HTTP 404:** a URL de emissão pode ter mudado. O comando exibe o endpoint chamado nos detalhes do erro. Confira a [documentação oficial da API Contribuintes](https://adn.producaorestrita.nfse.gov.br/contribuintes/docs/index.html) e, se necessário, ajuste `NFSE_URL_EMISSAO` no `.env`.

**Se retornar E4007 ("Não foi possível obter o certificado de cliente"):** testes com curl (usando os PEM de `NFSE_DEBUG_MTLS=true`) mostram que o **handshake TLS completa** — o certificado é enviado. Mesmo assim a aplicação da API (IIS/ASP.NET) responde 403 com E4007. Conclusão: **restrição no ambiente de homologação** (a API pode exigir que o certificado esteja cadastrado/habilitado para uso via API, ou há whitelist). O mesmo certificado que funciona no login do [Emissor Nacional Web](https://www.nfse.gov.br/EmissorNacional/) pode não estar autorizado para a API. Próximos passos: consultar a documentação oficial ou o suporte do Sistema Nacional NFSe sobre E4007 e requisitos de certificado para integração via API em produção restrita.

**Fluxo do comando `fiscal:emitir-nota`:** monta os DTOs a partir do `.env` (`EmpresaDTO`, `ClienteDTO`, `ServicoDTO`), constrói o `DpsDTO`, gera o XML da DPS (`DpsXmlBuilder`), assina com o certificado A1 e envia à API SEFIN via mTLS. Os campos do XML seguem o leiaute da NFSe Nacional (`tpAmb`, `dhEmi`, `infDPS`, `prest`, `toma`, `serv`, `valores`).

### Parametrização de ambiente NFSe

O ambiente de emissão (homologação ou produção) e as URLs são definidos via variáveis no `.env` e centralizados em `config/nfse.php`:

| Variável | Descrição | Padrão |
|---------|-----------|--------|
| `NFSE_AMBIENTE` | `1` = homologação, `2` = produção (valor da tag tpAmb no XML) | `1` |
| `NFSE_BASE_URL` | URL base da API | `https://adn.producaorestrita.nfse.gov.br` |
| `NFSE_URL_EMISSAO` | URL completa de emissão (SEFIN) | (defina no .env) |
| `NFSE_URL_CONSULTA` | URL de consulta por idDPS | (sem padrão; defina para usar `fiscal:consultar-nota`) |

Dados de empresa, cliente e serviço (prefixos `NFSE_EMPRESA_*`, `NFSE_CLIENTE_*`, `NFSE_SERVICO_*`) vêm do `.env`; veja `.env.example` para a lista completa. O sistema usa **homologação** (`NFSE_AMBIENTE=1`) por padrão. Para produção, defina `NFSE_AMBIENTE=2` no `.env`.

## 📁 Estrutura da Solução

* `app/Services`: Contém a `NotaNacionalService`, `DpsXmlBuilder`, `NfseApiClient`, `XmlSigner` e demais classes do fluxo NFSe (montagem da DPS, assinatura e envio à API).
* `app/Services/DTOs`: DTOs de NFSe Nacional (`EmpresaDTO`, `ClienteDTO`, `ServicoDTO`, `DpsDTO`, `DpsIdentificacaoDTO`).
* `app/Services/Contracts`: Interface `NfseServiceInterface`.
* `app/Console/Commands`: Comandos CLI
* `storage/app/certificados`: Diretório destinado ao armazenamento temporário de certificados (ignorado pelo Git).

## 🔐 Segurança

* **Certificados:** Arquivos `.pfx`, `.p12` e `.pem` estão bloqueados no `.gitignore`.
* **Ambiente:** As credenciais de acesso ao SEFIN Nacional devem ser configuradas exclusivamente via variáveis de ambiente no `.env`.

---
