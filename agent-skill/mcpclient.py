#!/usr/bin/env python3
"""Minimal MCP stdio client. Usage:
  mcpclient.py --cmd "<server command>" list
  mcpclient.py --cmd "<server command>" call <tool> '<json args>'
  mcpclient.py --cmd "<server command>" script file.json   (list of [tool,args])
"""
import json, subprocess, sys, argparse, os, threading, queue, time

class Client:
    def __init__(self, cmd, env=None, cwd=None):
        e = os.environ.copy(); e.update(env or {})
        self.p = subprocess.Popen(cmd, shell=True, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                  stderr=subprocess.PIPE, text=True, env=e, cwd=cwd, bufsize=1)
        self.q = queue.Queue(); self.id = 0
        threading.Thread(target=self._read, daemon=True).start()
        self.err = []
        threading.Thread(target=lambda: [self.err.append(l) for l in self.p.stderr], daemon=True).start()
    def _read(self):
        for line in self.p.stdout:
            line = line.strip()
            if not line: continue
            try: self.q.put(json.loads(line))
            except Exception: pass
    def req(self, method, params=None, timeout=60):
        self.id += 1; mid = self.id
        self.p.stdin.write(json.dumps({"jsonrpc": "2.0", "id": mid, "method": method, "params": params or {}}) + "\n"); self.p.stdin.flush()
        end = time.time() + timeout
        while time.time() < end:
            try: m = self.q.get(timeout=end - time.time())
            except queue.Empty: break
            if m.get("id") == mid: return m
        return {"error": "timeout", "stderr": "".join(self.err[-20:])}
    def notify(self, method, params=None):
        self.p.stdin.write(json.dumps({"jsonrpc": "2.0", "method": method, "params": params or {}}) + "\n"); self.p.stdin.flush()
    def init(self):
        r = self.req("initialize", {"protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "hark-test", "version": "1"}})
        self.notify("notifications/initialized"); return r
    def close(self):
        try: self.p.kill()
        except Exception: pass

if __name__ == "__main__":
    ap = argparse.ArgumentParser(); ap.add_argument("--cmd", required=True); ap.add_argument("--cwd"); ap.add_argument("rest", nargs="*")
    a = ap.parse_args(); c = Client(a.cmd, cwd=a.cwd)
    ini = c.init()
    if "error" in ini and ini.get("error") == "timeout": print("INIT FAIL", ini); sys.exit(1)
    op = a.rest[0] if a.rest else "list"
    if op == "list":
        r = c.req("tools/list"); tools = r.get("result", {}).get("tools", [])
        print(len(tools), "tools")
        for t in tools: print("-", t["name"], ":", (t.get("description") or "")[:90].replace("\n", " "))
        if not tools: print(json.dumps(r)[:2000])
    elif op == "call":
        r = c.req("tools/call", {"name": a.rest[1], "arguments": json.loads(a.rest[2] if len(a.rest) > 2 else "{}")}, timeout=120)
        print(json.dumps(r, ensure_ascii=False)[:4000])
    elif op == "script":
        for tool, args in json.load(open(a.rest[1])):
            r = c.req("tools/call", {"name": tool, "arguments": args}, timeout=120)
            res = r.get("result", {}); ok = not res.get("isError") and "error" not in r
            txt = json.dumps(res.get("content", r.get("error")), ensure_ascii=False)[:300]
            print(("OK  " if ok else "ERR ") + tool, txt)
    c.close()
