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

## 配布には含めない

`docs/` は **Release の zip から除いてある**（`.github/workflows/release.yml` の rsync）。

zip の中身は各サイトの `wp-content/plugins/hb-base/` に展開され、そこに置いた物は
`https://サイト/wp-content/plugins/hb-base/docs/...` で**ブラウザから直接取れる**（実測で200）。
検査用のファイルを本番の公開ディレクトリに置く理由が無い。

`docs/` 以下に物を足すときも、この除外に守られる（個別に指定していないため）。

## 新しいフィクスチャを足すとき

実在のホスト名・トークン・サイトID・客のメールアドレスが混ざっていないか確認すること。

```bash
grep -rnE "ts\.net|hbapp|wps_|whsec_|@[a-z0-9.-]+\.(com|jp|net)" docs/hb-support/fixtures/
```

公開リポジトリなので、ここに入れた物はそのまま公開される。
**守りはサイトごとのトークンで、API の形は読まれる前提**という設計（アプリ側と合意済み）。
