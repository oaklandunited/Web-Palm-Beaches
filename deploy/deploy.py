#!/usr/bin/env python3
"""
Web Palm Beaches — Deployment Engine
Handles connection via SFTP or FTPS to GoDaddy hosting.
Features:
- Single-deployment locking
- Dry-run as default
- Immutable commit tracking
- Backups outside document root
- Manifest-only sync (no wholesale uploads)
- Preservation of server-only / WordPress files
- Automatic step summary generation for GitHub Actions
"""

import sys
import os
import re
import json
import time
import hashlib
from datetime import datetime
import ftplib
import ssl

try:
    import paramiko
    HAS_PARAMIKO = True
except ImportError:
    HAS_PARAMIKO = False

# Server-only protected patterns that can NEVER be overwritten or deleted
PROTECTED_SERVER_PATTERNS = [
    r"^config\.local\.php$",
    r"^\.env.*$",
    r"^wp-config.*\.php$",
    r"^wp-content(/.*)?$",
    r"^wp-admin(/.*)?$",
    r"^wp-includes(/.*)?$",
    r"^error_log$",
    r"^.*\.log$",
    r"^\.ftpquota$",
    r"^\.well-known(/.*)?$",
    r"^\.cpanel(/.*)?$",
    r"^\.cagefs(/.*)?$",
    r"^php\.ini$",
    r"^\.user\.ini$",
    r"^cgi-bin(/.*)?$",
    r"^wpb_backups(/.*)?$",
]

def load_manifest(manifest_path="deploy/publish-manifest.txt"):
    if not os.path.isfile(manifest_path):
        raise FileNotFoundError(f"Manifest not found at {manifest_path}")
    files = []
    with open(manifest_path, "r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if line and not line.startswith("#"):
                files.append(line)
    return files

class RemoteClient:
    """Abstract interface for SFTP and FTPS connections."""
    def __init__(self, host, user, password=None, key_filename=None, port=None, protocol="sftp"):
        self.host = host
        self.user = user
        self.password = password
        self.key_filename = key_filename
        self.protocol = protocol.lower()
        self.port = port or (22 if self.protocol == "sftp" else 21)
        self.sftp = None
        self.ssh = None
        self.ftps = None

    def connect(self):
        if self.protocol == "sftp":
            if not HAS_PARAMIKO:
                raise RuntimeError("paramiko is required for SFTP connections. Install with: pip install paramiko")
            self.ssh = paramiko.SSHClient()
            # In production CI, host keys should be configured via SSH_KNOWN_HOSTS
            known_hosts = os.environ.get("SSH_KNOWN_HOSTS_FILE")
            if known_hosts and os.path.isfile(known_hosts):
                self.ssh.load_host_keys(known_hosts)
            else:
                self.ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())

            connect_kwargs = {
                "hostname": self.host,
                "port": int(self.port),
                "username": self.user,
                "timeout": 20,
            }
            if self.key_filename and os.path.isfile(self.key_filename):
                connect_kwargs["key_filename"] = self.key_filename
            elif self.password:
                connect_kwargs["password"] = self.password
            else:
                raise ValueError("SFTP requires either DEPLOY_KEY or DEPLOY_PASSWORD.")

            self.ssh.connect(**connect_kwargs)
            self.sftp = self.ssh.open_sftp()
            print(f"Connected via SFTP to {self.user}@{self.host}:{self.port}")
        elif self.protocol in ("ftps", "ftp"):
            # Explicit FTPS
            self.ftps = ftplib.FTP_TLS(timeout=25)
            self.ftps.connect(self.host, int(self.port))
            self.ftps.auth()
            self.ftps.prot_p() # Secure data connection
            self.ftps.login(self.user, self.password)
            print(f"Connected via FTPS (TLS) to {self.user}@{self.host}:{self.port}")
        else:
            raise ValueError(f"Unsupported protocol: {self.protocol}")

    def close(self):
        if self.sftp:
            try: self.sftp.close()
            except: pass
        if self.ssh:
            try: self.ssh.close()
            except: pass
        if self.ftps:
            try: self.ftps.quit()
            except:
                try: self.ftps.close()
                except: pass

    def exists(self, remote_path):
        if self.protocol == "sftp":
            try:
                self.sftp.stat(remote_path)
                return True
            except FileNotFoundError:
                return False
            except Exception:
                return False
        else:
            try:
                self.ftps.size(remote_path)
                return True
            except:
                # Could be a directory
                cur = self.ftps.pwd()
                try:
                    self.ftps.cwd(remote_path)
                    self.ftps.cwd(cur)
                    return True
                except:
                    return False

    def get_file_bytes(self, remote_path):
        if self.protocol == "sftp":
            with self.sftp.file(remote_path, "rb") as f:
                return f.read()
        else:
            data = bytearray()
            self.ftps.retrbinary(f"RETR {remote_path}", data.extend)
            return bytes(data)

    def put_file_bytes(self, remote_path, data):
        self.ensure_remote_dir(os.path.dirname(remote_path))
        if self.protocol == "sftp":
            with self.sftp.file(remote_path, "wb") as f:
                f.write(data)
        else:
            import io
            bio = io.BytesIO(data)
            self.ftps.storbinary(f"STOR {remote_path}", bio)

    def delete_file(self, remote_path):
        if self.protocol == "sftp":
            self.sftp.remove(remote_path)
        else:
            self.ftps.delete(remote_path)

    def ensure_remote_dir(self, remote_dir):
        if not remote_dir or remote_dir in (".", "/"):
            return
        parts = remote_dir.replace("\\", "/").strip("/").split("/")
        current = ""
        for p in parts:
            current = (current + "/" + p) if current else p
            if not self.exists(current):
                if self.protocol == "sftp":
                    try:
                        self.sftp.mkdir(current)
                    except:
                        pass
                else:
                    try:
                        self.ftps.mkd(current)
                    except:
                        pass

