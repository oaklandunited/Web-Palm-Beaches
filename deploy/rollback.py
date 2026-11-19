#!/usr/bin/env python3
"""
Web Palm Beaches — Rollback Engine
Restores overwritten files from a specified backup directory (outside docroot)
and deletes ONLY files that were newly introduced by that specific deployment.
Preserves config.local.php, .env, WordPress files, logs, and all runtime data.
"""

import sys
import os
import json
import re
from datetime import datetime

# Import helper classes from deploy.py
from deploy import RemoteClient, PROTECTED_SERVER_PATTERNS

def run_rollback(target_backup=None):
    host = os.environ.get("DEPLOY_HOST")
    user = os.environ.get("DEPLOY_USER")
    password = os.environ.get("DEPLOY_PASSWORD")
    key_file = os.environ.get("DEPLOY_KEY_FILE")
    port = os.environ.get("DEPLOY_PORT")
    protocol = os.environ.get("DEPLOY_PROTOCOL", "sftp")
    docroot = os.environ.get("DEPLOY_PATH", "public_html").strip("/")

    print("=== Web Palm Beaches: Rollback Engine ===")
    print(f"Protocol: {protocol.upper()} | Docroot: {docroot}")

    if not host or not user:
        print("ERROR: Missing required credentials (DEPLOY_HOST, DEPLOY_USER).", file=sys.stderr)
        return 1

    client = RemoteClient(host=host, user=user, password=password, key_filename=key_file, port=port, protocol=protocol)
    try:
        client.connect()
    except Exception as e:
        print(f"ERROR: Could not connect to remote host: {e}", file=sys.stderr)
        return 1

    parent_dir = os.path.dirname(docroot) if os.path.dirname(docroot) else "."
    current_release_path = f"{parent_dir}/current-release.json" if parent_dir != "." else "current-release.json"

    try:
        # Determine backup directory
        backup_dir = target_backup
        if not backup_dir or backup_dir == "latest":
            if client.exists(current_release_path):
                raw = client.get_file_bytes(current_release_path).decode("utf-8")
                info = json.loads(raw)
                backup_dir = info.get("backup_dir")
                print(f"Discovered current release backup directory: {backup_dir}")
            else:
                print(f"ERROR: No target backup specified and '{current_release_path}' not found.", file=sys.stderr)
                return 1

        if not client.exists(backup_dir):
            print(f"ERROR: Backup directory does not exist on server: {backup_dir}", file=sys.stderr)
            return 1

        print(f"Initiating rollback from: {backup_dir}")

        # 1. Read backup-manifest.txt (files to restore)
        backup_manifest_file = f"{backup_dir}/backup-manifest.txt"
        files_to_restore = []
        if client.exists(backup_manifest_file):
            raw = client.get_file_bytes(backup_manifest_file).decode("utf-8")
            files_to_restore = [line.strip() for line in raw.splitlines() if line.strip()]

        # 2. Read created-files.txt (files introduced by this release to remove)
        created_manifest_file = f"{backup_dir}/created-files.txt"
        files_to_remove = []
        if client.exists(created_manifest_file):
            raw = client.get_file_bytes(created_manifest_file).decode("utf-8")
            files_to_remove = [line.strip() for line in raw.splitlines() if line.strip()]

        print(f"Plan:")
        print(f"  - Overwritten files to restore: {len(files_to_restore)}")
        print(f"  - Newly introduced files to remove: {len(files_to_remove)}")

        # 3. Restore backed-up files
        restored_count = 0
        for rel in files_to_restore:
            backup_source = f"{backup_dir}/{rel}"
            docroot_target = f"{docroot}/{rel}" if docroot else rel
            if client.exists(backup_source):
                data = client.get_file_bytes(backup_source)
                client.put_file_bytes(docroot_target, data)
                restored_count += 1
                print(f"  [RESTORED] {rel}")
            else:
                print(f"  [WARNING] Backup copy missing for {rel}!", file=sys.stderr)

        # 4. Remove newly created files (ensuring protected files are never touched)
        removed_count = 0
        for rel in files_to_remove:
            is_protected = False
            for pat in PROTECTED_SERVER_PATTERNS:
                if re.match(pat, rel, re.IGNORECASE):
                    is_protected = True
                    break
            if is_protected:
                print(f"  [SKIPPED PROTECTED] {rel}")
                continue

            docroot_target = f"{docroot}/{rel}" if docroot else rel
            if client.exists(docroot_target):
                client.delete_file(docroot_target)
                removed_count += 1
                print(f"  [REMOVED NEW] {rel}")

        # 5. Update release metadata
        rollback_info = {
            "status": "rolled_back",
            "restored_from": backup_dir,
            "timestamp": datetime.utcnow().isoformat(),
            "restored_files_count": restored_count,
            "removed_files_count": removed_count,
            "actor": os.environ.get("GITHUB_ACTOR", "manual-rollback")
        }
        client.put_file_bytes(current_release_path, json.dumps(rollback_info, indent=2).encode("utf-8"))

        print(f"\nROLLBACK COMPLETE: Restored {restored_count} files, removed {removed_count} introduced files.")

        # Write summary if in GitHub Actions
        step_summary_file = os.environ.get("GITHUB_STEP_SUMMARY")
        if step_summary_file:
            md = [
                "# 🔄 ROLLBACK COMPLETED",
                f"- **Restored From Backup:** `{backup_dir}`",
                f"- **Files Restored:** `{restored_count}`",
                f"- **Introduced Files Removed:** `{removed_count}`",
                f"- **Docroot:** `{docroot}`",
                f"- **Timestamp (UTC):** `{datetime.utcnow().isoformat()}`",
            ]
            with open(step_summary_file, "a", encoding="utf-8") as f:
                f.write("\n".join(md) + "\n")

        return 0
    finally:
        client.close()

if __name__ == "__main__":
    target = sys.argv[1] if len(sys.argv) > 1 else None
    sys.exit(run_rollback(target))
