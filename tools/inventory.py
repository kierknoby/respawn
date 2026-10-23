#!/usr/bin/env python3
from pathlib import Path
import json
import subprocess
import hashlib
import tarfile

ROOT = Path(__file__).resolve().parents[1]
def git(*arguments):
    return subprocess.check_output(['git', *arguments], cwd=ROOT, text=True).strip()

tracked = git('diff', '--name-only', 'HEAD').splitlines()
new = git('ls-files', '--others', '--exclude-standard').splitlines()
paths = sorted(set(tracked + new))
staged = git('diff', '--cached', '--name-only').splitlines()
review = {'branch': git('branch', '--show-current'), 'head': git('rev-parse', '--short', 'HEAD'),
          'stagedFiles': staged, 'changedFiles': paths, 'changedFileCount': len(paths),
          'generatedArtefactsIgnored': True, 'note': 'Read-only Git inspection; no staging, commit, push or deployment performed.'}
snapshot = ROOT / 'artifacts/reconstruction-pass-starting-source.tgz'
if snapshot.exists():
    baseline = {}
    with tarfile.open(snapshot) as archive:
        for member in archive.getmembers():
            if member.isfile():
                baseline[member.name] = hashlib.sha256(archive.extractfile(member).read()).hexdigest()
    modified = sorted(name for name, digest in baseline.items() if (ROOT / name).is_file()
                      and hashlib.sha256((ROOT / name).read_bytes()).hexdigest() != digest)
    removed = sorted(name for name in baseline if not (ROOT / name).exists())
    added = sorted(set(paths) - set(baseline))
    review['reconstructionPass'] = {'startingBranch': '0-0-1_Dev',
        'startingHead': '147925b01d56896b3addc328a5e85c21d8a606b6',
        'branchUnchanged': git('branch', '--show-current') == '0-0-1_Dev',
        'headUnchanged': git('rev-parse', 'HEAD') == '147925b01d56896b3addc328a5e85c21d8a606b6',
        'startingSourceFileCount': len(baseline), 'modified': modified, 'added': added, 'removed': removed,
        'licenceUnchanged': hashlib.sha256((ROOT / 'LICENSE').read_bytes()).hexdigest() == baseline['LICENSE']}
OUT = ROOT / 'artifacts'
OUT.mkdir(exist_ok=True)
(OUT / 'changed-files.txt').write_text('\n'.join(paths) + '\n')
(OUT / 'worktree-review.json').write_text(json.dumps(review, indent=2) + '\n')
if 'reconstructionPass' in review:
    scope = review['reconstructionPass']
    (OUT / 'reconstruction-pass-files.txt').write_text('\n'.join(
        ['MODIFIED ' + name for name in scope['modified']] + ['ADDED ' + name for name in scope['added']]
        + ['REMOVED ' + name for name in scope['removed']]) + '\n')
if staged:
    raise SystemExit('Staged files found; left untouched for user review.')
print(f'{len(paths)} changed source files; index empty; {review["branch"]} at {review["head"]}')