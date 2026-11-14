# v4実装境界

完全保存・任意除外・世代間共有の設計を実装するための形式契約。現行コードへの接続前に読取り・拒否条件を検証する。旧v1/v2/v3の形は変更しない。

## 採用判断

内容ID先行の候補Aを基礎にする。候補BからDBの安定した順序と大きい値の有界読取りを採る。取得機に別の復号鍵を作る暗号化カタログ基盤、独自のパック符号化、公開参照ツリーは初期実装へ加えない。既存の制限付きtarをパックとして使う。

1TiBと32MiBパックなら、本文の最大パック数は32,768程度。微小ファイルによるメンバー数の制限、ルート・テーブル等でのパック分離を含め、確定記録は既存の100,000暗号文・32MiBの範囲を事前検証する。上限を超えた状態を成功にせず、今回の要求にない公開参照方式へ暗黙に切り替えない。

2案と比較判定は`/tmp/wrb-v4-architecture-20261030/`にある。利用可能なエージェントで比較しており、Claude/Grokで実行したとは扱わない。

## 利用側

`LiveBackup`が現在の操作ロック・保守所有情報・取消・転送再開を所有する。`LiveBackup::sourceV4()`から`V4Capture`へ、検証済みの対象ルート・保存ポリシー・保存先の識別を渡す。`WordPressSource`は旧形式の互換取得を担う。段階Aは共有を無効にする。段階Cは保存先選択だけを共有へ変更する。取得・復旧の別エンジンを増やさない。

`Restorer::restore()/resume()/inspect()/exportDatabase()/rebuildIndex()`はv4を振り分ける。復旧は選択した1世代の根と全ページ・パックだけで成立し、索引や以前の目録に依存しない。

## 固定値

- `encoding = wrb4-tar-raw1-sql1`。
- 内容片は最大1MiB。内容IDは`sha256(size . ':' . sha256(bytes))`。内容の種類・パス・時刻を含めない。
- パックは平文本文32MiBまたは512メンバーで区切る。tarの付加情報を含めた暗号文は34MiBまで。
- ページは復号後1MiBまたは1,024レコードで区切る。packsレコードは512メンバーを持てるよう256KiBまで、他のレコードは32KiBまで。
- 根は4MiB、ページ記述子8,192個、ルート64個まで。根にファイル別の全一覧を入れない。
- 通常ファイルとディレクトリ等の合計500,000項目。内容片はページ総件数で制限する。復旧対象全体は既存の1TiBまで。
- パックとページを合わせた暗号文100,000個まで。転送ジョブもパス単位ではなく、この暗号文単位で記録する。
- 私有メタデータの区画は符号化後4MiBまで。必要時にハッシュの次の桁で分割する。1回に1区画だけを読み、全件配列へ集約しない。

## キーと照合情報

既存のreceipt `{key,bytes,sha256}`を維持する。`bytes`と`sha256`は暗号文の照合値。内容の`size/sha256`は復号後の値なので混同しない。

```
packs/<scopeId>/<random64>.age
pages/<snapshotId>/<random64>.age
manifests/<snapshotId>.age
```

`scope = {accountId,bucket,unitId,keySetId,encoding}`。`scopeId=sha256(JSON_UNESCAPED_SLASHESで上記順に符号化)`。接続資格情報は含めない。アカウント・バケット・鍵・符号化方式の変更で別の索引となる。

既存の確定記録の`objects`へ全パック・全ページのreceiptを重複なく入れる。新しいreceiptキーは形式と保存先の全入口で検証する。旧manifestの中で新しいキーが使えるようにはしない。

## 根

厳密に次のフィールドだけを持つ。

```
format: wordpress-r2-backup
version: 4
dataClass: wordpress
unitId, snapshotId, keySetId, recipients, capturedAt
encoding: wrb4-tar-raw1-sql1
scope: {accountId,bucket,unitId,keySetId,encoding}
roots: [{id,name,sourcePath,type,mode}]
pages: [{kind,sequence,count,size,sha256,object}]
counts: {files,directories,links,contents,packs,databaseBytes,fileBytes}
database: {size,sha256,tableCount,events,environment}
wordpress: {siteUrl,policySha256,policy,evidence}
```

`roots.id/name`は`r0001`形式で重複不可。`sourcePath`は暗号化した資料内の元の絶対パスで、復旧先としては使わない。`type`は`directory|file`。復旧先は新規の`destination/roots/<name>`。`mode`は0〜0777。setuid/setgid/stickyや特殊ファイル等は事前拒否し、黙って落とさない。

