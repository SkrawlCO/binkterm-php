#!/usr/bin/env bash
# Stage ONLY checksum-pinned published Light Up output, never a moving build.
# Usage: bash build.sh /path/to/verified-release-files
# Directory must contain lightup.{html,js,wasm} and published-source.tar.gz.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
python3 - "$here" "${1:?Provide the M2 release directory (see upstream.lock.json)}" "${2:-}" <<'PY'
import hashlib,json,pathlib,sys,tarfile,tempfile,subprocess
base=pathlib.Path(sys.argv[1]); source=pathlib.Path(sys.argv[2]);lock=json.loads((base/'upstream.lock.json').read_text())
archive=source/'published-source.tar.gz'
assert hashlib.sha256(archive.read_bytes()).hexdigest()==lock['source_archive_sha256'],'Source archive pin mismatch'
with tarfile.open(archive) as tar:
    version=tar.extractfile('puzzles-'+lock['release']+'/version.h').read().decode()
    assert lock['release'] in version
for name,digest in lock['artifacts'].items():
    assert hashlib.sha256((source/name).read_bytes()).hexdigest()==digest, name+' pin mismatch'
dest=base.parents[2]/'public_html/webdoors/tatham-web/assets'/lock['release'];dest.mkdir(parents=True,exist_ok=True)
for name in lock['artifacts']:
    (dest/name).write_bytes((source/name).read_bytes())
if len(sys.argv)>3 and sys.argv[3]=='--terminal':
    with tempfile.TemporaryDirectory(prefix='tatham-terminal-') as scratch:
        with tarfile.open(archive) as tar: tar.extractall(scratch,filter='data')
        upstream=pathlib.Path(scratch)/('puzzles-'+lock['release'])
        output=base.parents[2]/'native-doors/doors/tatham-terminal/terminal-engine'
        units=['lightup','midend','malloc','random','misc','drawing','dsf','tree234','combi']
        subprocess.run(['cc','-std=c99','-O1','-ffunction-sections','-fdata-sections','-I',str(upstream),str(base/'terminal_engine.c'),*[str(upstream/(u+'.c')) for u in units],'-Wl,--gc-sections','-lm','-o',str(output)],check=True,timeout=60)
print('Verified and staged '+lock['release']+' (Light Up only)')
PY
