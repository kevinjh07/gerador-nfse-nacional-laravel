#!/bin/sh
# Testa mTLS com os PEM gerados por NFSE_DEBUG_MTLS=true
# Rode: docker compose exec app sh /var/www/html/scripts/test-mtls-curl.sh

CERT="/var/www/html/storage/app/mtls-debug-cert.pem"
KEY="/var/www/html/storage/app/mtls-debug-key.pem"
URL="https://sefin.producaorestrita.nfse.gov.br/API/SefinNacional/nfse"

if [ ! -f "$CERT" ] || [ ! -f "$KEY" ]; then
  echo "Arquivos PEM nao encontrados. Rode antes: NFSE_DEBUG_MTLS=true e php artisan nfse:emitir"
  exit 1
fi

echo "Testando mTLS com HTTP/1.1 (body vazio em JSON)..."
echo "Se o servidor so repassa o certificado em HTTP/1.1, isso pode evitar E4007."
curl -v --http1.1 --cert "$CERT" --key "$KEY" -X POST \
  -H "Content-Type: application/json" \
  -d '{"xml":""}' \
  "$URL"