`environment = {serverVersion,sqlMode,timeZone,characterSet,collation}`。値は元の接続条件を記録する。未対応のDB定義は事前に明示して拒否する。`evidence`には取得開始、DBロック取得、取得終了・照合時刻、テーブルごとの元件数・保存件数・除外件数と適用ルールを記録する。秘密値や除外前の行値は記録しない。

## ページとパック

ページは`{version:4,snapshotId,kind,sequence,records}`。`kind`は`packs|filesystem|database`。同じkind内のsequenceは0から連続する。根の記述子と本文のkind/sequence/count/size/hashを照合する。

`packs`ページのレコードは次の形。

```
{object:receipt,lane,members:[{id,size,sha256}]}
```

laneは`code|media|authentication|forms|other|database:<table-or-definition-id>`。混在を減らすための保存分類であり、除外ルールの代わりにはしない。パックのtarメンバーは`<contentId>`という通常ファイルだけ。リンク・ディレクトリ・重複ID・余分なメンバーは拒否する。全メンバーのサイズ・照合値とパック全体を検証する。

本文参照は`{id,size,sha256,pack}`。`pack`は上記のパックキー。メンバー名はidで一意。receiptはパックページに一元化する。

`filesystem`ページは次のレコードを元のルートごとの順に持つ。1ファイルの途中でページを跨げる。

```
{type:directory,rootId,path,mode}
{type:file,rootId,path,size,mode}
{type:part,content:{id,size,sha256,pack}}
{type:end,sha256}
{type:symlink,rootId,path,target}
```

fileからendまでは別のfile/directory/symlinkを挟まない。partの合計サイズとfile.size、全体SHA-256を照合する。空ファイルはpartなしでendを持つ。相対リンクは同じ保存ルート内の既知の対象へ解決できるものだけ。リンクを祖先にした書込みを禁止し、通常内容を組み立て終わってから作る。未対応のリンクは事前拒否する。

`database`ページは以下を順に持つ。本文は全体で1本のSQLとなり、空の隔離DBへ1回だけ取り込む。

```
{type:sql,stage,name,content:{id,size,sha256,pack}}
{type:table,name,rows,sourceRows,excludedRows,definitionSha256,rowSha256,columns,order,foreignKeys}
```

stageは`preamble|definition|data|postamble`。テーブルの定義と行データを別の安定した内容片にする。データ片へ世代・時刻を混ぜない。主キー/一意キー順、なければNULLとバイト列による全列順で同じ行集合を同じ順にする。生成列は定義に残し、INSERT列に含めない。FK（外部キー。表どうしの参照制約）は保持し、除外後の参照不成立は取得前に拒否する。復旧後も件数・値・定義・孤立行を照合する。

## 取得と索引

DBの`definitionSha256`は、MySQLが再作成時に表示を展開する冗長な`CHARACTER SET <charset> COLLATE <collation>`だけを、collationが同じcharsetを決める場合に`COLLATE <collation>`へ正規化した照合値とする。引用識別子・文字列・コメントの内部は変更しない。保存するCREATE本文は元のままで、SQLの内容片のSHA-256は元の全バイトを対象にする。復旧時にも同じ表示正規化を使い、実際の定義を書き換える処理は行わない。

保存対象から除外したファイルの本文は読まない。除外したログ・キャッシュの変更、出現、消失は保存対象の照合へ影響させない。処理用領域・索引・秘密鍵の既知の場所と保存元ルートの重なりは、除外設定とは別の安全条件として拒否する。除外本文中の未知の秘密鍵は検出・保証しない。含めるファイルで標準のage秘密鍵を検出した場合は拒否する。

平文のファイル本文・SQLを一時保存しない。内容片のバッファだけを有界メモリで保持し、パックをageへストリーム入力する。パックキーはバッファの作成前に決め、暗号文receiptはパックページへ記録する。

完全保存と共有無効の場合は現在の取得と同様、取得と最後の再照合を行う。除外付き共有の場合は先に内容ID集合を私有区画へ記録し、候補パックの全メンバーが今回の集合に属する場合だけ再利用する。足りない場合は、今回許可された取得元から必要内容だけを新しいパックへ入れる。旧暗号文を取得機で復号しない。この条件による物理的な再送は許容して報告する。

索引は候補・照合済み・内容一致・ロック・欠落時停止の`DedupIndex`契約を継承する。共有を有効にして索引が失われたら、管理端末での再構築か明示的な共有無効化へ案内する。無言で全量再送しない。本文の照合済み状態は転送と読み戻しの後にだけ記録する。

操作ロックを取得から転送・保持まで共有し、取消・取得失敗では未確定のパック参照を確定世代として扱わない。取得中にプロセスが消えたら新世代で取り直す。取得後の暗号文転送は同じjobから再開する。索引や保守所有情報が全保存ルートと重なる場合は事前拒否する。

