#!/usr/bin/env python3
"""Response times of the read endpoints and the exports, measured over HTTP like the SPA calls them (D3e).

Run it on the server under test so the network adds nothing:

    sudo python3 http-timing.py --base https://demo-accounting.vcare.web.id --resolve 127.0.0.1 \
        --email direktur@demo.test --password-file /etc/accounting/demo.password

Each endpoint is called once to warm up, then `--runs` times; the median and the slowest are printed in milliseconds,
measured from the end of the TLS setup to the last byte (the setup of a new connection is not counted).
The password is read from the file and never printed. One user is limited to 120 requests a minute (API-TR-15), so a
full run paces itself; raise API_RATE_LIMIT_USER on the server for a heavier run.
"""
import argparse
import json
import statistics
import subprocess
import sys
import tempfile
import time
from urllib.parse import unquote

ap = argparse.ArgumentParser()
ap.add_argument("--base", required=True)
ap.add_argument("--resolve", help="IP to connect to instead of DNS (e.g. 127.0.0.1)")
ap.add_argument("--email", required=True)
ap.add_argument("--password-file", required=True)
ap.add_argument("--runs", type=int, default=5)
args = ap.parse_args()

host = args.base.split("//", 1)[1].split("/")[0]
jar = tempfile.NamedTemporaryFile(delete=False).name
password = open(args.password_file, encoding="utf-8").read().strip()


def curl(path, method="GET", accept="application/json", body=None, headers=()):
    cmd = ["curl", "-s", "-g", "-b", jar, "-c", jar, "-o", "-", "-w", "\n%{http_code} %{time_total} %{time_appconnect}", "-X", method,
           "-H", f"Accept: {accept}", "-H", f"Origin: {args.base}", "-H", f"Referer: {args.base}/"]
    if args.resolve:
        cmd += ["--resolve", f"{host}:443:{args.resolve}"]
    for h in headers:
        cmd += ["-H", h]
    if body is not None:
        cmd += ["-H", "Content-Type: application/json", "--data", json.dumps(body)]
    out = subprocess.run(cmd + [args.base + path], capture_output=True).stdout
    raw, _, tail = out.rpartition(b"\n")
    code, total, connected = tail.decode().split()
    # Milliseconds from the end of the TCP and TLS setup to the last byte: what the server did, without the connection each curl call opens
    # (behind Cloudflare the connection is reused, so its setup is not part of a request).
    return int(code), (float(total) - float(connected)) * 1000, raw


def xsrf():
    for line in open(jar, encoding="utf-8"):
        if "XSRF-TOKEN" in line:
            return unquote(line.rstrip("\n").split("\t")[-1])
    return ""


curl("/api/v1/csrf-cookie")
code, _, raw = curl("/login", "POST", body={"email": args.email, "password": password}, headers=[f"X-XSRF-TOKEN: {xsrf()}"])
if code not in (200, 204):
    sys.exit(f"login answered {code}: {raw[:200].decode(errors='replace')}")
del password

code, _, raw = curl("/api/v1/me/workspaces")
workspaces = json.loads(raw)["data"]
company = next(w for w in workspaces if w.get("company_id"))["company_id"]
base = f"/api/v1/companies/{company}"

accounts = {a["code"]: a["id"] for a in json.loads(curl(f"{base}/accounts?per_page=200")[2])["data"]}
definitions = {d["key"]: d["id"] for d in json.loads(curl(f"{base}/report-definitions")[2])["data"]}
year, whole, as_of = "from=2025-01-01&to=2025-12-31", "from=2025-01-01&to=2026-11-30", "2026-11-13"

csv, xlsx, pdf = "text/csv", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "application/pdf"
cases = [
    ("journals, first page", f"{base}/journals?per_page=50", "list", None),
    ("journals by cash account", f"{base}/journals?per_page=50&filter[account_id]={accounts.get('1111', '')}", "list", None),
    ("open items", f"{base}/open-items?per_page=50&filter[status]=open", "list", None),
    ("trial balance, one year", f"{base}/reports/trial-balance?{year}", "trial balance", None),
    ("trial balance, whole range", f"{base}/reports/trial-balance?{whole}", "trial balance", None),
    ("general ledger, cash, one year", f"{base}/reports/general-ledger?account_id={accounts.get('1111', '')}&{year}", "ledger", None),
    ("aging, receivables", f"{base}/reports/aging?as_of={as_of}&control_role=receivables_control", "aging", None),
]
for key, query in (("income_statement", year), ("balance_sheet", f"from={as_of}&to={as_of}"), ("cash_flow_indirect", year)):
    if key in definitions:
        cases.append((key + ", one year", f"{base}/report-definitions/{definitions[key]}/result?{query}", "statement", None))
cases += [
    ("export trial balance CSV", f"{base}/reports/trial-balance?{whole}", "export", csv),
    ("export trial balance XLSX", f"{base}/reports/trial-balance?{whole}", "export", xlsx),
    ("export trial balance PDF", f"{base}/reports/trial-balance?{whole}", "export", pdf),
]
if "balance_sheet" in definitions:
    cases.append(("export balance sheet PDF", f"{base}/report-definitions/{definitions['balance_sheet']}/result?from={as_of}&to={as_of}", "export", pdf))

# Proposed p95 targets of D3e, milliseconds (not agreed yet).
target = {"list": 300, "trial balance": 1000, "ledger": 1000, "statement": 2000, "aging": 2000, "export": 10000}

print(f"{'endpoint':34}{'status':>7}{'median':>9}{'slowest':>9}{'target':>8}")
for label, path, kind, accept in cases:
    curl(path, accept=accept or "application/json")  # warm-up
    times, codes = [], set()
    for _ in range(args.runs):
        code, ms, _raw = curl(path, accept=accept or "application/json")
        codes.add(code)
        times.append(ms)
        time.sleep(0.6)  # keeps one user under the rate limit
    median, slowest = statistics.median(times), max(times)
    flag = "" if codes == {200} and slowest <= target[kind] else "  <-- check"
    print(f"{label:34}{'/'.join(map(str, sorted(codes))):>7}{median:9.0f}{slowest:9.0f}{target[kind]:8d}{flag}")
