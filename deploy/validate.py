#!/usr/bin/env python3
"""
Web Palm Beaches — Pre-Deployment Validator
Validates the publish manifest, PHP syntax, JSON integrity, HTML asset links,
and verifies no server-only or unapproved files are slated for deployment.
"""

import sys
import os
import re
import json
import subprocess
import html.parser

FORBIDDEN_PATTERNS = [
    r"^config\.local\.php$",
    r"^\.env.*$",
    r"^wp-config.*\.php$",
    r"^wp-salt\.php$",
    r"^wp-admin(/.*)?$",
    r"^wp-includes(/.*)?$",
    r"^wp-content(/.*)?$",
    r"^error_log$",
    r"^.*\.log$",
    r"^\.ftpquota$",
    r"^\.well-known(/.*)?$",
    r"^contact_handler\s+1\.php$",
    r"^.*\.bak$",
    r"^.*\.orig$",
    r"^\.git.*$",
    r"^deploy(/.*)?$",
    r"^\.github(/.*)?$"
]

def load_manifest(manifest_path="deploy/publish-manifest.txt"):
    if not os.path.isfile(manifest_path):
        raise FileNotFoundError(f"Manifest not found at {manifest_path}")
    files = []
    with open(manifest_path, "r", encoding="utf-8") as f:
        for line_num, line in enumerate(f, 1):
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            files.append((line_num, line))
    return files

def validate_manifest_entries(manifest_entries, repo_root="."):
    errors = []
    seen = set()
    validated_files = []

    for line_num, rel_path in manifest_entries:
        if rel_path in seen:
            errors.append(f"Line {line_num}: Duplicate entry '{rel_path}'")
            continue
        seen.add(rel_path)

        for pat in FORBIDDEN_PATTERNS:
            if re.match(pat, rel_path, re.IGNORECASE):
                errors.append(f"Line {line_num}: Forbidden / server-only file detected in manifest: '{rel_path}'")

        full_path = os.path.join(repo_root, rel_path)
        if not os.path.isfile(full_path):
            errors.append(f"Line {line_num}: File not found in repository: '{rel_path}'")
        else:
            validated_files.append(rel_path)

    return validated_files, errors

def validate_php_syntax(php_files, repo_root="."):
    errors = []
    for rel_path in php_files:
        full_path = os.path.join(repo_root, rel_path)
        res = subprocess.run(["php", "-l", full_path], capture_output=True, text=True)
        if res.returncode != 0:
            errors.append(f"PHP syntax error in {rel_path}:\n{res.stderr or res.stdout}")
    return errors

def validate_json_files(json_files, repo_root="."):
    errors = []
    for rel_path in json_files:
        full_path = os.path.join(repo_root, rel_path)
        try:
            with open(full_path, "r", encoding="utf-8") as f:
                json.load(f)
        except Exception as e:
            errors.append(f"JSON validation failed for {rel_path}: {e}")
    return errors

class LocalLinkExtractor(html.parser.HTMLParser):
    def __init__(self):
        super().__init__()
        self.links = []

    def handle_starttag(self, tag, attrs):
        for attr, val in attrs:
            if attr in ("href", "src") and val:
                self.links.append((tag, attr, val))

def validate_html_assets(html_files, manifest_set, repo_root="."):
    errors = []
    total_links_checked = 0

    for rel_path in html_files:
        full_path = os.path.join(repo_root, rel_path)
        try:
            with open(full_path, "r", encoding="utf-8") as f:
                content = f.read()
        except Exception as e:
            errors.append(f"Could not read HTML file {rel_path}: {e}")
            continue

        parser = LocalLinkExtractor()
        try:
            parser.feed(content)
        except Exception as e:
            errors.append(f"HTML parse error in {rel_path}: {e}")
            continue

        for tag, attr, url in parser.links:
            # Strip anchors and query params
            clean_url = url.split("#")[0].split("?")[0].strip()
            if not clean_url:
                continue

            # Ignore external protocols, tel, mailto, data URIs, javascript
            if re.match(r"^(https?:|//|mailto:|tel:|javascript:|data:|#)", clean_url, re.IGNORECASE):
                continue

            # Server-hosted dynamic/WordPress paths that live permanently on remote server
            if clean_url.startswith("/wp-content") or clean_url.startswith("wp-content"):
                continue

            rel_target = clean_url.lstrip("/")
            total_links_checked += 1

            # Target must exist in repository
            target_path = os.path.join(repo_root, rel_target)
            if not os.path.exists(target_path):
                errors.append(f"{rel_path}: Broken local reference <{tag} {attr}=\"{url}\"> -> '{rel_target}' does not exist.")

    return total_links_checked, errors

