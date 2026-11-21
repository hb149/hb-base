#!/usr/bin/env bash
#
# 契約の検査。
#
#   1. 手元の fixtures が手元の contract.sha256 と合っているか（部分取り込みの検出）
#   2. 手元の版が上流と同じか（丸ごと古いままの検出）
#
# 2 は上流のリポジトリが同じマシンにある時だけ走る。
# **走らなかった時は必ずそう出力する**（黙ってスキップすると「走っていない検査」になる）。
# 本線は ping が名乗る contract_version との照合（管理画面のサポート連携タブ）。

set -u

here="$(cd "$(dirname "$0")/.." && pwd)"
docs="$here/docs/hb-support"
upstream="${HB_CONTRACT_UPSTREAM:-$HOME/作業場/App/hb-documents/app/docs/wp-support}"
status=0

echo "== 1. fixtures × contract.sha256 =="
if ( cd "$docs/fixtures" && shasum -a 256 -c ../contract.sha256 ); then
  echo "   OK"
else
  echo "   NG: 取り込みが途中です。fixtures と contract.sha256 を揃えて取り直してください。"
  status=1
fi

echo
echo "== 2. 手元の版 × 上流の版 =="
if [ -f "$docs/contract.version" ]; then
  mine="$(tr -d '[:space:]' < "$docs/contract.version")"
  echo "   手元: $mine"
else
  echo "   NG: contract.version がありません。"
  status=1
  mine=""
fi

if [ -f "$upstream/contract.version" ]; then
  theirs="$(tr -d '[:space:]' < "$upstream/contract.version")"
  echo "   上流: $theirs"
  if [ -n "$mine" ] && [ "$mine" != "$theirs" ]; then
    echo "   NG: 上流が動いています。取り直してください。"
    status=1
  elif [ -n "$mine" ]; then
    echo "   OK"
  fi
else
  # ここを黙って通さない。CI や本番には上流が無く、そこで静かに消える検査になる。
  echo "   SKIP: 上流が見つかりません（${upstream}）"
  echo "   → この検査は走っていません。版の照合は管理画面のサポート連携タブ"
  echo "     （ping の contract_version との突き合わせ）が本線です。"
fi

exit "$status"
