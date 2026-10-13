from pathlib import Path
import subprocess
import sys
root = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(root / '.local/qa-media-runtime'))
import imageio_ffmpeg
destination = root / 'docs/qa/fixtures/product-demo.webm'
destination.parent.mkdir(parents=True, exist_ok=True)
subprocess.run([imageio_ffmpeg.get_ffmpeg_exe(), '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i', 'color=c=0x2e3192:s=320x180:r=10', '-t', '2', '-an', '-c:v', 'libvpx', '-b:v', '150k', '-y', str(destination)], check=True)
print({'fixture_bytes': destination.stat().st_size})
