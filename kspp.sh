#!/usr/bin/env bash
# ============================================================
# KSPP Hardening Profile Script
# BLS/grubby aware, idempotent, safer profile switching
# ============================================================

set -Eeuo pipefail

MODE="${1:-status}"
PROFILE_ARG="${2:-auto}"

SYSCTL_FILE="/etc/sysctl.d/99-kspp.conf"
GRUB_FILE="/etc/default/grub"
GRUB_BACKUP="/etc/default/grub.kspp.bak"

# ------------------------------------------------------------
# Sysctl profiles
# ------------------------------------------------------------

COMMON_SYSCTL=(
  "kernel.kptr_restrict=2"
  "kernel.dmesg_restrict=1"
  "kernel.unprivileged_bpf_disabled=1"
  "kernel.randomize_va_space=2"

  "fs.protected_hardlinks=1"
  "fs.protected_symlinks=1"
  "fs.protected_fifos=2"
  "fs.protected_regular=2"

  "net.core.bpf_jit_harden=2"
)

SAFE_SYSCTL=(
  "${COMMON_SYSCTL[@]}"
  "kernel.perf_event_paranoid=2"
  "kernel.yama.ptrace_scope=1"
)

STRICT_SYSCTL=(
  "${COMMON_SYSCTL[@]}"
  "kernel.perf_event_paranoid=3"
  "kernel.yama.ptrace_scope=2"

  # Strict only.
  # Value 1 blocks unprivileged io_uring unless allowed by group/capability.
  # Value 2 would disable io_uring globally and is more likely to break things.
  "kernel.io_uring_disabled=1"
)

# ------------------------------------------------------------
# Boot parameter profiles
# ------------------------------------------------------------

COMMON_ARGS=(
  "page_alloc.shuffle=1"
  "randomize_kstack_offset=on"
)

SAFE_ARGS=(
  "${COMMON_ARGS[@]}"
)

STRICT_ARGS=(
  "slab_nomerge"
  "init_on_alloc=1"
  "init_on_free=1"
  "${COMMON_ARGS[@]}"

  # Strict only.
  "debugfs=off"
  "vsyscall=none"
)

EMPTY_ARGS=()
EMPTY_SYSCTL=()

# All boot arg keys managed by this script.
# Used when switching profiles, so stale strict/safe args are removed first.
MANAGED_ARG_KEYS=(
  "slab_nomerge"
  "init_on_alloc"
  "init_on_free"
  "page_alloc.shuffle"
  "randomize_kstack_offset"
  "debugfs"
  "vsyscall"
)

RUNTIME_SYSCTL_KEYS=(
  "kernel.kptr_restrict"
  "kernel.dmesg_restrict"
  "kernel.unprivileged_bpf_disabled"
  "kernel.randomize_va_space"
  "kernel.perf_event_paranoid"
  "kernel.yama.ptrace_scope"

  "fs.protected_hardlinks"
  "fs.protected_symlinks"
  "fs.protected_fifos"
  "fs.protected_regular"

  "net.core.bpf_jit_harden"
  "kernel.io_uring_disabled"
)

log() {
  echo "[*] $*"
}

ok() {
  echo "[+] $*"
}

warn() {
  echo "[!] $*" >&2
}

die() {
  echo "[ERROR] $*" >&2
  exit 1
}

need_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    die "Run as root."
  fi
}

has_cmd() {
  command -v "$1" >/dev/null 2>&1
}

is_bls() {
  [[ -d /boot/loader/entries ]] && has_cmd grubby
}

join_by_space() {
  local IFS=' '
  echo "$*"
}

sysctl_proc_path() {
  local key="$1"
  echo "/proc/sys/${key//./\/}"
}

sysctl_exists() {
  local key="$1"
  [[ -e "$(sysctl_proc_path "$key")" ]]
}

array_has_key() {
  local needle="$1"
  shift

  local item key
  for item in "$@"; do
    key="${item%%=*}"
    [[ "$key" == "$needle" ]] && return 0
  done

  return 1
}

profile_args_var() {
  case "$1" in
    safe)   echo "SAFE_ARGS" ;;
    strict) echo "STRICT_ARGS" ;;
    none)   echo "EMPTY_ARGS" ;;
    *)      die "Unknown profile: $1" ;;
  esac
}

profile_sysctl_var() {
  case "$1" in
    safe)   echo "SAFE_SYSCTL" ;;
    strict) echo "STRICT_SYSCTL" ;;
    none)   echo "EMPTY_SYSCTL" ;;
    *)      die "Unknown profile: $1" ;;
  esac
}

