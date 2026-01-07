<?php

namespace ApiDto\BaseResponseDto;

use ApiDto\BaseValueObject\BaseValueObject;
use BackedEnum;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

abstract readonly class BaseResponseDto
{

    protected function __construct(...$args)
    {
        throw new LogicException(static::class." にコンストラクタが定義されていません。");
    }

    /**
     * レスポンス（JSON文字列 / 配列 / stdClass）から DTO を生成する。
     *
     * 前提：
     * - 子クラスは public promoted properties を持つ
     * - プロパティ名はレスポンスのキー名と一致（もしくは set() 相当のマッピングを子でやる）
     */
    public static function fromResponse(mixed $response): static
    {
        $data = self::normalizeToArray($response);

        $ref = new ReflectionClass(static::class);
        $props = $ref->getProperties(ReflectionProperty::IS_PUBLIC);

        $ctorArgs = [];

        foreach ($props as $prop) {
            $name = $prop->getName();

            if (!array_key_exists($name, $data)) {
                // レスポンスに無いなら null（またはデフォルト）に寄せる
                // 必須項目チェックしたい場合はここで例外にしてもOK
                $ctorArgs[$name] = null;
                continue;
            }

            $ctorArgs[$name] = self::castValueForProperty($prop, $data[$name]);
        }

        return new static(...$ctorArgs);
    }

    // ----------------- normalize / cast -----------------

    /**
     * 入力を array に正規化する。
     */
    private static function normalizeToArray(mixed $response): array
    {
        // JSON文字列
        if (is_string($response)) {
            $decoded = json_decode($response, true);

            if (!is_array($decoded)) {
                throw new LogicException('JSON のデコードに失敗しました。');
            }
            return $decoded;
        }

        // 既に配列
        if (is_array($response)) {
            return $response;
        }

        // stdClass / オブジェクト（配列に寄せる）
        if (is_object($response)) {
            // JsonSerializable なども含め、とにかく配列へ
            $encoded = json_encode($response);
            if ($encoded === false) {
                throw new LogicException('レスポンスオブジェクトの JSON 変換に失敗しました。');
            }

            $decoded = json_decode($encoded, true);
            if (!is_array($decoded)) {
                throw new LogicException('レスポンスオブジェクトの配列化に失敗しました。');
            }

            return $decoded;
        }

        throw new LogicException('fromResponse の入力は JSON文字列 / array / object のみ対応です。');
    }

    /**
     * プロパティ型に合わせて値を変換する。
     */
    private static function castValueForProperty(ReflectionProperty $prop, mixed $value): mixed
    {
        $type = $prop->getType();

        // 型宣言なし → そのまま（ただしネストがあれば配列化は済んでいる）
        if ($type === null) {
            return $value;
        }

        // union / intersection はまずは非対応（必要なら拡張）
        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        $typeName = $type->getName();

        // null 許容
        if ($value === null) {
            if ($type->allowsNull()) {
                return null;
            }
            // ここは厳しくするなら例外
            return null;
        }

        // built-in
        if ($type->isBuiltin()) {
            // array の場合、中身の型は DocBlock で解決する
            if ($typeName === 'array') {
                if (!is_array($value)) {
                    throw new LogicException(static::class . "::{$prop->getName()} は array ですが、入力が array ではありません。");
                }
                return self::castArrayByDocblock($prop, $value);
            }

            // scalar は軽く寄せる（厳密にしたいならここで型チェック）
            return $value;
        }

        // class-string
        if (!class_exists($typeName) && !enum_exists($typeName)) {
            // ここに来るなら autoload / タイプ指定ミス
            throw new LogicException(static::class . "::{$prop->getName()} の型 {$typeName} が解決できません。");
        }

        // BackedEnum
        if (is_subclass_of($typeName, BackedEnum::class)) {
            /** @var class-string<BackedEnum> $typeName */
            $converted = $typeName::tryFrom($value);
            if ($converted === null) {
                throw new LogicException(static::class . "::{$prop->getName()} の Enum 変換に失敗しました。");
            }
            return $converted;
        }

        // BaseValueObject
        if (is_subclass_of($typeName, BaseValueObject::class)) {
            /** @var class-string<BaseValueObject> $typeName */
            return $typeName::fromValue($value);
        }

        // BaseResponseDto（入れ子DTO）
        if (is_subclass_of($typeName, self::class)) {
            /** @var class-string<self> $typeName */
            return $typeName::fromResponse($value);
        }

        // その他のクラスはそのまま（必要なら例外にする）
        return $value;
    }

    /**
     * array 型プロパティの要素型を DocBlock から推測し、必要なら変換する。
     *
     * 対応例：
     * - @var list<FooResponseDto>
     * - @var array<int, FooResponseDto>
     * - @var list<SomeEnum>
     * - @var list<SomeValueObject>
     */
    private static function castArrayByDocblock(ReflectionProperty $prop, array $value): array
    {
        $doc = (string)$prop->getDocComment();
        if ($doc === '') {
            // Docが無ければそのまま
            return $value;
        }

        // ざっくりジェネリクス抽出: <Type>
        if (!preg_match('/@var\s+(?:list|array)\s*<\s*([\w\\\\]+)\s*>/u', $doc, $m)) {
            return $value;
        }

        $elemType = $m[1];

        // Enum
        if (enum_exists($elemType) && is_subclass_of($elemType, BackedEnum::class)) {
            $out = [];
            foreach ($value as $k => $v) {
                $e = $elemType::tryFrom($v);
                if ($e === null) {
                    throw new LogicException(static::class . "::{$prop->getName()} の配列要素 Enum 変換に失敗しました。");
                }
                $out[$k] = $e;
            }
            return $out;
        }

        // ValueObject
        if (class_exists($elemType) && is_subclass_of($elemType, BaseValueObject::class)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $elemType::fromValue($v);
            }
            return $out;
        }

        // ResponseDto
        if (class_exists($elemType) && is_subclass_of($elemType, self::class)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $elemType::fromResponse($v);
            }
            return $out;
        }

        // その他
        return $value;
    }
}
