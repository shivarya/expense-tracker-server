#!/bin/bash

SESSION_DIR=".cagefs/opt/alt/php84/var/lib/php/session"
THRESHOLD=10000
DELETE_COUNT=2000  # Delete oldest 2000 files when over threshold

# Count files
FILE_COUNT=$(find "$SESSION_DIR" -type f 2>/dev/null | wc -l)

# If count exceeds threshold, delete oldest files
if [ "$FILE_COUNT" -gt "$THRESHOLD" ]; then
  # Delete oldest files (sort by modification time, oldest first)
  find "$SESSION_DIR" -type f -printf '%T@ %p\n' 2>/dev/null | \
    sort -n | \
    head -n "$DELETE_COUNT" | \
    cut -d' ' -f2- | \
    xargs -r rm -f

  # Log the action (optional)
  echo "$(date): Deleted $DELETE_COUNT files. Previous count: $FILE_COUNT" >> ~/session-cleanup.log
fi