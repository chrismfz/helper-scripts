#!/bin/bash

OUTFILE="/root/convert_everything_utf8mb3_to_utf8mb4.sql"
TMP_COLS="/tmp/utf8_cols.txt"
TMP_TABLES="/tmp/utf8_tables.txt"
TMP_DBS="/tmp/utf8_dbs.txt"

echo "[*] Scanning for utf8mb3 columns..."
mysql -NB -e "
SELECT DISTINCT TABLE_SCHEMA, TABLE_NAME
FROM information_schema.COLUMNS
WHERE CHARACTER_SET_NAME = 'utf8'
AND TABLE_SCHEMA NOT IN ('mysql', 'information_schema', 'performance_schema', 'sys');
" > "$TMP_COLS"

echo "[*] Scanning for utf8mb3 table collations..."
mysql -NB -e "
SELECT TABLE_SCHEMA, TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_COLLATION LIKE 'utf8%' AND TABLE_COLLATION NOT LIKE 'utf8mb4%'
AND TABLE_SCHEMA NOT IN ('mysql', 'information_schema', 'performance_schema', 'sys');
" > "$TMP_TABLES"

echo "[*] Scanning for utf8mb3 database collations..."
mysql -NB -e "
SELECT SCHEMA_NAME
FROM information_schema.SCHEMATA
WHERE DEFAULT_CHARACTER_SET_NAME = 'utf8'
AND SCHEMA_NAME NOT IN ('mysql', 'information_schema', 'performance_schema', 'sys');
" > "$TMP_DBS"

# Combine all table conversions (from column and table scan)
echo "[*] Generating ALTER TABLE statements..."
(cat "$TMP_COLS" "$TMP_TABLES" | sort -u) | awk -F'\t' '{
    printf "ALTER TABLE `%s`.`%s` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n", $1, $2;
}' > "$OUTFILE"

# Add database conversions
echo "[*] Generating ALTER DATABASE statements..."
awk '{ printf "ALTER DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n", $1 }' "$TMP_DBS" >> "$OUTFILE"

# Clean up
rm -f "$TMP_COLS" "$TMP_TABLES" "$TMP_DBS"

echo "[✓] Done! SQL saved to: $OUTFILE"