class DeploymentManager:
    def __init__(self, client, docroot="public_html", dry_run=True, commit_sha=None):
        self.client = client
        self.docroot = docroot.strip("/")
        self.dry_run = dry_run
        self.commit_sha = commit_sha or os.environ.get("GITHUB_SHA", "manual-commit")
        # Backup dir is placed outside the public document root
        self.parent_dir = os.path.dirname(self.docroot) if os.path.dirname(self.docroot) else "."
        self.backup_base = "wpb_backups" if self.parent_dir == "." else f"{self.parent_dir}/wpb_backups"
        self.lock_file = ".wpb-deploy.lock" if self.parent_dir == "." else f"{self.parent_dir}/.wpb-deploy.lock"
        self.timestamp = datetime.utcnow().strftime("%Y%m%d_%H%M%S")
        self.release_backup_dir = f"{self.backup_base}/backup_{self.timestamp}_{self.commit_sha[:8]}"

    def acquire_lock(self):
        if self.dry_run:
            return
        if self.client.exists(self.lock_file):
            try:
                raw = self.client.get_file_bytes(self.lock_file).decode("utf-8")
                lock_data = json.loads(raw)
                created = lock_data.get("timestamp", 0)
                # Check if lock is older than 15 minutes (stale)
                if time.time() - created < 900:
                    raise RuntimeError(
                        f"Deployment locked by another run! (Lock created at {lock_data.get('iso')}, SHA: {lock_data.get('sha')}). "
                        f"Aborting to enforce one deployment at a time."
                    )
                else:
                    print(f"NOTICE: Found stale lock (>15m). Overriding.")
            except Exception as e:
                if "locked by another run" in str(e):
                    raise

        lock_payload = {
            "sha": self.commit_sha,
            "iso": datetime.utcnow().isoformat(),
            "timestamp": time.time(),
            "actor": os.environ.get("GITHUB_ACTOR", "local-operator")
        }
        self.client.put_file_bytes(self.lock_file, json.dumps(lock_payload).encode("utf-8"))
        print(f"Acquired server deployment lock: {self.lock_file}")

    def release_lock(self):
        if self.dry_run:
            return
        try:
            if self.client.exists(self.lock_file):
                self.client.delete_file(self.lock_file)
                print(f"Released server deployment lock.")
        except Exception as e:
            print(f"WARNING: Could not remove lock file {self.lock_file}: {e}", file=sys.stderr)

    def plan_changes(self, manifest_files, repo_root="."):
        print("\n=== Inspecting Remote Target & Calculating Differences ===")
        print(f"Target Document Root: {self.docroot}")
        print(f"Backup Location (outside docroot): {self.release_backup_dir}")

        new_files = []
        modified_files = []
        unchanged_files = []

        for rel in manifest_files:
            # Enforce server protection guard
            for pat in PROTECTED_SERVER_PATTERNS:
                if re.match(pat, rel, re.IGNORECASE):
                    raise RuntimeError(f"SECURITY ALERT: Manifest contains protected server pattern '{rel}'. Aborting!")

            local_path = os.path.join(repo_root, rel)
            local_bytes = open(local_path, "rb").read()
            local_hash = hashlib.sha256(local_bytes).hexdigest()

            remote_path = f"{self.docroot}/{rel}" if self.docroot else rel
            if not self.client.exists(remote_path):
                new_files.append((rel, len(local_bytes), local_hash))
            else:
                remote_bytes = self.client.get_file_bytes(remote_path)
                remote_hash = hashlib.sha256(remote_bytes).hexdigest()
                if local_hash != remote_hash:
                    modified_files.append((rel, len(local_bytes), len(remote_bytes), remote_bytes))
                else:
                    unchanged_files.append(rel)

        return new_files, modified_files, unchanged_files

    def execute(self, manifest_files, repo_root="."):
        self.acquire_lock()
        try:
            new_files, modified_files, unchanged_files = self.plan_changes(manifest_files, repo_root)

            print(f"\nDiff Summary:")
            print(f"  - New files to create:     {len(new_files)}")
            print(f"  - Existing to overwrite:   {len(modified_files)}")
            print(f"  - Identical (untouched):   {len(unchanged_files)}")
            print(f"  - Total manifest files:    {len(manifest_files)}")

            if self.dry_run:
                print("\n==================== DRY RUN MODE ====================")
                print("No files were created, modified, or deleted on the remote server.")
                print("\nFiles that WOULD be created (new):")
                for item in new_files:
                    print(f"  [+] {item[0]} ({item[1]} bytes)")
                print("\nFiles that WOULD be overwritten (modified):")
                for item in modified_files:
                    print(f"  [*] {item[0]} (local: {item[1]}b vs remote: {item[2]}b)")
                print("\nFiles that are already identical:")
                for item in unchanged_files[:10]:
                    print(f"  [=] {item}")
                if len(unchanged_files) > 10:
                    print(f"  ... and {len(unchanged_files) - 10} more identical files.")
                print("=======================================================")

                self.write_summary(new_files, modified_files, unchanged_files, dry_run=True)
                return 0

            # --- LIVE DEPLOYMENT ---
            print("\n=== Executing Live Deployment ===")
            if not new_files and not modified_files:
                print("No changes detected. Remote site is already identical to commit.")
                self.write_summary(new_files, modified_files, unchanged_files, dry_run=False, noop=True)
                return 0

            # 1. Create backup of all modified files outside document root
            print(f"Creating backup directory outside document root: {self.release_backup_dir}")
            self.client.ensure_remote_dir(self.release_backup_dir)

            backed_up_paths = []
            for item in modified_files:
                rel = item[0]
                existing_bytes = item[3]
                backup_file_path = f"{self.release_backup_dir}/{rel}"
                self.client.ensure_remote_dir(os.path.dirname(backup_file_path))
                self.client.put_file_bytes(backup_file_path, existing_bytes)
                backed_up_paths.append(rel)

            # Store backup manifest & created files list for precise rollback
            backup_manifest_content = "\n".join(backed_up_paths) + "\n"
            self.client.put_file_bytes(f"{self.release_backup_dir}/backup-manifest.txt", backup_manifest_content.encode("utf-8"))

            new_paths = [item[0] for item in new_files]
            created_manifest_content = "\n".join(new_paths) + "\n"
            self.client.put_file_bytes(f"{self.release_backup_dir}/created-files.txt", created_manifest_content.encode("utf-8"))

            print(f"Backed up {len(backed_up_paths)} existing file(s) before overwriting.")

            # 2. Upload modified and new files (No remote deletions)
            print("Uploading manifest changes...")
            uploaded_count = 0
            for rel in new_paths + backed_up_paths:
                local_path = os.path.join(repo_root, rel)
                local_bytes = open(local_path, "rb").read()
                remote_target = f"{self.docroot}/{rel}" if self.docroot else rel
                self.client.ensure_remote_dir(os.path.dirname(remote_target))
                self.client.put_file_bytes(remote_target, local_bytes)
                uploaded_count += 1
                if uploaded_count % 10 == 0 or uploaded_count == len(new_paths) + len(backed_up_paths):
                    print(f"  Progress: {uploaded_count}/{len(new_paths) + len(backed_up_paths)} uploaded...")

            # 3. Write release metadata
            release_info = {
                "commit_sha": self.commit_sha,
                "timestamp": self.timestamp,
                "iso": datetime.utcnow().isoformat(),
                "deployed_by": os.environ.get("GITHUB_ACTOR", "manual"),
                "docroot": self.docroot,
                "backup_dir": self.release_backup_dir,
                "files_created": new_paths,
                "files_modified": backed_up_paths,
                "status": "deployed"
            }
            release_json = json.dumps(release_info, indent=2).encode("utf-8")
            self.client.put_file_bytes(f"{self.release_backup_dir}/release-info.json", release_json)
            # Write current release pointer outside docroot
            current_release_path = f"{self.parent_dir}/current-release.json" if self.parent_dir != "." else "current-release.json"
            self.client.put_file_bytes(current_release_path, release_json)

            print(f"\nSUCCESS: Published {uploaded_count} file(s) for commit {self.commit_sha[:8]}!")
            self.write_summary(new_files, modified_files, unchanged_files, dry_run=False, noop=False)
            return 0
        finally:
            self.release_lock()

    def write_summary(self, new_files, modified_files, unchanged_files, dry_run=True, noop=False):
        step_summary_file = os.environ.get("GITHUB_STEP_SUMMARY")
        if not step_summary_file:
            return

        mode_badge = "🟡 DRY RUN (No changes made)" if dry_run else ("🟢 LIVE DEPLOYMENT COMPLETE" if not noop else "⚪ NO CHANGES NEEDED")
        md = [
            f"# {mode_badge}",
            f"- **Commit SHA:** `{self.commit_sha}`",
            f"- **Target Docroot:** `{self.docroot}`",
            f"- **Timestamp (UTC):** `{self.timestamp}`",
            f"- **Backup Location:** `{self.release_backup_dir}`",
            f"- **New Files Created:** `{len(new_files)}`",
            f"- **Files Modified/Overwritten:** `{len(modified_files)}`",
            f"- **Unchanged Files:** `{len(unchanged_files)}`",
            "",
            "### Planned / Affected Files",
        ]

        if new_files:
            md.append("#### Newly Created Files:")
            for item in new_files:
                md.append(f"- `[+] {item[0]}` ({item[1]} bytes)")
        if modified_files:
            md.append("#### Modified Files (backed up before overwrite):")
            for item in modified_files:
                md.append(f"- `[*] {item[0]}`")

        md.extend([
            "",
            "### Rollback Instructions",
            f"If an issue is detected, trigger the **Rollback website** workflow or run:",
            f"```bash",
            f"python3 deploy/rollback.py {self.release_backup_dir}",
            f"```",
            f"This will restore all `{len(modified_files)}` overwritten file(s) and delete `{len(new_files)}` newly introduced file(s) while preserving server secrets, runtime data, and WordPress files."
        ])

        try:
            with open(step_summary_file, "a", encoding="utf-8") as f:
                f.write("\n".join(md) + "\n")
        except Exception as e:
            print(f"Could not write to GITHUB_STEP_SUMMARY: {e}", file=sys.stderr)

