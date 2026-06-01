#!/bin/bash
#
# Database Auto-Backup Script for Docker
# Run via cron or systemd timer
#
# Usage: ./backup.sh [backup_path]
#

set -e

BACKUP_PATH="${1:-.backups}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:30}"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="$BACKUP_PATH/vaultly-backup-$TIMESTAMP.sql"

# Ensure backup directory exists
mkdir -p "$BACKUP_PATH"

# Get database credentials from Docker Compose or environment
DB_HOST="${DB_HOST:-mysql}"
DB_USER="${DB_USER:-vaultly_user}"
DB_PASS="${DB_PASS:-change_me_secure_password}"
DB_NAME="${DB_NAME:-vaultly_db}"

echo "🔄 Starting database backup..."
echo "  Target: $BACKUP_FILE"
echo "  Database: $DB_NAME @ $DB_HOST"

# Perform backup
if ! mysqldump \
    -h "$DB_HOST" \
    -u "$DB_USER" \
    -p"$DB_PASS" \
    --single-transaction \
    --quick \
    --lock-tables=false \
    "$DB_NAME" > "$BACKUP_FILE" 2>/dev/null; then
    echo "❌ Backup failed!"
    exit 1
fi

# Compress backup
echo "📦 Compressing backup..."
gzip -f "$BACKUP_FILE"
BACKUP_FILE_GZ="$BACKUP_FILE.gz"

# Verify backup file exists and is not empty
if [ ! -s "$BACKUP_FILE_GZ" ]; then
    echo "❌ Backup file is empty!"
    rm -f "$BACKUP_FILE_GZ"
    exit 1
fi

BACKUP_SIZE=$(du -h "$BACKUP_FILE_GZ" | cut -f1)
echo "✅ Backup successful! Size: $BACKUP_SIZE"

# Cleanup old backups (older than retention period)
echo "🧹 Cleaning up old backups (older than $RETENTION_DAYS days)..."
find "$BACKUP_PATH" -name "vaultly-backup-*.sql.gz" -mtime +$RETENTION_DAYS -delete
echo "   Done."

# Optional: Upload to S3 or remote storage
if [ ! -z "$BACKUP_S3_BUCKET" ]; then
    echo "☁️  Uploading to S3..."
    aws s3 cp "$BACKUP_FILE_GZ" \
        "s3://$BACKUP_S3_BUCKET/backups/vaultly-backup-$TIMESTAMP.sql.gz" \
        --storage-class STANDARD_IA \
        --sse AES256
    echo "   S3 upload complete."
fi

echo "✨ Backup process completed at $(date)"
exit 0
