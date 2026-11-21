# 契約フィクスチャ（コピー）

**正本はアプリ側**（hb-documents）の `app/docs/wp-support/`。ここはそのコピー。
**この中を WP 側で直さない。** 直すとハッシュが合わなくなり、どちらが正しいか分からなくなる。

仕様の正本: `hb-documents/app/docs/wp-support-api-design.md`
往復ノート: `hb-documents/app/docs/wp-support/qa.md`（質問はここへ書く。実装で埋めない）

## 取り込み方（アプリ側で契約が動いたとき）

```bash
SRC=~/作業場/App/hb-documents/app/docs/wp-support
cp "$SRC"/fixtures/*.json docs/hb-support/fixtures/
cp "$SRC"/contract.sha256 docs/hb-support/
```

## 検査

```bash
cd docs/hb-support/fixtures && shasum -a 256 -c ../contract.sha256
```

**不一致＝アプリ側で契約が動いたのに取り込んでいない。** 取り直すこと。
一致しているあいだは、WP 側はこのフィクスチャだけで画面を作れる
（アプリの API がまだ無くても進められる）。
