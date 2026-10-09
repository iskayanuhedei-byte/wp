"""Build the installable plugin ZIP without tests or development tools."""
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

root = Path(__file__).resolve().parents[1]
plugin = root / "thumbnail-source-repair"
target = root / "dist" / "thumbnail-source-repair-1.0.0.zip"
target.parent.mkdir(exist_ok=True)
files = [
    "thumbnail-source-repair.php",
    "includes/class-resolver.php",
    "includes/class-runner.php",
    "includes/class-admin.php",
    "assets/admin.js",
    "assets/admin.css",
    "readme.txt",
    "README.md",
]
with ZipFile(target, "w", compression=ZIP_DEFLATED, compresslevel=9) as archive:
    for name in files:
        info = ZipInfo(f"thumbnail-source-repair/{name}", (2026, 10, 9, 0, 0, 0))
        info.external_attr = (0o100644 << 16)
        info.compress_type = ZIP_DEFLATED
        archive.writestr(info, (plugin / name).read_bytes())
with ZipFile(target) as archive:
    if archive.testzip() is not None:
        raise SystemExit("The generated ZIP failed its integrity check.")
print(f"Created {target} ({target.stat().st_size} bytes; {len(files)} files).")