detect_profile() {
  local cmdline
  cmdline="$(cat /proc/cmdline 2>/dev/null || true)"

  if echo "$cmdline" | grep -qw 'init_on_alloc=1' || \
     echo "$cmdline" | grep -qw 'init_on_free=1' || \
     echo "$cmdline" | grep -qw 'debugfs=off' || \
     echo "$cmdline" | grep -qw 'vsyscall=none' || \
     { [[ -f "$SYSCTL_FILE" ]] && grep -q '^kernel.perf_event_paranoid=3$' "$SYSCTL_FILE"; }; then
    echo "strict"
    return
  fi

  if echo "$cmdline" | grep -qw 'page_alloc.shuffle=1' || \
     echo "$cmdline" | grep -qw 'randomize_kstack_offset=on' || \
     { [[ -f "$SYSCTL_FILE" ]] && grep -q '^kernel.perf_event_paranoid=2$' "$SYSCTL_FILE"; }; then
    echo "safe"
    return
  fi

  echo "none"
}

resolve_profile() {
  local requested="$1"

  case "$requested" in
    auto|"")
      detect_profile
      ;;
    safe|strict|none)
      echo "$requested"
      ;;
    *)
      die "Unknown verify profile: $requested. Use safe, strict, none, or auto."
      ;;
  esac
}

apply_sysctl_file() {
  [[ -f "$SYSCTL_FILE" ]] || return 0

  log "Applying sysctl from $SYSCTL_FILE"
  sysctl --load="$SYSCTL_FILE" >/dev/null
}

write_sysctl_file() {
  local -n arr="$1"

  log "Writing $SYSCTL_FILE"

  {
    echo "# Managed by kspp.sh"
    echo "# Do not edit manually unless you know what you are doing."

    local line key proc
    for line in "${arr[@]}"; do
      key="${line%%=*}"
      proc="$(sysctl_proc_path "$key")"

      if [[ -e "$proc" ]]; then
        echo "$line"
      else
        echo "# skipped missing sysctl: $line"
        warn "Skipping missing sysctl: $key"
      fi
    done
  } > "$SYSCTL_FILE"

  apply_sysctl_file
}

backup_grub() {
  if [[ -f "$GRUB_FILE" && ! -f "$GRUB_BACKUP" ]]; then
    cp -a "$GRUB_FILE" "$GRUB_BACKUP"
    ok "Created backup: $GRUB_BACKUP"
  fi
}

get_grub_cmdline() {
  if [[ ! -f "$GRUB_FILE" ]]; then
    echo ""
    return
  fi

  if grep -qE '^GRUB_CMDLINE_LINUX=' "$GRUB_FILE"; then
    grep -E '^GRUB_CMDLINE_LINUX=' "$GRUB_FILE" \
      | sed -E 's/^GRUB_CMDLINE_LINUX="(.*)"/\1/'
  else
    echo ""
  fi
}

set_grub_cmdline() {
  local new_cmdline="$1"

  if grep -qE '^GRUB_CMDLINE_LINUX=' "$GRUB_FILE"; then
    sed -i -E "s|^GRUB_CMDLINE_LINUX=.*|GRUB_CMDLINE_LINUX=\"${new_cmdline}\"|" "$GRUB_FILE"
  else
    echo "GRUB_CMDLINE_LINUX=\"${new_cmdline}\"" >> "$GRUB_FILE"
  fi
}

remove_managed_args_from_line() {
  local line="$1"
  local out=()
  local word key remove managed

  for word in $line; do
    key="${word%%=*}"
    remove=0

    for managed in "${MANAGED_ARG_KEYS[@]}"; do
      if [[ "$key" == "$managed" ]]; then
        remove=1
        break
      fi
    done

    [[ "$remove" -eq 0 ]] && out+=("$word")
  done

  echo "${out[*]:-}"
}

update_grub_cfg() {
  if [[ -d /sys/firmware/efi ]]; then
    local cfg
    cfg="$(find /boot/efi/EFI -name grub.cfg 2>/dev/null | head -n1 || true)"
    [[ -n "$cfg" ]] || die "Could not find EFI grub.cfg"

    log "Regenerating GRUB config: $cfg"
    grub2-mkconfig -o "$cfg" >/dev/null
  else
    log "Regenerating GRUB config: /boot/grub2/grub.cfg"
    grub2-mkconfig -o /boot/grub2/grub.cfg >/dev/null
  fi
}