def validate_commit_sha(expected_sha=None):
    if not expected_sha:
        expected_sha = os.environ.get("GITHUB_SHA") or os.environ.get("EXPECTED_COMMIT_SHA")
    if not expected_sha:
        return None, []

    res = subprocess.run(["git", "rev-parse", "HEAD"], capture_output=True, text=True)
    if res.returncode != 0:
        return None, ["Could not determine git HEAD commit SHA."]
    actual_sha = res.stdout.strip()
    if actual_sha.lower() != expected_sha.strip().lower():
        return actual_sha, [f"Commit mismatch: Expected {expected_sha} but repository HEAD is at {actual_sha}."]
    return actual_sha, []

def main():
    repo_root = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
    manifest_path = os.path.join(repo_root, "deploy", "publish-manifest.txt")

    print("=== Web Palm Beaches: Pre-Deployment Validation ===")
    print(f"Repository Root: {repo_root}")

    # 1. Commit check
    expected_sha = sys.argv[1] if len(sys.argv) > 1 else None
    actual_sha, sha_errors = validate_commit_sha(expected_sha)
    if sha_errors:
        for err in sha_errors:
            print(f"FAIL: {err}", file=sys.stderr)
        sys.exit(1)
    if actual_sha:
        print(f"Immutable Commit SHA: {actual_sha}")

    # 2. Manifest check
    try:
        entries = load_manifest(manifest_path)
    except Exception as e:
        print(f"FAIL: {e}", file=sys.stderr)
        sys.exit(1)

    print(f"Loaded {len(entries)} entries from publish-manifest.txt.")
    validated_files, manifest_errors = validate_manifest_entries(entries, repo_root)
    if manifest_errors:
        for err in manifest_errors:
            print(f"FAIL: {err}", file=sys.stderr)
        sys.exit(1)

    manifest_set = set(validated_files)

    # 3. PHP syntax
    php_files = [f for f in validated_files if f.endswith(".php")]
    php_errors = validate_php_syntax(php_files, repo_root)
    if php_errors:
        for err in php_errors:
            print(f"FAIL: {err}", file=sys.stderr)
        sys.exit(1)
    print(f"PHP syntax verified: {len(php_files)} file(s) OK.")

    # 4. JSON integrity
    json_files = [f for f in validated_files if f.endswith(".json")]
    json_errors = validate_json_files(json_files, repo_root)
    if json_errors:
        for err in json_errors:
            print(f"FAIL: {err}", file=sys.stderr)
        sys.exit(1)
    print(f"JSON integrity verified: {len(json_files)} file(s) OK.")

    # 5. HTML asset & local reference checks
    html_files = [f for f in validated_files if f.endswith(".html")]
    links_checked, html_errors = validate_html_assets(html_files, manifest_set, repo_root)
    if html_errors:
        for err in html_errors[:20]: # Show up to 20 errors
            print(f"FAIL: {err}", file=sys.stderr)
        if len(html_errors) > 20:
            print(f"... and {len(html_errors) - 20} more HTML reference errors.", file=sys.stderr)
        sys.exit(1)
    print(f"HTML references verified: {links_checked} local asset links across {len(html_files)} pages OK.")

    print("\nPASS: All pre-deployment validation checks succeeded!")
    print(f"Total Approved Files: {len(validated_files)}")
    return 0

if __name__ == "__main__":
    sys.exit(main())
