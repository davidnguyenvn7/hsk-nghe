#!/usr/bin/env python3
"""
Trợ giúp TTS cho chế độ "Python trên máy này" của plugin (chỉ dùng trên máy dev / Local).
stdin:  [{"text": "...", "voice": "zh-CN-XiaoxiaoNeural", "rate": "+0%", "path": "C:/.../clips/abc.mp3"}, …]
stdout: {"ok": [path…], "errors": {path: "lý do"}}
Cần: pip install edge-tts truststore
"""
import asyncio, json, sys

try:
    import truststore
    truststore.inject_into_ssl()
except ImportError:
    pass
import edge_tts


async def one(sem, job, out):
    async with sem:
        err = None
        for attempt in range(3):
            try:
                await edge_tts.Communicate(job["text"], job["voice"], rate=job["rate"]).save(job["path"])
                out["ok"].append(job["path"])
                return
            except Exception as e:  # mạng chập chờn → thử lại
                err = str(e)
                await asyncio.sleep(2 * (attempt + 1))
        out["errors"][job["path"]] = err


async def main():
    jobs = json.loads(sys.stdin.buffer.read().decode("utf-8"))
    out = {"ok": [], "errors": {}}
    sem = asyncio.Semaphore(6)
    await asyncio.gather(*(one(sem, j, out) for j in jobs))
    sys.stdout.write(json.dumps(out))


asyncio.run(main())
