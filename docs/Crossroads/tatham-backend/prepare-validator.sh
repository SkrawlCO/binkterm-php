#!/usr/bin/env bash
# Build only a noninteractive validator from the checksum-pinned release archive.
# Usage: bash prepare-validator.sh /path/to/published-source.tar.gz
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
python3 - "$here" "${1:?Pinned source archive required}" <<'PY'
import hashlib,json,pathlib,subprocess,sys,tarfile,tempfile
base=pathlib.Path(sys.argv[1]); archive=pathlib.Path(sys.argv[2])
lock=json.loads((base/'upstream.lock.json').read_text())
if hashlib.sha256(archive.read_bytes()).hexdigest()!=lock['source_archive_sha256']:
    raise SystemExit('Source archive pin mismatch')
with tempfile.TemporaryDirectory(prefix='tatham-validator-') as scratch:
    with tarfile.open(archive) as tar: tar.extractall(scratch, filter='data')
    source=pathlib.Path(scratch)/('puzzles-'+lock['release'])
    dest=base/'generated';dest.mkdir(exist_ok=True)
    units=['lightup','midend','malloc','random','misc','drawing','dsf','tree234','combi']
    subprocess.run(['cc','-std=c99','-O1','-ffunction-sections','-fdata-sections','-I',str(source),str(base/'validate.c'),*[str(source/(u+'.c')) for u in units],'-Wl,--gc-sections','-lm','-o',str(dest/'validate')],check=True,timeout=60)
    print('Pinned canonical validator built: '+lock['revision'])
PY