apply_boot_args_bls() {
  local -a desired_args=("$@")
  local -a remove_args=()
  local key

  for key in "${MANAGED_ARG_KEYS[@]}"; do
    remove_args+=("$key")
  done

  log "Removing old KSPP boot args from all kernels"
  grubby --update-kernel=ALL --remove-args="$(join_by_space "${remove_args[@]}")"

  if [[ "${#desired_args[@]}" -gt 0 ]]; then
    log "Adding boot args to all kernels: $(join_by_space "${desired_args[@]}")"
    grubby --update-kernel=ALL --args="$(join_by_space "${desired_args[@]}")"
  fi
}

apply_boot_args_legacy() {
  local -a desired_args=("$@")

  [[ -f "$GRUB_FILE" ]] || die "$GRUB_FILE not found"
  backup_grub

  local current cleaned new_cmdline

  current="$(get_grub_cmdline)"
  cleaned="$(remove_managed_args_from_line "$current")"

  if [[ "${#desired_args[@]}" -gt 0 ]]; then
    new_cmdline="$(echo "$cleaned $(join_by_space "${desired_args[@]}")" | xargs)"
  else
    new_cmdline="$(echo "$cleaned" | xargs)"
  fi

  log "Updating GRUB_CMDLINE_LINUX"
  set_grub_cmdline "$new_cmdline"
  update_grub_cfg
}

apply_boot_args() {
  if is_bls; then
    apply_boot_args_bls "$@"
  else
    apply_boot_args_legacy "$@"
  fi
}

remove_boot_args() {
  apply_boot_args
}

get_default_kernel_args() {
  if is_bls; then
    grubby --info=DEFAULT 2>/dev/null \
      | sed -n 's/^args=//p' \
      | sed -E 's/^"//; s/"$//'
  elif [[ -f "$GRUB_FILE" ]]; then
    get_grub_cmdline
  else
    echo "(unknown)"
  fi
}

print_arg_state() {
  local line="$1"
  local wanted="$2"
  local key="${wanted%%=*}"
  local word found=""

  for word in $line; do
    if [[ "$word" == "$wanted" ]]; then
      echo "OK        $wanted"
      return 0
    fi

    if [[ "${word%%=*}" == "$key" ]]; then
      found="$word"
    fi
  done

  if [[ -n "$found" ]]; then
    echo "DIFF      wanted: $wanted    found: $found"
    return 1
  fi

  echo "MISSING   $wanted"
  return 1
}

show_arg_state() {
  local line="$1"
  local label="$2"
  shift 2

  echo
  echo "[$label]"

  if [[ "$#" -eq 0 ]]; then
    echo "(none expected)"
    return
  fi

  local wanted
  for wanted in "$@"; do
    print_arg_state "$line" "$wanted" || true
  done
}

status() {
  local detected_profile
  detected_profile="$(detect_profile)"

  local args_var
  args_var="$(profile_args_var "$detected_profile")"
  local -n target_args="$args_var"

  echo "===== KSPP STATUS ====="
  echo

  echo "[Detected profile]"
  echo "$detected_profile"

  echo
  echo "[Boot mode]"
  if is_bls; then
    echo "BLS / grubby"
  else
    echo "Legacy GRUB"
  fi

  echo
  echo "[Current running kernel cmdline]"
  cat /proc/cmdline

  echo
  echo "[Configured default kernel cmdline - next boot]"
  local next_args
  next_args="$(get_default_kernel_args)"
  echo "$next_args"

  echo
  echo "[Persistent sysctl file]"
  if [[ -f "$SYSCTL_FILE" ]]; then
    cat "$SYSCTL_FILE"
  else
    echo "(none)"
  fi

  echo
  echo "[Runtime sysctl values]"
  local key
  for key in "${RUNTIME_SYSCTL_KEYS[@]}"; do
    if sysctl_exists "$key"; then
      sysctl "$key" 2>/dev/null || true
    else
      echo "$key = (missing)"
    fi
  done

  show_arg_state "$(cat /proc/cmdline)" "${detected_profile} boot args in current running kernel" "${target_args[@]}"
  show_arg_state "$next_args" "${detected_profile} boot args configured for next boot" "${target_args[@]}"

  echo
  echo "======================="
}