## 復旧

パック記述子とパス衝突・公開所有情報は私有の分割メタデータへ記録し、ページを跨いで全件をPHP配列に集約しない。同じ内容を別パスへ戻す場合は、別のファイル実体を作る。元モードの適用は内容・DBの照合と公開所有記録の後。私有作業領域の0700条件を緩めない。

古い世代のinspect/restore/resume/discard/exportDatabase/rebuild-indexを残す。新しい形式の復旧試験は元の取得元・索引を削除したfixture（検証専用データ）で実行する。実データを使う復元試験は、利用者が明示した範囲と隔離環境で別途行う。

## 段階Cの索引契約

実サイト用は`V4ContentIndex`が所有する。旧`DedupIndex`の内容一致・候補・照合済み・排他・欠落時停止・再構築という契約を、パック内部の内容と分割MetadataMapへ適用する。旧1ファイル1暗号文用の保存キーと全件配列をv4へ流用しない。

索引は指定した永続私有ディレクトリに置き、`scopeId`とランダムな`indexId`で識別する。scopeに資格情報は含めない。`scope.json`は厳密なscopeと識別子、版、照合値を持つ。`contents`のMapは`contentId -> {pack}`、`packs`のMapは`packKey -> {descriptor,status,rootScopeSha256}`。statusはcandidate/ready/missing。元のパス・値・秘密鍵は格納しない。

`rootScopeSha256`は明示ルートのsourcePath/typeを整列した照合値で、modeやファイル名・時刻を含めない。完全保存で同じrootScopeから作ったパックは、その世代が使う内容だけを参照して共有できる。除外付き世代、またはルート変更・出自不明のパックは、全メンバーが現在の選択内容ID集合に含まれると証明できるときだけ共有する。証明できなければ必要内容を現在の取得元から再パックする。

新規のパックはcandidateとして記録し、R2への転送と本文のサイズ/SHA-256照合の後にだけreadyにする。ready後の内容Map更新は再実行しても同じ結果にする。LiveBackupの転送位置はready記録より後に進め、途中終了時は同じ保存パックと索引更新を再試行する。完了前の参照は既存の操作ロックと未完了stateによる削除禁止で保護する。

索引が消えた場合に、同scopeのpacksが保存先へ1個でもあれば新規作成して全量送信しない。管理端末で再構築するか、利用者が共有を明示的に無効にする。readyの暗号文が予想外に消失・破損しても停止する。正常な保持期限処理が削除したpackは、そのキーをmissingへ無効化してから次の取得へ渡す。

公開APIは次を基礎にする。ロック中だけ検索・更新する。

```
V4ContentIndex::openOrCreate(privateDirectory, scope, repository)
identity(): {scopeId,indexId}
withLock(operation)
lookup(contentId): ?{descriptor,rootScopeSha256}
verifyReadyPack(packKey): descriptor
recordCandidate(descriptor, rootScopeSha256)
markReady(receipt)
markMissing(keys)
requiresMembershipProof(full, rootScopeSha256): bool
V4ContentIndex::rootScopeSha256(roots): string
```

repositoryは同scopeのpackを逐次列挙できる。DirectR2では実接続先のaccount/bucketをscopeの正本にし、別accountのrootをコピーしても共有へ流用しない。Localの復旧では、検証したrootが一意に示すscopeを使用する。

共有のために選択内容IDの事前走査が必要な場合、SQL断片のIDはDB読取りロック中に収集する。選択fileのIDは、除外設定があり索引を使う新規取得では事前走査時にロック外で収集できる。それ以外の必要な場合はDB読取りロック中に収集する。ロック外で収集した場合も、実取得時と取得後に選択fileの内容を読み直して事前走査時のSHA-256と照合し、選択範囲・除外判定の根拠もロック中に確認する。不一致や欠落があれば確定しない。DBの再読は同じ接続・ロック・定義に結び付け、既に取得した検証結果と一致することを確認する。DBロックはファイルの書込み自体を止めないため、どちらの方式でもファイルの同時変更は照合で検出できる範囲に限られ、ファイルシステムのスナップショットを保証しない。本文やSQLを作業ディスクへ置かない。

管理端末の再構築は、暗号化root/pageと各packの全メンバーを復号照合してready索引を新しい私有領域へ出力する。未参照packも同scopeに限って全メンバーを照合して回収し、出自不明として全メンバー包含の証明を要求する。再構築途中のディレクトリを完成索引と扱わず、秘密鍵をWordPressへ送らない。
