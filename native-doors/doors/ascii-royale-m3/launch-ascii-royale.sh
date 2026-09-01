#!/bin/bash
set -u
set -o pipefail

readonly PINNED_SHA='ac7d9771dfd788b278427db619e43989d4317029'
readonly DEFAULT_RUNTIME_ROOT='/var/www/html/data/runtime/ascii-royale-m3'
readonly DEFAULT_CHANNEL='/var/www/html/data/run/ascii-royale-m3/endpoint-id'
readonly MAX_CHANNEL_AGE=15

fail() {
    printf '%s\r\n' 'The ascii-royale arena is temporarily unavailable.' >&2
    exit 1
}

to_base36() {
    local number=$1 result='' remainder
    readonly digits='0123456789abcdefghijklmnopqrstuvwxyz'
    while (( number > 0 )); do
        remainder=$((number % 36))
        result="${digits:remainder:1}${result}"
        number=$((number / 36))
    done
    printf '%s' "$result"
}

if (( $# != 2 )); then
    fail
fi

readonly raw_name=$1
readonly user_id=$2
if [[ ! $user_id =~ ^[1-9][0-9]*$ ]] || (( user_id > 2147483647 )); then
    fail
fi

normalized=''
for (( i=0; i<${#raw_name}; i++ )); do
    ch=${raw_name:i:1}
    case "$ch" in
        [A-Z]) normalized+="${ch,,}" ;;
        [a-z0-9_-]) normalized+="$ch" ;;
    esac
done
[[ -n $normalized ]] || normalized='user'

suffix="-$(to_base36 "$user_id")"
base_len=$((12 - ${#suffix}))
(( base_len >= 1 )) || fail
call_sign="${normalized:0:base_len}${suffix}"
[[ $call_sign =~ ^[a-z0-9_-]{1,12}$ ]] || fail

readonly runtime_root=${ASCII_ROYALE_M3_RUNTIME_ROOT:-$DEFAULT_RUNTIME_ROOT}
readonly channel=${ASCII_ROYALE_M3_CHANNEL:-$DEFAULT_CHANNEL}
readonly binary="$runtime_root/$PINNED_SHA/ascii-royale"
readonly alsa_config="$runtime_root/alsa-null.conf"

[[ -f $channel && ! -L $channel ]] || fail
[[ $(stat -c '%u:%g:%a' -- "$channel" 2>/dev/null) == '0:0:640' ]] || fail
channel_dir=$(dirname -- "$channel")
[[ -d $channel_dir && ! -L $channel_dir ]] || fail
[[ $(stat -c '%u:%g:%a' -- "$channel_dir" 2>/dev/null) == '0:0:750' ]] || fail
[[ $(stat -c '%s' -- "$channel" 2>/dev/null) -le 1024 ]] || fail

now=$(date +%s) || fail
mtime=$(stat -c '%Y' -- "$channel" 2>/dev/null) || fail
age=$((now - mtime))
(( age >= -5 && age <= MAX_CHANNEL_AGE )) || fail

version='' file_sha='' updated_unix='' host_generation='' endpoint_id=''
while IFS='=' read -r key value; do
    case "$key" in
        version) version=$value ;;
        pinned_sha) file_sha=$value ;;
        updated_unix) updated_unix=$value ;;
        host_generation) host_generation=$value ;;
        endpoint_id) endpoint_id=$value ;;
        '') ;;
        *) fail ;;
    esac
done < "$channel"

[[ $version == '1' ]] || fail
[[ $file_sha == "$PINNED_SHA" ]] || fail
[[ $updated_unix =~ ^[0-9]+$ ]] || fail
content_age=$((now - updated_unix))
(( content_age >= -5 && content_age <= MAX_CHANNEL_AGE )) || fail
[[ $host_generation =~ ^[a-zA-Z0-9._-]{1,64}$ ]] || fail
[[ $endpoint_id =~ ^[0-9a-f]{64}$ ]] || fail
[[ -x $binary && -f $binary && ! -L $binary ]] || fail
[[ -f $alsa_config && ! -L $alsa_config ]] || fail

readonly private_home="${DOOR_HOME:-/tmp}/ascii-royale-m3"
mkdir -p -m 700 -- "$private_home" || fail
export HOME="$private_home"
export XDG_CONFIG_HOME="$private_home/config"
export ALSA_CONFIG_PATH="$alsa_config"
mkdir -p -m 700 -- "$XDG_CONFIG_HOME" || fail

exec "$binary" join "$endpoint_id" --name "$call_sign"
fail
