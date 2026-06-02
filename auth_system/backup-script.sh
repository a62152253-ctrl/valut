#!/bin/bash
# VAULTLY AUTOMATED BACKUP SCRIPT
# ═══════════════════════════════════════════════════════════════════════════════
# 
# Automated encrypted daily backups of Vaultly database
# Schedule with cron: 0 2 * * * /path/to/backup-script.sh

set -e

# ═══════════════════════════════════════════════════════════════════════════════
# CONFIGURATION
# ═══════════════════════════════════════════════════════════════════════════════

BACKUP_DIR="/backups"
DB_HOST="${DB_HOST:-localhost}"
DB_USER="${DB_USER:-vaultly_user}"
DB_PASS="${DB_PASS}"
DB_NAME="${DB_NAME:-vaultly}"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="$BACKUP_DIR/vaultly_backup_$TIMESTAMP.sql"
ENCRYPTED_FILE="$BACKUP_FILE.gpg"
RETENTION_DAYS=30

# ═══════════════════════════════════════════════════════════════════════════════
# LOGGING
# ═══════════════════════════════════════════════════════════════════════════════

LOG_FILE="/var/log/vaultly-backup.log"

log() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"
}

log "Starting Vaultly database backup..."

# ═══════════════════════════════════════════════════════════════════════════════
# PRE-BACKUP CHECKS
# ═══════════════════════════════════════════════════════════════════════════════

if [ ! -d "$BACKUP_DIR" ]; then
    mkdir -p "$BACKUP_DIR"
    chmod 700 "$BACKUP_DIR"
    log "Created backup directory: $BACKUP_DIR"
fi

if [ ! -w "$BACKUP_DIR" ]; then
    log "ERROR: Backup directory not writable: $BACKUP_DIR"
    exit 1
fi

if [ ! -x "$(command -v mysqldump)" ]; then
    log "ERROR: mysqldump not found"
    exit 1
fi

# ═══════════════════════════════════════════════════════════════════════════════
# DATABASE BACKUP
# ═══════════════════════════════════════════════════════════════════════════════

log "Dumping database $DB_NAME..."

if ! mysqldump \
    --host="$DB_HOST" \
    --user="$DB_USER" \
    --password="$DB_PASS" \
    --single-transaction \
    --quick \
    --lock-tables=false \
    --verbose \
    "$DB_NAME" > "$BACKUP_FILE" 2>> "$LOG_FILE"; then
    
    log "ERROR: Database backup failed"
    rm -f "$BACKUP_FILE"
    exit 1
fi

BACKUP_SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
log "Database backup completed: $BACKUP_SIZE"

# ═══════════════════════════════════════════════════════════════════════════════
# ENCRYPTION
# ═══════════════════════════════════════════════════════════════════════════════

if [ -x "$(command -v gpg)" ]; then
    log "Encrypting backup..."
    
    # Use symmetric encryption with GPG
    if gpg --symmetric --cipher-algo AES256 --output "$ENCRYPTED_FILE" "$BACKUP_FILE" 2>> "$LOG_FILE"; then
        rm -f "$BACKUP_FILE"
        log "Backup encrypted: $ENCRYPTED_FILE"
    else
        log "WARNING: Encryption failed, keeping unencrypted backup"
    fi
else
    log "WARNING: GPG not found, backup not encrypted"
fi

# ═══════════════════════════════════════════════════════════════════════════════
# SECURITY PERMISSIONS
# ═══════════════════════════════════════════════════════════════════════════════

chmod 400 "$ENCRYPTED_FILE" 2>/dev/null || chmod 400 "$BACKUP_FILE"
log "Backup permissions set to 400 (read-only)"

# ═══════════════════════════════════════════════════════════════════════════════
# RETENTION POLICY
# ═══════════════════════════════════════════════════════════════════════════════

log "Cleaning up old backups (retention: $RETENTION_DAYS days)..."

find "$BACKUP_DIR" -name "vaultly_backup_*.sql*" -type f -mtime +$RETENTION_DAYS -delete

BACKUP_COUNT=$(find "$BACKUP_DIR" -name "vaultly_backup_*.sql*" | wc -l)
log "Current backups: $BACKUP_COUNT"

# ═══════════════════════════════════════════════════════════════════════════════
# VERIFICATION
# ═══════════════════════════════════════════════════════════════════════════════

if [ -f "$ENCRYPTED_FILE" ]; then
    FINAL_FILE="$ENCRYPTED_FILE"
elif [ -f "$BACKUP_FILE" ]; then
    FINAL_FILE="$BACKUP_FILE"
else
    log "ERROR: Backup file not found"
    exit 1
fi

FINAL_SIZE=$(du -h "$FINAL_FILE" | cut -f1)
CHECKSUM=$(sha256sum "$FINAL_FILE" | cut -d' ' -f1)

log "Backup verification: SUCCESS"
log "Final file: $FINAL_FILE"
log "Size: $FINAL_SIZE"
log "SHA256: $CHECKSUM"
log "Backup completed successfully"

# ═══════════════════════════════════════════════════════════════════════════════
# OPTIONAL: CLOUD BACKUP (AWS S3 example)
# ═══════════════════════════════════════════════════════════════════════════════

# Uncomment to enable S3 backup
# if [ -x "$(command -v aws)" ]; then
#     log "Uploading to S3..."
#     aws s3 cp "$FINAL_FILE" "s3://vaultly-backups/$TIMESTAMP/" \
#         --sse AES256 \
#         --metadata "checksum=$CHECKSUM" || log "WARNING: S3 upload failed"
# fi

exit 0
