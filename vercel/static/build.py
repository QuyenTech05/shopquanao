#!/usr/bin/env python3
"""Build an isolated, fingerprinted CDN package from explicitly public source assets."""
import argparse
import hashlib
import json
from pathlib import Path
import shutil

ROOT = Path(__file__).resolve().parents[2]
# Product images are mutable through admin upload/delete, so retain their origin URLs.
ASSETS = ['assets/css/style.css']


def build(destination: Path) -> None:
    destination.mkdir(parents=True, exist_ok=True)
    if any(destination.iterdir()):
        raise SystemExit('Output directory must be empty')
    manifest = {}
    for name in ASSETS:
        source = ROOT / 'public' / name
        digest = hashlib.sha256(source.read_bytes()).hexdigest()
        target = Path(name).with_name(f'{source.stem}.{digest[:16]}{source.suffix}')
        output = destination / target
        output.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, output)
        manifest['/' + name] = {'path': '/' + target.as_posix(), 'sha256': digest}
    (destination / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    (destination / 'vercel.json').write_text(json.dumps({
        'framework': None,
        'headers': [
            {'source': '/assets/(.*)', 'headers': [
                {'key': 'Cache-Control', 'value': 'public, max-age=31536000, immutable'},
                {'key': 'Access-Control-Allow-Origin', 'value': '*'},
            ]},
            {'source': '/manifest.json', 'headers': [{'key': 'Cache-Control', 'value': 'no-cache'}]},
        ],
    }, indent=2) + '\n')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('destination', type=Path)
    build(parser.parse_args().destination)
