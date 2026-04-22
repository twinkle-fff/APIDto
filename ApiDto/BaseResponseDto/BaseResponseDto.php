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
        throw new LogicException(static::class . " にコンストラクタが定義されていません。");
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
        $doc = (string) $prop->getDocComment();

        if ($doc === '') {
            return $value;
        }

        if (
            !preg_match(
                '/@var\s+(?:list|array)\s*<\s*(?:[\w\\\\]+\s*,\s*)?([\w\\\\]+)\s*>/u',
                $doc,
                $m
            )
        ) {
            return $value;
        }

        $elemType = self::resolveDocblockTypeName($prop, $m[1]);

        // DTO / VO / Enum の配列を想定しているが、
        // XML→JSONの都合で「1件だけだと連想配列」になる場合がある。
        // その場合は 1件の list とみなして包む。
        if (!array_is_list($value)) {
            $value = [$value];
        }

        $out = [];

        foreach ($value as $k => $v) {
            if ($v === null) {
                $out[$k] = null;
                continue;
            }

            if (enum_exists($elemType) && is_subclass_of($elemType, BackedEnum::class)) {
                $e = $elemType::tryFrom($v);
                if ($e === null) {
                    throw new LogicException(
                        static::class . "::{$prop->getName()}[$k] Enum {$elemType} 変換失敗"
                    );
                }
                $out[$k] = $e;
                continue;
            }

            if (class_exists($elemType) && is_subclass_of($elemType, BaseValueObject::class)) {
                try {
                    $out[$k] = $elemType::fromValue($v);
                } catch (\Throwable $e) {
                    throw new LogicException(
                        static::class . "::{$prop->getName()}[$k] ValueObject {$elemType} 変換失敗",
                        0,
                        $e
                    );
                }
                continue;
            }

            if (class_exists($elemType) && is_subclass_of($elemType, self::class)) {
                if (!is_array($v) && !is_object($v)) {
                    throw new LogicException(
                        static::class . "::{$prop->getName()}[$k] は {$elemType} ですが array/object ではありません"
                    );
                }
                $out[$k] = $elemType::fromResponse($v);
                continue;
            }

            throw new LogicException(
                static::class . "::{$prop->getName()} の配列要素型 {$elemType} は変換規則が定義されていません"
            );
        }

        return $out;
    }

    /**
     * DocBlock 上の型名を実行時に解決可能なクラス名へ補正する。
     *
     * 対応:
     * - 完全修飾名: Foo\Bar\Baz
     * - 同一namespaceの短縮名: Baz -> DeclaringClassのnamespace\Baz
     *
     * 非対応:
     * - use import / alias の解決
     */
    private static function resolveDocblockTypeName(ReflectionProperty $prop, string $typeName): string
    {
        // すでに存在するならそのまま
        if (class_exists($typeName) || enum_exists($typeName)) {
            return $typeName;
        }

        // 先頭 \ は除去して再確認
        $trimmed = ltrim($typeName, '\\');
        if (class_exists($trimmed) || enum_exists($trimmed)) {
            return $trimmed;
        }

        // namespace を補完
        if (!str_contains($trimmed, '\\')) {
            $namespace = $prop->getDeclaringClass()->getNamespaceName();
            $candidate = $namespace . '\\' . $trimmed;

            if (class_exists($candidate) || enum_exists($candidate)) {
                return $candidate;
            }
        }

        return $trimmed;
    }
}
