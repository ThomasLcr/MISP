#!/bin/bash
set -e

# --- [1] Vérification de l'argument ---
if [ -z "$1" ] || ! [[ "$1" =~ ^[0-9]+$ ]]; then
  echo "Usage: $0 <nombre_de_conteneurs_db_et_misp>"
  exit 1
fi

NUM_INSTANCES=$1
MISP_DOCKER_URL="https://github.com/MISP/misp-docker.git"
MISP_DOCKER_DIR="misp-docker"

declare -A HOSTS
declare -A AUTHS
declare -A ORG_UUIDS


# --- [2] Préparation de l’environnement ---
echo "[+] Mise à jour des paquets..."
sudo apt update && sudo apt upgrade -y

if ! command -v docker &> /dev/null; then
  echo "[+] Installation de Docker..."
  sudo apt install -y docker.io
  sudo systemctl enable --now docker
fi

if docker compose version &>/dev/null; then
  COMPOSE_CMD="docker compose"
else
  if ! command -v docker-compose &>/dev/null; then
    echo "[+] Installation de docker-compose..."
    sudo apt install -y docker-compose
  fi
  COMPOSE_CMD="docker-compose"
fi
echo "[+] Utilisation de la commande: $COMPOSE_CMD"

if [ -d "$MISP_DOCKER_DIR" ]; then
  echo "[*] Suppression de l’ancien dépôt..."
  rm -rf "$MISP_DOCKER_DIR"
fi
git clone "$MISP_DOCKER_URL"
cd "$MISP_DOCKER_DIR" || exit 1

# --- [3] Génération docker-compose.yml ---
echo "[+] Génération du fichier docker-compose.yml..."
# Contenu statique...
cat > docker-compose.yml <<EOF
services:
  mail:
    image: ixdotai/smtp
    environment:
      - "SMARTHOST_ADDRESS=\${SMARTHOST_ADDRESS}"
      - "SMARTHOST_PORT=\${SMARTHOST_PORT}"
      - "SMARTHOST_USER=\${SMARTHOST_USER}"
      - "SMARTHOST_PASSWORD=\${SMARTHOST_PASSWORD}"
      - "SMARTHOST_ALIASES=\${SMARTHOST_ALIASES}"

  redis:
    image: valkey/valkey:7.2
    command: "--requirepass '\${REDIS_PASSWORD:-redispassword}'"
    healthcheck:
      test: "valkey-cli -a '\${REDIS_PASSWORD:-redispassword}' -p \${REDIS_PORT:-6379} ping | grep -q PONG || exit 1"
      interval: 2s
      timeout: 1s
      retries: 3
      start_period: 5s
      start_interval: 5s
EOF

