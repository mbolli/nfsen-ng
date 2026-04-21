#!/bin/bash
# Reorganize nfcapd files into year/month/day directory structure
# Expected by nfsen-ng Import

# Don't exit on error - we want to process all files even if some fail
# set -e

SOURCE_DIR="/var/nfdump/capture"
TARGET_BASE="/var/nfdump/capture/all"

echo "Reorganizing nfcapd files from flat structure to YYYY/MM/DD hierarchy..."
echo "Source: $SOURCE_DIR"
echo "Target base: $TARGET_BASE"
echo ""

# Create target base directory
mkdir -p "$TARGET_BASE"

# Counter for moved files
moved=0
skipped=0

# Find all nfcapd files (not the symlink, not .current files, not in subdirectories)
for file in "$SOURCE_DIR"/nfcapd.[0-9]*; do
    # Skip if not a regular file
    [ -f "$file" ] || continue
    
    # Skip if it's in a subdirectory (only process files directly in SOURCE_DIR)
    if [[ "$(dirname "$file")" != "$SOURCE_DIR" ]]; then
        continue
    fi
    
    # Extract filename
    filename=$(basename "$file")
    
    # Skip .current files
    if [[ "$filename" == *.current.* ]]; then
        echo "Skipping: $filename (current file)"
        ((skipped++))
        continue
    fi
    
    # Extract date from filename (format: nfcapd.YYYYMMDDhhmm)
    if [[ "$filename" =~ nfcapd\.([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{4}) ]]; then
        year="${BASH_REMATCH[1]}"
        month="${BASH_REMATCH[2]}"
        day="${BASH_REMATCH[3]}"
        
        # Create target directory
        target_dir="$TARGET_BASE/$year/$month/$day"
        mkdir -p "$target_dir"
        
        # Move file
        target_file="$target_dir/$filename"
        if [ -f "$target_file" ]; then
            echo "Skipping: $filename (already exists in target)"
            ((skipped++))
        else
            mv "$file" "$target_file"
            echo "Moved: $filename -> $year/$month/$day/"
            ((moved++))
        fi
    else
        echo "Skipping: $filename (invalid format)"
        ((skipped++))
    fi
done

echo ""
echo "Done!"
echo "Files moved: $moved"
echo "Files skipped: $skipped"
echo ""
echo "Directory structure created under: $TARGET_BASE"
echo "You can now update nfcapd to use: -w $TARGET_BASE -S 1"
