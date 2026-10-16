"""PARK の AI の仕事の箱から仕事を取ってきて、手元の AI（Ollama）に渡し、結果を返す。

案件や個人の情報は、この PC と PARK の間だけでやり取りし、ほかの外部のサービスには送らない。
PC がついていて、これが動いている間だけ、AI の仕事が進む。

使い方（環境変数で設定する。合言葉は PARK の .env の PARK_AI_TOKEN と同じもの）:
    set PARK_URL=https://www.jona.jp/park
    set PARK_AI_TOKEN=（合言葉）
    set PARK_AI_MODEL=qwen2.5:7b        （省略可。Ollama に入れたモデルの名前）
    python park_ai_worker.py           （ずっと動く。止めるときは Ctrl+C）
    python park_ai_worker.py --once    （仕事を1つだけやって終わる）

Python の標準ライブラリだけで動く（追加のインストールは要らない）。
"""

import json
import os
import sys
import time
import urllib.error
import urllib.request

PARK_URL = os.environ.get("PARK_URL", "http://localhost/park").rstrip("/")
TOKEN = os.environ.get("PARK_AI_TOKEN", "")
OLLAMA_URL = os.environ.get("OLLAMA_URL", "http://localhost:11434").rstrip("/")
MODEL = os.environ.get("PARK_AI_MODEL", "qwen2.5:7b")
IDLE_SECONDS = int(os.environ.get("PARK_AI_IDLE", "30"))


def post(url, data, headers=None, timeout=60):
    body = json.dumps(data, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(url, data=body, method="POST",
                                 headers={"Content-Type": "application/json", "Accept": "application/json", **(headers or {})})
    with urllib.request.urlopen(req, timeout=timeout) as res:
        return json.loads(res.read().decode("utf-8"))


def park(path, data=None):
    return post(PARK_URL + path, data or {}, {"X-Park-Ai-Token": TOKEN})


def ask_ai(prompt):
    # Ollama の chat API。答えを JSON に限らせる（format: json）。CPU だけだと数分かかるので、長く待つ
    res = post(OLLAMA_URL + "/api/chat", {
        "model": MODEL,
        "messages": [{"role": "user", "content": prompt}],
        "format": "json",
        "stream": False,
        "options": {"temperature": 0.2, "num_ctx": 8192},
    }, timeout=1800)
    return res["message"]["content"]


def work_once():
    job = park("/ai/worker/next").get("job")
    if not job:
        return False
    print(f"[{time.strftime('%H:%M:%S')}] 仕事 #{job['id']}（{job['kind']}）を始めます", flush=True)
    try:
        out = ask_ai(job["prompt"])
        park(f"/ai/worker/{job['id']}/result", {"output": out, "engine": f"ollama:{MODEL}"})
        print(f"[{time.strftime('%H:%M:%S')}] 仕事 #{job['id']} を返しました", flush=True)
    except Exception as e:  # AI が止まっていても、PARK には失敗として返す
        park(f"/ai/worker/{job['id']}/result", {"error": str(e)[:500], "engine": f"ollama:{MODEL}"})
        print(f"[{time.strftime('%H:%M:%S')}] 仕事 #{job['id']} は失敗しました：{e}", flush=True)
    return True


def main():
    if not TOKEN:
        sys.exit("PARK_AI_TOKEN が設定されていません（PARK の .env と同じ合言葉を入れてください）")
    once = "--once" in sys.argv
    print(f"PARK の AI の仕事の箱（{PARK_URL}）を見ています。AI：{MODEL}（{OLLAMA_URL}）", flush=True)
    while True:
        try:
            did = work_once()
        except urllib.error.HTTPError as e:
            print(f"PARK につながりません（{e.code}）。合言葉と URL を確かめてください", flush=True)
            did = False
        except urllib.error.URLError as e:
            print(f"つながりません：{e.reason}", flush=True)
            did = False
        if once:
            break
        if not did:
            time.sleep(IDLE_SECONDS)


if __name__ == "__main__":
    main()
