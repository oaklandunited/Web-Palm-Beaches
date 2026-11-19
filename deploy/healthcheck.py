#!/usr/bin/env python3
"""
Web Palm Beaches — Post-Deployment Healthcheck
Performs non-intrusive HTTPS verification of core pages, clean routing, assets,
and endpoint probes without submitting forms or triggering paid LLM calls.
"""

import sys
import os
import urllib.request
import urllib.error
import time

BASE_URL = os.environ.get("DEPLOY_CHECK_BASE_URL", "https://webpalmbeaches.com").rstrip("/")

CHECKS = [
    {
        "name": "Homepage",
        "url": f"{BASE_URL}/",
        "method": "GET",
        "expected_status": 200,
        "content_contains": ["Web Palm Beaches", "AI Consulting"],
    },
    {
        "name": "Services Page",
        "url": f"{BASE_URL}/services.html",
        "method": "GET",
        "expected_status": 200,
        "content_contains": ["AI Agent Development", "Workflow Automation"],
    },
    {
        "name": "Clean URL Routing: /project-brief",
        "url": f"{BASE_URL}/project-brief",
        "method": "GET",
        "expected_status": 200,
        "content_contains": ["AI Project Brief Generator", "Tailored Project Brief"],
    },
    {
        "name": "Styles: styles.css",
        "url": f"{BASE_URL}/styles.css",
        "method": "GET",
        "expected_status": 200,
        "content_contains": ["--accent", "--tertiary"],
    },
    {
        "name": "Scripts: shared.js",
        "url": f"{BASE_URL}/shared.js",
        "method": "GET",
        "expected_status": 200,
        "content_contains": ["search-overlay", "tablist"],
    },
    {
        "name": "Search Index: search-index.json",
        "url": f"{BASE_URL}/search-index.json",
        "method": "GET",
        "expected_status": 200,
        "content_contains": ["project-brief.html"],
    },
    {
        "name": "API Probe: project-brief-api.php (safe method check)",
        "url": f"{BASE_URL}/project-brief-api.php",
        "method": "GET",
        "expected_status": 405,  # Must reject GET without invoking LLM or consuming tokens
        "content_contains": ["method_not_allowed"],
    },
    {
        "name": "API Probe: contact_handler.php (safe method check)",
        "url": f"{BASE_URL}/contact_handler.php",
        "method": "GET",
        "expected_status": 405,  # Must reject GET without sending emails
        "content_contains": ["Method Not Allowed"],
    },
]

def run_healthchecks():
    print(f"=== Post-Deployment HTTPS Health Checks ===")
    print(f"Target Base URL: {BASE_URL}")
    print("Rules: Non-intrusive checks only; zero form submissions, zero LLM token consumption.\n")

    all_passed = True
    results = []

    for c in CHECKS:
        name = c["name"]
        url = c["url"]
        method = c["method"]
        expected_status = c["expected_status"]
        content_needles = c.get("content_contains", [])

        req = urllib.request.Request(
            url,
            method=method,
            headers={
                "User-Agent": "WPB-Deployment-Verifier/1.0 (+https://webpalmbeaches.com)"
            }
        )

        status_code = None
        body = ""
        error_msg = ""
        start_time = time.time()

        try:
            with urllib.request.urlopen(req, timeout=12) as resp:
                status_code = resp.getcode()
                body = resp.read().decode("utf-8", "ignore")
        except urllib.error.HTTPError as e:
            status_code = e.code
            try:
                body = e.read().decode("utf-8", "ignore")
            except Exception:
                body = ""
        except Exception as e:
            error_msg = str(e)

        elapsed = round(time.time() - start_time, 3)

        passed = True
        fail_reasons = []

        if status_code != expected_status:
            passed = False
            fail_reasons.append(f"HTTP status {status_code} != expected {expected_status}")

        for needle in content_needles:
            if needle not in body:
                passed = False
                fail_reasons.append(f"Missing expected content snippet: '{needle}'")

        if error_msg:
            passed = False
            fail_reasons.append(f"Connection error: {error_msg}")

        mark = "PASS" if passed else "FAIL"
        print(f"[{mark}] {name} ({elapsed}s)")
        print(f"       URL: {url} -> Status: {status_code or 'N/A'}")
        if fail_reasons:
            all_passed = False
            for reason in fail_reasons:
                print(f"       REASON: {reason}")
        print()

        results.append({
            "name": name,
            "url": url,
            "status": status_code,
            "passed": passed,
            "elapsed": elapsed,
            "reasons": fail_reasons
        })

    if all_passed:
        print("ALL HEALTH CHECKS PASSED: Live website is healthy!")
        return 0, results
    else:
        print("FAIL: One or more health checks failed! Immediate inspection or rollback required.", file=sys.stderr)
        return 1, results

if __name__ == "__main__":
    code, _ = run_healthchecks()
    sys.exit(code)
