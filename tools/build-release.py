#!/usr/bin/env python3
"""Build a deterministic, source-bound plugin ZIP; never upload or deploy it."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import zipfile

root = Path(__file__).resolve().parents[1]


def git(*args):
    return subprocess.check_output(["git", "-C", str(root), *args])


if len(sys.argv) != 2:
    raise SystemExit("Usage: python3 tools/build-release.py /absolute/output-directory")
if git("status", "--porcelain").strip():
    raise SystemExit("Refusing to package an uncommitted working tree.")
destination = Path(sys.argv[1]).resolve()
destination.mkdir(parents=True, exist_ok=True)
commit = git("rev-parse", "HEAD").decode().strip()
slug = "mrn-recaptcha-enterprise-manager"
version = "0.2.0"
archive = destination / f"{slug}-{version}.zip"
files = git("ls-tree", "-r", "--name-only", commit).decode().splitlines()
selected = [name for name in files if name in (f"{slug}.php", "readme.txt", "README.md") or name.startswith(("includes/", "assets/", "docs/"))]
hashes = {}
with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as output:
    for name in sorted(selected):
        content = git("show", f"{commit}:{name}")
        info = zipfile.ZipInfo(f"{slug}/{name}", date_time=(2026, 10, 1, 0, 0, 0))
        info.external_attr = 0o100644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        output.writestr(info, content, compresslevel=9)
        hashes[name] = hashlib.sha256(content).hexdigest()
receipt = {
    "status": "local-candidate-not-published-or-deployed",
    "version": version,
    "source_repository": "mrnwebdesigns/mrn-recaptcha-enterprise-manager",
    "source_commit": commit,
    "archive": archive.name,
    "sha256": hashlib.sha256(archive.read_bytes()).hexdigest(),
    "size_bytes": archive.stat().st_size,
    "files": hashes,
    "excluded": ["tests", "tools", "credentials", "runtime database", "git metadata"],
}
(destination / "release-receipt.json").write_text(json.dumps(receipt, indent=2) + "\n")
print(json.dumps({key: value for key, value in receipt.items() if key != "files"}, indent=2))
