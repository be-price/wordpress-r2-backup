# 同梱物の出典とライセンス

本プラグイン自身のライセンスは[GPL version 2またはそれ以降](LICENSE)です。同梱物はそれぞれの著作権表示・ライセンスを保持します。

| 同梱物 | 出典・固定版 | ライセンスと同梱表示 |
|---|---|---|
| age実行ファイル（Linux amd64 / Darwin arm64） | [FiloSottile/age](https://github.com/FiloSottile/age)、v1.3.2。`src/AgeBinary.php`で各バイナリのSHA-256を固定照合 | BSD-3-Clause、[bin/LICENSE.age](bin/LICENSE.age) |
| WordPress SQLite Database Integration / MySQL on SQLite | [WordPress/sqlite-database-integration](https://github.com/WordPress/sqlite-database-integration)、commit `c156cf1066d1f0fce65ef8769debf0a2480bef65`、plugin v3.1.2 | GPL-2.0-or-later、[LICENSE](dependencies/sqlite-database-integration/LICENSE)と[MYSQL-ON-SQLITE-LICENSE](dependencies/sqlite-database-integration/MYSQL-ON-SQLITE-LICENSE) |
| Symfony PHP 8.0 / 8.1 Polyfills | [symfony/polyfill](https://github.com/symfony/polyfill)、依存版は`composer.lock`で固定 | MIT。Composerで導入した各パッケージの`LICENSE`を配布物に保持 |

SQLite同梱物の構成と上流ファイルの扱いは[PROVENANCE.json](dependencies/sqlite-database-integration/PROVENANCE.json)に記録しています。ageバイナリを再配布する場合は`bin/LICENSE.age`も含めてください。

開発用のPHPUnitとその依存物は`composer.lock`に記録し、本番用の`composer install --no-dev`には含めません。開発用依存物を再配布する場合も、各パッケージの著作権表示・ライセンスを保持してください。
