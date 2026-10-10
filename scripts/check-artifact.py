#!/usr/bin/env python3
"""Reject unexpected paths, links and oversized release archives before installation."""
from pathlib import Path
import sys
import tarfile

APP = 'integration_autophish'
DIRECTORIES = {'appinfo', 'lib', 'templates', 'js', 'css', 'img'}
ROOT_FILES = {'LICENSE', 'license.md', 'README.md', 'CHANGELOG.md'}
REQUIRED = {'appinfo/info.xml', 'appinfo/routes.php', 'lib/AppInfo/Application.php'} | ROOT_FILES


def validate(path, signed=False):
    if Path(path).stat().st_size > 20 * 1024 * 1024:
        raise ValueError('Release exceeds the App Store 20 MiB limit')
    files = set()
    seen = set()
    with tarfile.open(path, 'r:gz') as archive:
        for entry in archive:
            parts = entry.name.split('/')
            if (parts[0] != APP or any(part.startswith('.') or not part for part in parts)
                    or not (entry.isdir() or entry.isfile()) or entry.name in seen):
                raise ValueError('Invalid archive path or entry type: ' + entry.name)
            seen.add(entry.name)
            if len(parts) == 1:
                if not entry.isdir():
                    raise ValueError('The archive root must be a directory')
                continue
            if parts[1] not in DIRECTORIES and not (len(parts) == 2 and parts[1] in ROOT_FILES and entry.isfile()):
                raise ValueError('Non-runtime archive entry: ' + entry.name)
            if any(part in {'vendor', 'node_modules', 'tests', 'fixtures'} for part in parts[1:]):
                raise ValueError('Development files in archive')
            relative = '/'.join(parts[1:])
            if entry.isfile():
                files.add(relative)
                if relative == 'appinfo/info.xml' and entry.size >= 512 * 1024:
                    raise ValueError('info.xml exceeds the App Store size limit')
        if not REQUIRED <= files:
            raise ValueError('Required runtime files missing')
        if ('appinfo/signature.json' in files) != signed:
            raise ValueError('Archive signature does not match its signed/unsigned designation')
    return len(files)


if __name__ == '__main__':
    try:
        print(f'Artifact validated ({validate(sys.argv[1], "--signed" in sys.argv[2:])} runtime files)')
    except (ValueError, OSError, tarfile.TarError) as error:
        sys.exit(str(error))