# Ajout dynamique des services
for i in $(seq 1 "$NUM_INSTANCES"); do
  cat >> docker-compose.yml <<EOF

  db_$i:
    image: mariadb:10.11
    restart: always
    environment:
      - "MYSQL_USER=\${MYSQL_USER:-misp}"
      - "MYSQL_PASSWORD=\${MYSQL_PASSWORD:-example}"
      - "MYSQL_ROOT_PASSWORD=\${MYSQL_ROOT_PASSWORD:-password}"
      - "MYSQL_DATABASE=\${MYSQL_DATABASE:-misp_$i}"
    command: "\
      --innodb-buffer-pool-size=\${INNODB_BUFFER_POOL_SIZE:-2048M} \
      --innodb-change-buffering=\${INNODB_CHANGE_BUFFERING:-none} \
      --innodb-io-capacity=\${INNODB_IO_CAPACITY:-1000} \
      --innodb-io-capacity-max=\${INNODB_IO_CAPACITY_MAX:-2000} \
      --innodb-log-file-size=\${INNODB_LOG_FILE_SIZE:-600M} \
      --innodb-read-io-threads=\${INNODB_READ_IO_THREADS:-16} \
      --innodb-stats-persistent=\${INNODB_STATS_PERSISTENT:-ON} \
      --innodb-write-io-threads=\${INNODB_WRITE_IO_THREADS:-4}"
    volumes:
      - mysql_misp_$i:/var/lib/mysql
    cap_add:
      - SYS_NICE
    healthcheck:
      test: mysqladmin --user=\$\$MYSQL_USER --password=\$\$MYSQL_PASSWORD status
      interval: 2s
      timeout: 1s
      retries: 3
      start_period: 30s
      start_interval: 5s

  misp_$i:
    image: ghcr.io/misp/misp-docker/misp-core:\${CORE_RUNNING_TAG:-latest}
    build:
      context: core/.
      args:
        - CORE_TAG=\${CORE_TAG:?Missing .env file, see README.md for instructions}
        - CORE_COMMIT=\${CORE_COMMIT}
        - CORE_FLAVOR=\${CORE_FLAVOR:-full}
        - PHP_VER=\${PHP_VER:?Missing .env file, see README.md for instructions}
        - PYPI_REDIS_VERSION=\${PYPI_REDIS_VERSION}
        - PYPI_LIEF_VERSION=\${PYPI_LIEF_VERSION}
        - PYPI_PYDEEP2_VERSION=\${PYPI_PYDEEP2_VERSION}
        - PYPI_PYTHON_MAGIC_VERSION=\${PYPI_PYTHON_MAGIC_VERSION}
        - PYPI_MISP_LIB_STIX2_VERSION=\${PYPI_MISP_LIB_STIX2_VERSION}
        - PYPI_MAEC_VERSION=\${PYPI_MAEC_VERSION}
        - PYPI_MIXBOX_VERSION=\${PYPI_MIXBOX_VERSION}
        - PYPI_CYBOX_VERSION=\${PYPI_CYBOX_VERSION}
        - PYPI_PYMISP_VERSION=\${PYPI_PYMISP_VERSION}
        - PYPI_MISP_STIX_VERSION=\${PYPI_MISP_STIX_VERSION}
        - PYPI_SETUPTOOLS=\${PYPI_SETUPTOOLS}
        - PYPI_SUPERVISOR=\${PYPI_SUPERVISOR}
    depends_on:
      redis:
        condition: service_healthy
      db_$i:
        condition: service_healthy
    healthcheck:
      test: curl -ks \${BASE_URL:-http://localhost}/users/heartbeat > /dev/null || exit 1
      interval: 2s
      timeout: 1s
      retries: 3
      start_period: 30s
      start_interval: 30s
    ports:
      - "808$i:80"
      - "844$i:443"
    volumes:
      - "./instance-$i/instance-config/:/var/www/MISP/app/Config/"
      - "./instance-$i/instance-log/:/var/www/MISP/app/tmp/logs/"
      - "./instance-$i/instance-files/:/var/www/MISP/app/files/"
      - "./instance-$i/instance-ssl/:/etc/nginx/certs/"
      - "./instance-$i/instance-gnupg/:/var/www/MISP/.gnupg/"
    environment:
      - "BASE_URL=http://localhost:808$i"
      - "CRON_USER_ID=\${CRON_USER_ID}"
      - "CRON_PULLALL=\${CRON_PULLALL}"
      - "CRON_PUSHALL=\${CRON_PUSHALL}"
      - "DISABLE_IPV6=\${DISABLE_IPV6}"
      - "DISABLE_SSL_REDIRECT=\${DISABLE_SSL_REDIRECT}"
      - "ENABLE_DB_SETTINGS=\${ENABLE_DB_SETTINGS}"
      - "ENABLE_BACKGROUND_UPDATES=\${ENABLE_BACKGROUND_UPDATES}"
      - "ENCRYPTION_KEY=\${ENCRYPTION_KEY}"
      - "DISABLE_CA_REFRESH=\${DISABLE_CA_REFRESH}"
      - "MYSQL_HOST=\${MYSQL_HOST:-db_$i}"
      - "MYSQL_PORT=\${MYSQL_PORT:-3306}"
      - "MYSQL_USER=\${MYSQL_USER:-misp}"
      - "MYSQL_PASSWORD=\${MYSQL_PASSWORD:-example}"
      - "MYSQL_DATABASE=\${MYSQL_DATABASE:-misp_$i}"
      - "REDIS_HOST=\${REDIS_HOST:-redis}"
      - "REDIS_PORT=\${REDIS_PORT:-6379}"
      - "REDIS_PASSWORD=\${REDIS_PASSWORD:-redispassword}"
      - "REDIS_DB=$i"
      - "DEBUG=\${DEBUG}"
EOF
done

# Volumes
echo -e "\nvolumes:" >> docker-compose.yml
for i in $(seq 1 "$NUM_INSTANCES"); do
  echo "  mysql_misp_$i:" >> docker-compose.yml
done

echo "[✓] docker-compose.yml généré."

# Copie et modif .env
cp template.env .env
TMP_ENV=$(mktemp)
sed 's/^# *\(DISABLE_SSL_REDIRECT=true\)/\1/' .env > "$TMP_ENV"
mv "$TMP_ENV" .env

echo "[+] Récupération des images Docker (pull)..."
$COMPOSE_CMD pull

echo "[+] Démarrage des services Docker..."
$COMPOSE_CMD up -d

echo "[✓] Conteneurs démarrés. Attente de stabilisation..."
sleep 60  # temps d'attente de démarrage (modifiable)


# --- [4] Lecture des clés API et export ---
echo "Veuillez vous-connecter aux différentes instances pour récupérer les différentes clés API"
for i in $(seq 1 "$NUM_INSTANCES"); do
  HOSTS[$i]="localhost:808$i"
  echo -n "Clé API admin pour l'instance misp_$i (${HOSTS[$i]}): "
  read -r auth_key
  AUTHS[$i]="$auth_key"
done

echo -e "\n[?] Définir le schéma de synchronisation entre les instances"
for i in $(seq 1 "$NUM_INSTANCES"); do
  echo -n "→ Quelles sont les instances connectées à l'instance $i (n° séparés par des espaces, ex: 2 3) ? "
  read -r connexions
  CONNECTION_SCHEMA[$i]="$connexions"
done

# --- [5] Fonctions de synchronisation ---
generate_uuids() {
  for i in "${!HOSTS[@]}"; do
    ORG_UUIDS[$i]=$(uuidgen)
  done
}

get_org_id_on_instance() {
  local instance="$1" org="$2"
  curl -s -X GET "http://${HOSTS[$instance]}/organisations/index.json" \
    -H "Authorization: ${AUTHS[$instance]}" | \
    jq -r ".[] | select(.Organisation.name==\"ORG_$org\") | .Organisation.id"
}

create_all_orgs_on_instance() {
  local id="$1"
  for org_id in "${!ORG_UUIDS[@]}"; do
    curl -s -X POST "http://${HOSTS[$id]}/admin/organisations/add" \
      -H "Authorization: ${AUTHS[$id]}" \
      -H "Content-Type: application/json" \
      -d "$(jq -n --arg name "ORG_$org_id" --arg uuid "${ORG_UUIDS[$org_id]}" --arg desc "ORG $org_id" \
            '{name: $name, uuid: $uuid, description: $desc}')" > /dev/null
  done
}

set_host_org() {
  local id="$1"
  local org_id
  org_id=$(get_org_id_on_instance "$id" "$id")
  docker exec -i "misp-docker-misp_$id-1" \
    bash -c "cd /var/www/MISP && sudo -u www-data /var/www/MISP/app/Console/cake Admin setSetting 'MISP.host_org_id' $org_id"
}

create_sync_user() {
  local source="$1" target="$2"
  local org_id user_id api_key
  org_id=$(get_org_id_on_instance "$target" "$source")
  local result=$(curl -s -X POST "http://${HOSTS[$target]}/admin/users/add" \
    -H "Authorization: ${AUTHS[$target]}" \
    -H "Content-Type: application/json" \
    -d "{\"email\":\"sync${source}on${target}@user.test\",\"org_id\":$org_id,\"role_id\":5}")

  user_id=$(echo "$result" | jq -r .User.id)
  api_key=$(curl -s -X POST "http://${HOSTS[$target]}/auth_keys/add/${user_id}" \
    -H "Authorization: ${AUTHS[$target]}" \
    -H "Content-Type: application/json" \
    -d "{\"comment\": \"Key for sync ${source} -> ${target}\"}" | jq -r .AuthKey.authkey_raw)

  export "SYNC_KEY_${source}_ON_${target}"="$api_key"
}

create_sync_server() {
  local source="$1" target="$2"
  local key_var="SYNC_KEY_${source}_ON_${target}"
  local key="${!key_var}"
  local org_id=$(get_org_id_on_instance "$source" "$target")
  curl -s -X POST "http://${HOSTS[$source]}/servers/add" \
    -H "Authorization: ${AUTHS[$source]}" \
    -H "Content-Type: application/json" \
    -d "{\"name\":\"MISP_${target}\",\"url\":\"http://misp_${target}\",\"remote_org_id\":\"${org_id}\",\"authkey\":\"${key}\",\"push\":true,\"pull\":true}" > /dev/null
}

# --- [6] Lancement configuration ---
generate_uuids

for i in "${!HOSTS[@]}"; do
  create_all_orgs_on_instance "$i"
  set_host_org "$i"
done

for source in "${!CONNECTION_SCHEMA[@]}"; do
  for target in ${CONNECTION_SCHEMA[$source]}; do
    create_sync_user "$source" "$target"
    create_sync_server "$source" "$target"
  done
done

echo "[✔] Déploiement terminé et synchronisations configurées."
