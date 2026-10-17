"""PARK の AI の仕事の箱から仕事を取ってきて、手元の AI（Ollama）に渡し、結果を返す。

案件や個人の情報は、この PC と PARK の間だけでやり取りし、ほかの外部のサービスには送らない。
PC がついていて、これが動いている間だけ、AI の仕事が進む。

使い方（環境変数で設定する。合言葉は PARK の .env の PARK_AI_TOKEN と同じもの）:
    set PARK_URL=https://www.jona.jp/park
    set PARK_AI_TOKEN=（合言葉）
    set PARK_AI_MODEL=qwen2.5:7b        （省略可。Ollama に入れたモデルの名前）
    python park_ai_worker.py           （ずっと動く。止めるときは Ctrl+C）
    python park_ai_worker.py --once    （仕事を1つだけやって終わる）

環境変数のかわりに、~/.park-ai/config.json（{"PARK_URL": ..., "PARK_AI_TOKEN": ...}）にも書ける。
合言葉はこのリポジトリ（公開）には書かず、この PC にだけ置く。
PC の起動で自動で動かすときは pythonw.exe で動かす（窓は出ない）。そのときの記録は ~/.park-ai/worker.log。

Python の標準ライブラリだけで動く（追加のインストールは要らない）。
"""

import json
import os
import sys
import time
import urllib.error
import urllib.request

HOME = os.path.join(os.path.expanduser("~"), ".park-ai")
CONF = {}
if os.path.exists(os.path.join(HOME, "config.json")):
    with open(os.path.join(HOME, "config.json"), encoding="utf-8") as f:
        CONF = json.load(f)


def setting(name, default=""):
    return os.environ.get(name) or CONF.get(name) or default


PARK_URL = setting("PARK_URL", "http://localhost/park").rstrip("/")
TOKEN = setting("PARK_AI_TOKEN")
OLLAMA_URL = setting("OLLAMA_URL", "http://localhost:11434").rstrip("/")
MODEL = setting("PARK_AI_MODEL", "qwen2.5:7b")
IDLE_SECONDS = int(setting("PARK_AI_IDLE", "30"))

# pythonw で動かすと画面に出せないので、記録をファイルに書く
if sys.stdout is None or not sys.stdout.isatty() and "--log" in sys.argv:
    os.makedirs(HOME, exist_ok=True)
    sys.stdout = sys.stderr = open(os.path.join(HOME, "worker.log"), "a", encoding="utf-8", buffering=1)


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