verify() {
  local profile
  profile="$(resolve_profile "$PROFILE_ARG")"

  local args_var sysctl_var
  args_var="$(profile_args_var "$profile")"
  sysctl_var="$(profile_sysctl_var "$profile")"

  local -n target_args="$args_var"
  local -n target_sysctl="$sysctl_var"

  echo "===== KSPP VERIFY ====="
  echo

  local cmdline
  cmdline="$(cat /proc/cmdline)"

  local klog
  klog="$(journalctl -k -b -o cat 2>/dev/null || dmesg 2>/dev/null || true)"

  local cfg="/boot/config-$(uname -r)"
  local failed=0

  echo "[Selected profile]"
  echo "$profile"
  echo

  echo "[Kernel]"
  uname -r
  echo

  echo "[Passed on current cmdline]"
  if [[ "${#target_args[@]}" -eq 0 ]]; then
    echo "(none expected)"
  else
    local arg
    for arg in "${target_args[@]}"; do
      print_arg_state "$cmdline" "$arg" || failed=1
    done
  fi

  echo
  echo "[Kernel boot warnings about managed args]"

  local unknown_lines
  unknown_lines="$(echo "$klog" | grep -Ei 'Unknown kernel command line parameters|unknown parameter|invalid.*parameter|Malformed early option' || true)"

  local matched_unknown=""
  local managed
  for managed in "${MANAGED_ARG_KEYS[@]}"; do
    if echo "$unknown_lines" | grep -Fq "$managed"; then
      matched_unknown+="$(echo "$unknown_lines" | grep -F "$managed")"$'\n'
    fi
  done

  if [[ -n "$matched_unknown" ]]; then
    echo "$matched_unknown"
    failed=1
  else
    echo "OK        no unknown/invalid warning found for managed KSPP args"
  fi

  echo
  echo "[Runtime sysctl values for selected profile]"

  if [[ "${#target_sysctl[@]}" -eq 0 ]]; then
    echo "(none expected)"
  else
    local line key expected actual
    for line in "${target_sysctl[@]}"; do
      key="${line%%=*}"
      expected="${line#*=}"

      if sysctl_exists "$key"; then
        actual="$(sysctl -n "$key" 2>/dev/null || true)"

        if [[ "$actual" == "$expected" ]]; then
          echo "OK        $key=$actual"
        else
          echo "WARN      $key expected $expected, found $actual"
          failed=1
        fi
      else
        echo "SKIP      $key is missing on this kernel"
      fi
    done
  fi

  echo
  echo "[page_alloc.shuffle runtime state]"
  if array_has_key "page_alloc.shuffle" "${target_args[@]}"; then
    if [[ -r /sys/module/page_alloc/parameters/shuffle ]]; then
      local shuffle
      shuffle="$(cat /sys/module/page_alloc/parameters/shuffle)"

      case "$shuffle" in
        1|Y|y|on|true)
          echo "OK        /sys/module/page_alloc/parameters/shuffle = $shuffle"
          ;;
        *)
          echo "WARN      /sys/module/page_alloc/parameters/shuffle = $shuffle"
          failed=1
          ;;
      esac
    else
      echo "WARN      /sys/module/page_alloc/parameters/shuffle not found"
      echo "          Kernel may lack CONFIG_SHUFFLE_PAGE_ALLOCATOR=y"
      failed=1
    fi
  else
    echo "SKIP      page_alloc.shuffle is not part of selected profile"
  fi

  echo
  echo "[mem auto-init state]"
  if array_has_key "init_on_alloc" "${target_args[@]}" || array_has_key "init_on_free" "${target_args[@]}"; then
    local meminit_state meminit_clear
    meminit_state="$(echo "$klog" | grep -i 'mem auto-init:.*heap alloc:' | tail -n1 || true)"
    meminit_clear="$(echo "$klog" | grep -i 'mem auto-init: clearing system memory may take some time' | tail -n1 || true)"

    if [[ -n "$meminit_state" ]]; then
      echo "$meminit_state"

      if echo "$meminit_state" | grep -q 'heap alloc:on'; then
        echo "OK        init_on_alloc appears active"
      else
        echo "WARN      init_on_alloc does not appear active in mem auto-init log"
        failed=1
      fi

      if echo "$meminit_state" | grep -q 'heap free:on'; then
        echo "OK        init_on_free appears active"
      else
        echo "WARN      init_on_free does not appear active in mem auto-init log"
        failed=1
      fi

      if [[ -n "$meminit_clear" ]]; then
        echo "OK        kernel printed memory-clearing notice"
      fi
    else
      if [[ -n "$meminit_clear" ]]; then
        echo "$meminit_clear"
        echo "PARTIAL   memory-clearing notice found; this normally indicates init_on_free is active"
        echo "WARN      state line with heap alloc/free was not found, so init_on_alloc cannot be confirmed from log"
      else
        echo "WARN      no detailed mem auto-init line found in kernel log"
        echo "          Try: journalctl -k -b -o cat | grep -i 'mem auto-init'"
        failed=1
      fi
    fi
  else
    echo "SKIP      init_on_alloc/init_on_free are not part of selected profile"
  fi

  echo
  echo "[randomize_kstack_offset support]"
  if array_has_key "randomize_kstack_offset" "${target_args[@]}"; then
    if [[ -r "$cfg" ]]; then
      if grep -q '^CONFIG_HAVE_ARCH_RANDOMIZE_KSTACK_OFFSET=y' "$cfg" && \
         grep -q '^CONFIG_RANDOMIZE_KSTACK_OFFSET=y' "$cfg"; then
        echo "OK        kernel config supports randomize_kstack_offset"
      else
        echo "WARN      kernel config may not support randomize_kstack_offset"
        failed=1
      fi
    else
      echo "WARN      $cfg not readable"
      failed=1
    fi
  else
    echo "SKIP      randomize_kstack_offset is not part of selected profile"
  fi

  echo
  echo "[debugfs state]"
  if array_has_key "debugfs" "${target_args[@]}"; then
    if grep -qw debugfs /proc/mounts; then
      echo "WARN      debugfs appears mounted:"
      grep -w debugfs /proc/mounts || true
      failed=1
    else
      echo "OK        debugfs is not mounted"
    fi
  else
    echo "SKIP      debugfs=off is not part of selected profile"
  fi

  echo
  echo "[vsyscall state]"
  if array_has_key "vsyscall" "${target_args[@]}"; then
    if grep -q '\[vsyscall\]' /proc/self/maps 2>/dev/null; then
      echo "WARN      [vsyscall] mapping is visible in /proc/self/maps"
      failed=1
    else
      echo "OK        no [vsyscall] mapping visible"
    fi
  else
    echo "SKIP      vsyscall=none is not part of selected profile"
  fi

  echo
  echo "[Kernel config hints]"
  if [[ -r "$cfg" ]]; then
    grep -E 'CONFIG_SHUFFLE_PAGE_ALLOCATOR|CONFIG_INIT_ON_ALLOC|CONFIG_INIT_ON_FREE|CONFIG_HAVE_ARCH_RANDOMIZE_KSTACK_OFFSET|CONFIG_RANDOMIZE_KSTACK_OFFSET|CONFIG_SLUB|CONFIG_DEBUG_FS|CONFIG_IO_URING|CONFIG_BPF_JIT|CONFIG_LEGACY_VSYSCALL' "$cfg" || true
  else
    echo "WARN      $cfg not readable"
  fi

  echo
  echo "[Interpretation]"
  echo "OK in /proc/cmdline means the bootloader passed it."
  echo "No unknown/invalid kernel warning means the kernel probably accepted it."
  echo "Some early/static params do not expose a runtime boolean."

  echo
  if [[ "$failed" -eq 0 ]]; then
    echo "[+] Verification looks good."
  else
    echo "[!] Verification found warnings. Review output above."
  fi

  echo "======================="
}

