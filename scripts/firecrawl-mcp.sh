#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT_DIR/.env"
CREDENTIALS_FILE="${HOME}/.config/firecrawl-cli/credentials.json"

if [[ -f "$ENV_FILE" ]]; then
  while IFS= read -r line || [[ -n "$line" ]]; do
    case "$line" in
      ""|\#*)
        continue
        ;;
      FIRECRAWL_API_KEY=*|FIRECRAWL_API_URL=*)
        export "$line"
        ;;
    esac
  done < "$ENV_FILE"
fi

if [[ -f "$CREDENTIALS_FILE" ]]; then
  if [[ -z "${FIRECRAWL_API_KEY:-}" ]]; then
    FIRECRAWL_API_KEY="$(node -e "const fs=require('fs');try{const data=JSON.parse(fs.readFileSync(process.argv[1],'utf8'));if(data.apiKey)process.stdout.write(data.apiKey)}catch{}" "$CREDENTIALS_FILE")"
    export FIRECRAWL_API_KEY
  fi

  if [[ -z "${FIRECRAWL_API_URL:-}" ]]; then
    FIRECRAWL_API_URL="$(node -e "const fs=require('fs');try{const data=JSON.parse(fs.readFileSync(process.argv[1],'utf8'));if(data.apiUrl)process.stdout.write(data.apiUrl)}catch{}" "$CREDENTIALS_FILE")"
    export FIRECRAWL_API_URL
  fi
fi

if [[ -z "${FIRECRAWL_API_KEY:-}" && -z "${FIRECRAWL_API_URL:-}" ]]; then
  echo "Set FIRECRAWL_API_KEY or FIRECRAWL_API_URL in $ENV_FILE, or authenticate the CLI with firecrawl config, before starting firecrawl-mcp." >&2
  exit 1
fi

exec firecrawl-mcp
