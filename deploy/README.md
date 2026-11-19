# Web Palm Beaches — Safe Publishing System

This directory contains the automated, safe publishing system for deploying approved changes from the `main` branch of `oaklandunited/Web-Palm-Beaches` to the GoDaddy cPanel hosting environment.

---

## 1. Safety Guarantees & Architecture

1. **Manifest-Driven Deployment:**
   Only files explicitly listed in `deploy/publish-manifest.txt` are published. The system never performs a wholesale directory copy and never relies on `.gitignore` as a deployment filter.

2. **Immutable Commit Verification:**
   Every deployment validates that the repository is clean and matches the expected Git commit SHA before touching the network.

3. **Strict Server-Only File Protection:**
   The following server-side files and directories are protected by hardcoded safety guards and will **never** be uploaded, overwritten, or deleted:
   - `config.local.php` and `.env` (contains server-side API keys)
   - `wp-config.php`, `wp-salt.php`
   - `wp-content/` (including uploads like `Web-Palm-Beaches-Banner-with-Title.svg`)
   - `wp-admin/`, `wp-includes/`
   - Runtime logs (`error_log`, `*.log`)
   - `../wpb-config.php` (stored outside docroot)
   - SSL and server control files (`.well-known/`, `.ftpquota`, `.cpanel/`, `.cagefs/`)

4. **Zero Remote Deletions in Initial Release:**
   Deployments only create new files or update existing manifest files. No remote files are removed during publish.

5. **Automatic Backups Outside Document Root:**
   Before any existing file is modified, its original contents are copied to a timestamped backup directory **outside the public document root**:
   `../wpb_backups/backup_<YYYYMMDD_HHMMSS>_<SHA>/`
   Along with:
   - `backup-manifest.txt` (exact list of original files backed up)
   - `created-files.txt` (exact list of newly introduced files)
   - `release-info.json` (metadata, commit SHA, timestamp, deployer)

6. **Targeted Rollback:**
   The rollback engine (`deploy/rollback.py`) restores all backed-up files and removes **only** files that were introduced by that release, leaving server secrets and unmanaged content untouched.

7. **Concurrency Locking:**
   A lock file (`../.wpb-deploy.lock`) ensures that only one deployment can run at a time, preventing race conditions or partial states.

8. **Non-Intrusive HTTPS Health Checks:**
   After live publishing or rollback, the system verifies:
   - Homepage (200 OK)
   - Services page (200 OK)
   - Clean routing for `/project-brief` (200 OK)
   - Assets (`styles.css`, `shared.js`, `search-index.json`)
   - API endpoints (`project-brief-api.php` and `contact_handler.php` respond with HTTP 405 on GET without invoking LLM tokens or sending emails)

---

## 2. GitHub Actions Secrets Configuration

To enable the GitHub Actions workflows, add the following secrets in **GitHub Repository -> Settings -> Secrets and variables -> Actions**:

| Secret Name | Description | Example / Default |
|---|---|---|
| `DEPLOY_HOST` | GoDaddy server IP or hostname | `148.72.52.99` or `webpalmbeaches.com` |
| `DEPLOY_USER` | GoDaddy cPanel / SSH username | e.g. `cpaneluser` |
| `DEPLOY_PASSWORD` | Password for SFTP / FTPS account | *(Your cPanel password or FTP user password)* |
| `DEPLOY_KEY` | *(Optional if using SSH keys for SFTP)* Private SSH key | `-----BEGIN OPENSSH PRIVATE KEY-----...` |
| `DEPLOY_PORT` | Port number | `22` (SFTP) or `21` (FTPS) |
| `DEPLOY_PROTOCOL` | Connection protocol | `sftp` (recommended) or `ftps` |
| `DEPLOY_PATH` | Path relative to login root | `public_html` |
| `SSH_KNOWN_HOSTS` | *(Optional)* Known host key for host verification | Output of `ssh-keyscan -p 22 148.72.52.99` |

---

## 3. How to Deploy

### Step A: Run a Dry Run (Default)
1. In GitHub, go to the **Actions** tab.
2. Select the **Publish website** workflow.
3. Click **Run workflow** on the `main` branch.
4. Leave **Dry Run Mode** checked (`true`).
5. Review the run summary: it will display the exact list of files that would be created and overwritten, without modifying anything on the server.

### Step B: Execute Live Deployment
1. When ready, click **Run workflow** again.
2. Uncheck **Dry Run Mode**.
3. Type `DEPLOY` in the **confirm_deploy** field.
4. The workflow will:
   - Run pre-deployment validation (PHP syntax, JSON integrity, HTML asset links).
   - Connect via SFTP/FTPS and acquire the deployment lock.
   - Back up all existing target files to `../wpb_backups/`.
   - Upload approved manifest files.
   - Run post-deployment HTTPS health checks.
   - Output the commit SHA, changed files, backup location, and rollback instructions in the job summary.

---

## 4. How to Rollback

If an unexpected issue occurs on production:
1. In GitHub, go to the **Actions** tab.
2. Select the **Rollback website** workflow.
3. Click **Run workflow**.
4. Keep `backup_name` as `latest` (or enter a specific backup path, e.g. `wpb_backups/backup_20261119_120000_57f38a20`).
5. Type `ROLLBACK` in the confirmation field.
6. The workflow will restore the previous versions of modified files and delete only files introduced by that deployment.
7. Post-rollback health checks will run to verify site recovery.

---

## 5. Local CLI Usage (Optional)

You can also run the deployment engine directly from your Mac or local terminal:

```bash
# 1. Run Pre-flight Validation
python3 deploy/validate.py

# 2. Run Dry Run
export DEPLOY_HOST="148.72.52.99"
export DEPLOY_USER="your_cpanel_user"
export DEPLOY_PASSWORD="your_password"
export DRY_RUN="true"
python3 deploy/deploy.py

# 3. Run Live Deployment
export DRY_RUN="false"
python3 deploy/deploy.py

# 4. Run Post-Deployment Health Check
python3 deploy/healthcheck.py

# 5. Run Rollback (if ever needed)
python3 deploy/rollback.py latest
```
