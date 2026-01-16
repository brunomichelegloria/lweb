set -e

DB_CLIENT=$(command -v mariadb || command -v mysql)
DB_HOST="localhost"
DB_ROOT_USER="root"

LOG_DIR="./logs"
TIMESTAMP=$(date +"%Y-%m-%d_%H-%M-%S")
LOG_FILE="$LOG_DIR/db_$1_$TIMESTAMP.log"

mkdir -p "$LOG_DIR"

case "$1" in
  init)
    echo "[$(date +"%H:%M:%S")] Initializing database..."

    sudo "$DB_CLIENT" --abort-source-on-error -h "$DB_HOST" -u "$DB_ROOT_USER" -p < init.sql > "$LOG_FILE" 2>&1

    sudo "$DB_CLIENT" -h "$DB_HOST" -u "$DB_ROOT_USER" -p -e "SHOW WARNINGS;" >> "$LOG_FILE" 2>&1

    echo "[$(date +"%H:%M:%S")] Done. Log: $LOG_FILE"
    ;;
  drop)
    echo "[$(date +"%H:%M:%S")] Dropping database..."

    sudo "$DB_CLIENT" --abort-source-on-error -h "$DB_HOST" -u "$DB_ROOT_USER" -p < drop.sql > "$LOG_FILE" 2>&1

    sudo "$DB_CLIENT" -h "$DB_HOST" -u "$DB_ROOT_USER" -p -e "SHOW WARNINGS;" >> "$LOG_FILE" 2>&1

    echo "[$(date +"%H:%M:%S")] Done. Log: $LOG_FILE"
    ;;
  *)
    echo "Usage: $0 {init|drop}"
    exit 1
    ;;
esac