#!/usr/bin/env python3
"""Publish verified assets without replacing an already published byte sequence."""
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import release


def gh(*args):
    return subprocess.check_output(['gh', *args], text=True)


def main():
    _, version, *_ = release.metadata()
    tag = 'v' + version
    asset = f'{release.APP_ID}-{version}.tar.gz'
    names = [asset + suffix for suffix in ('', '.sha256', '.sha512', '.sig')]
    for name in names:
        if not (release.ROOT / 'dist' / name).is_file():
            raise ValueError('Missing release asset: ' + name)
    result = subprocess.run(['gh', 'release', 'view', tag, '--json', 'isDraft,assets'], text=True, capture_output=True)
    if result.returncode and 'release not found' not in result.stderr.lower() and '404' not in result.stderr:
        raise RuntimeError('Cannot read GitHub release; refusing to create or replace it')
    with tempfile.TemporaryDirectory() as temporary:
        directory = Path(temporary)
        notes = directory / 'notes.md'
        notes.write_text(release.notes(version))
        if result.returncode:
            args = ['release', 'create', tag, '--verify-tag', '--draft', '--title', 'AutoPhish ' + version, '--notes-file', str(notes)]
            if '-' in version:
                args.append('--prerelease')
            gh(*args)
            current = {'assets': [], 'isDraft': True}
        else:
            current = json.loads(result.stdout)
        existing = {item['name'] for item in current['assets']}
        if existing - set(names):
            raise ValueError('Unexpected existing assets; inspect the release manually')
        for name in names:
            source = release.ROOT / 'dist' / name
            if name in existing:
                gh('release', 'download', tag, '--pattern', name, '--dir', temporary)
                if hashlib.sha256(source.read_bytes()).digest() != hashlib.sha256((directory / name).read_bytes()).digest():
                    raise ValueError('Existing asset differs; refusing to overwrite: ' + name)
            else:
                gh('release', 'upload', tag, str(source))
        if current['isDraft']:
            gh('release', 'edit', tag, '--draft=false')
    print('GitHub Release contains the four verified assets')


if __name__ == '__main__':
    main()
