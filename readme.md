【現状】
俺の使うためのDTOのabstractクラスだ。

俺が使うために作ったが、お前らも使っていいぞ


インストール
packagestに登録しているので、composerから使用できる。

$ composer require keisukesaichi/api_dto

現在の最新バーションは 1.0.0 だ。


概要
このライブラリは、API通信用の Request / Response DTO を
「雑に書いても後で壊れない」ようにするための基盤だ。

目的は以下：

・immutable（readonly）前提
・deprecated を踏まない
・Reflection を乱用しない
・DTOに余計な仕事をさせない

DDDを厳密にやりたい人向けではない。
APIを殴るためのDTOを、ちゃんとした形で持ちたい人向け。


提供しているもの


BaseRequestDto
APIリクエスト用DTOの基底クラス。

・readonly（immutable）
・setXxx() による fluent な組み立て
・array / query / json への変換を提供

主な機能：

・fromArray(array): static
・setXxx($value)（__call 経由）
・toArray(): array
・toQuery(QueryNestOpenMode): string
・toJson(): string


BaseResponseDto
APIレスポンス用DTOの基底クラス。

・JSON / array / object を入口に受け取れる
・型宣言に基づいて自動キャスト
・ネストしたDTOにも対応（再帰）

対応している型：

・string / int / bool / null
・BackedEnum（tryFrom）
・BaseValueObject（fromValue）
・BaseResponseDto（fromResponse）
・array<Dto / Enum / ValueObject>
  ※ arrayの中身は @var list<Foo> のDocBlockから推測する


BaseValueObject
値オブジェクト用の基底クラス。

・fromValue() を必須化
・validate() を必須化
・実装忘れは即例外（fail fast）


QueryNestOpenMode
クエリ生成時に、配列などのネストをどう展開するかを指定するEnum。

・JSONIZE
  a=[1,2,3]

・IMPLODE_COMMA
  a=1,2,3


使用例（Request DTO）

final readonly class SearchRequest extends BaseRequestDto {
    public function __construct(
        public ?string $q = null,
        public int $limit = 10,
        public array $tags = [],
    ) {}
}

$req = SearchRequest::empty()
    ->setQ('test')
    ->setLimit(20)
    ->setTags(['php', 'api']);

$query = $req->toQuery(QueryNestOpenMode::IMPLODE_COMMA);
q=test&limit=20&tags=php,api


使用例（Response DTO）

final readonly class UserResponse extends BaseResponseDto {
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}

$user = UserResponse::fromResponse([
    'id' => 1,
    'name' => 'Alice',
]);


設計方針

・DTOは状態を持たない
・DTOにロジックを書かない
・setterは immutable 前提
・private / protected プロパティは使わない
・setAccessible() は使わない（PHP 8.1+ 対応）

「賢いDTO」ではなく
「壊れにくいDTO」を目指している。


向いている人

・APIクライアントを自作している
・古いSDKのdeprecatedに苦しんでいる
・arrayベタ書きDTOに疲れた
・PHP 8.1+ 前提で書きたい


向いていない人

・フルDDDフレームワークが欲しい人
・魔法の自動マッピングが好きな人
・DTOにビジネスロジックを書きたい人


ライセンス
MIT
