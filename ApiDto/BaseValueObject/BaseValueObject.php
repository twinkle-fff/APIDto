<?php

namespace ApiDto\BaseValueObject;

use LogicException;

/**
 * 値オブジェクト（Value Object）の基底クラス。
 *
 * このクラスは、プリミティブ値（string / int / array など）を
 * 型としてラップし、ドメイン上の意味と制約を付与するための
 * 共通基盤として使用する。
 *
 * - 外部からの直接生成を禁止するため、コンストラクタは protected
 * - 不変性を保証するため readonly
 * - バリデーションや生成ルールは各具象クラスで定義する
 *
 * 想定用途：
 * - Request / Response DTO 内の型安全な値表現
 * - API パラメータの正規化
 * - ドメイン制約の明示化
 */
abstract readonly class BaseValueObject
{
    /**
     * 内部で保持する値。
     *
     * 型は具象クラス側で意味付け・制約を行うため、
     * この基底クラスではあえて型指定を行わない。
     *
     * @var mixed
     */
    protected function __construct(public $value)
    {
        $this->validate();
    }

    /**
     * 値の妥当性検証を行うためのフックメソッド。
     *
     * このメソッドは命名保護および構造統一のために定義されており、
     * 実際の検証処理は各具象 ValueObject でオーバーライドする。
     *
     * 例：
     * - 文字列長チェック
     * - 正規表現による形式検証
     * - null / 空値の禁止
     */
    protected function validate(): void
    {
        throw new LogicException(static::class."にvalidate()が定義されていません。");
    }

    /**
     * プリミティブ値から ValueObject を生成するファクトリメソッド。
     *
     * 具象クラス側で実装し、コンストラクタの代替として使用する。
     * 外部コードは new を使わず、このメソッド経由で生成することを想定。
     *
     * @param mixed $value
     * @return static
     */
    public static function fromValue($value): static
    {
        throw new LogicException(static::class."にfromvalue()が定義されていません。");
    }

    /**
     * 内部で保持している値を取得する。
     *
     * 主に以下の用途を想定：
     * - API リクエストへの変換
     * - シリアライズ処理
     * - ログ・デバッグ出力
     *
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }
}