usage() {
  cat <<EOF
Usage: $0 {none|safe|strict|status|verify|help} [safe|strict|none|auto]

Modes:
  safe      Apply low-risk KSPP profile
  strict    Apply stronger KSPP profile
  none      Remove kspp.sh persistent sysctl file and managed boot args
  status    Show current runtime and next-boot state
  verify    Verify whether current boot appears to have accepted selected profile
            Examples:
              $0 verify
              $0 verify safe
              $0 verify strict

Important:
  Boot args require reboot before appearing in /proc/cmdline.
EOF
}

apply_safe() {
  need_root
  write_sysctl_file SAFE_SYSCTL
  apply_boot_args "${SAFE_ARGS[@]}"

  ok "Safe profile applied."
  warn "Boot parameters require reboot before they appear in /proc/cmdline."
  log "After reboot, run: $0 verify safe"
}

apply_strict() {
  need_root
  write_sysctl_file STRICT_SYSCTL
  apply_boot_args "${STRICT_ARGS[@]}"

  ok "Strict profile applied."
  warn "Boot parameters require reboot before they appear in /proc/cmdline."
  log "After reboot, run: $0 verify strict"
}

apply_none() {
  need_root

  if [[ -f "$SYSCTL_FILE" ]]; then
    rm -f "$SYSCTL_FILE"
    ok "Removed $SYSCTL_FILE"
  else
    log "$SYSCTL_FILE already absent"
  fi

  remove_boot_args

  ok "Removed managed KSPP boot args."
  warn "Runtime sysctl values may remain until reboot or until overridden by another sysctl file."
}

case "$MODE" in
  safe)
    apply_safe
    ;;
  strict)
    apply_strict
    ;;
  verify)
    verify
    ;;
  none)
    apply_none
    ;;
  status|"")
    status
    ;;
  help|-h|--help)
    usage
    ;;
  *)
    usage
    exit 1
    ;;
esac
