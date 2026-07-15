_conductor_completion()
{
    local cur
    cur="${COMP_WORDS[COMP_CWORD]}"

    COMPREPLY=()
    while IFS= read -r candidate; do
        COMPREPLY+=("$candidate")
    done < <(conductor __complete "$COMP_CWORD" "${COMP_WORDS[@]}" 2>/dev/null)

    if [[ "$cur" == --*=* ]]; then
        compopt -o nospace 2>/dev/null || true
    fi
}

complete -o default -o bashdefault -F _conductor_completion conductor
