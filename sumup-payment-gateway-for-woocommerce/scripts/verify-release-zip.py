"""Validate release ZIP contents against the tracked distribution, optionally stage it."""

import hashlib
import json
from pathlib import Path
import shutil
import stat
import sys
import zipfile


def digest(stream):
    result = hashlib.sha256()
    while chunk := stream.read(1024 * 1024):
        result.update(chunk)
    return result.digest()


def verify(root, filename, manifest, destination=None):
    expected = {f"{manifest['slug']}/{name}": name for name in manifest['files']}
    directories = {f"{parent}/" for name in expected for parent in Path(name).parents if str(parent) != "."}
    with zipfile.ZipFile(filename) as archive:
        seen = set()
        seen_files = set()
        for entry in archive.infolist():
            name = entry.filename
            if (
                "\\" in name
                or any(part in ("", ".", "..") for part in name.removesuffix("/").split("/"))
                or name in seen
            ):
                raise ValueError(f"Unsafe or duplicate ZIP path: {name}")
            mode = stat.S_IFMT(entry.external_attr >> 16)
            seen.add(name)
            if entry.is_dir() and mode in (0, stat.S_IFDIR) and not entry.flag_bits & 1:
                if name not in directories:
                    raise ValueError(f"Unexpected ZIP directory: {name}")
                continue
            if entry.flag_bits & 1 or mode not in (0, stat.S_IFREG):
                raise ValueError(f"Encrypted or non-file ZIP entry: {name}")
            if name not in expected:
                raise ValueError(f"Unexpected ZIP entry: {name}")
            with archive.open(entry) as packaged, (root / expected[name]).open("rb") as source:
                if digest(packaged) != digest(source):
                    raise ValueError(f"ZIP entry differs from the current checkout: {name}")
            seen_files.add(name)
        missing = expected.keys() - seen_files
        if missing:
            raise ValueError(f"Missing ZIP entries: {', '.join(sorted(missing))}")

        if destination is not None:
            # Refuse an existing staging directory, and validate everything before writing.
            destination.mkdir()
            for entry in archive.infolist():
                if entry.is_dir():
                    continue
                output = destination / entry.filename
                output.parent.mkdir(parents=True, exist_ok=True)
                with archive.open(entry) as source, output.open("xb") as target:
                    shutil.copyfileobj(source, target)
                output.chmod(0o644)


if __name__ == "__main__":
    try:
        verify(
            Path(sys.argv[1]),
            sys.argv[2],
            json.load(sys.stdin),
            Path(sys.argv[3]) if len(sys.argv) > 3 else None,
        )
    except (OSError, ValueError, zipfile.BadZipFile, RuntimeError) as error:
        print(error, file=sys.stderr)
        sys.exit(1)