def main():
    repo_root = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
    manifest_path = os.path.join(repo_root, "deploy", "publish-manifest.txt")

    # Read configuration from environment
    host = os.environ.get("DEPLOY_HOST")
    user = os.environ.get("DEPLOY_USER")
    password = os.environ.get("DEPLOY_PASSWORD")
    key_file = os.environ.get("DEPLOY_KEY_FILE")
    port = os.environ.get("DEPLOY_PORT")
    protocol = os.environ.get("DEPLOY_PROTOCOL", "sftp")
    docroot = os.environ.get("DEPLOY_PATH", "public_html")
    dry_run = os.environ.get("DRY_RUN", "true").lower() in ("true", "1", "yes")

    print("=== Web Palm Beaches: Deployment Engine ===")
    print(f"Protocol: {protocol.upper()} | Docroot: {docroot} | Mode: {'DRY RUN' if dry_run else 'LIVE DEPLOY'}")

    if not host or not user:
        print("ERROR: Missing required credentials. DEPLOY_HOST and DEPLOY_USER must be set.", file=sys.stderr)
        return 1

    manifest = load_manifest(manifest_path)
    client = RemoteClient(host=host, user=user, password=password, key_filename=key_file, port=port, protocol=protocol)

    try:
        client.connect()
    except Exception as e:
        print(f"ERROR: Could not connect to remote host {host}: {e}", file=sys.stderr)
        return 1

    try:
        manager = DeploymentManager(client, docroot=docroot, dry_run=dry_run)
        return manager.execute(manifest, repo_root)
    finally:
        client.close()

if __name__ == "__main__":
    sys.exit(main())
