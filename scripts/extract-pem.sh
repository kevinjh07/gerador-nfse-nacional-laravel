#!/bin/sh
# Extrai cert.pem e private_key.pem do .pfx para uso em mTLS.
# Uso (dentro do container): sh /var/www/html/scripts/extract-pem.sh
# Ou do host: docker compose exec app sh /var/www/html/scripts/extract-pem.sh

set -e
DIR="/var/www/html/storage/app/certificados"
PFX="$DIR/Med eLearning_ Senha_116278.pfx"
PASS="116278"

if [ ! -f "$PFX" ]; then
  echo "Arquivo .pfx nao encontrado: $PFX"
  echo "Ajuste PFX e PASS neste script ou passe como variaveis de ambiente."
  exit 1
fi

# -nokeys sem -clcerts = todos os certificados (cliente + cadeia), exigido por muitos servidores mTLS
openssl pkcs12 -in "$PFX" -nokeys -out "$DIR/cert.pem" -passin "pass:$PASS"
openssl pkcs12 -in "$PFX" -nocerts -nodes -out "$DIR/private_key.pem" -passin "pass:$PASS"
chmod 600 "$DIR/cert.pem" "$DIR/private_key.pem"
echo "OK: $DIR/cert.pem e $DIR/private_key.pem criados."
ls -la "$DIR/cert.pem" "$DIR/private_key.pem"
