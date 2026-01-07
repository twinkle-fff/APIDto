<?php

namespace ApiDto\BaseRequestDto;

use ApiDto\BaseRequestDto\Enum\QueryNestOpenMode;
use ApiDto\BaseValueObject\BaseValueObject;
use BackedEnum;
use LogicException;
use ReflectionClass;
use ReflectionType;

abstract readonly class BaseRequestDto
{
    public function __construct(...$args) {}

    public static function empty(): static
    {
        return new static();
    }

    /**
     * 配列から DTO を生成する（子クラスが public プロパティを定義している前提）
     */
    public static function fromArray(array $data): static
    {
        $dto = new static();

        foreach ($data as $k => $v) {
            $dto = $dto->set((string)$k, $v);
        }

        return $dto;
    }

    /**
     * readonly のため、プロパティ更新ではなく「更新済みの新インスタンス」を返す。
     *
     * 前提：
     * - 子クラスのプロパティは public（promoted properties 推奨）
     * - 子クラスのコンストラクタ引数名 ＝ プロパティ名
     */
    public function set(string $key, mixed $value): static
    {
        $ref = new ReflectionClass($this);

        if (!$ref->hasProperty($key)) {
            throw new LogicException(static::class . " に {$key} プロパティが存在しません。");
        }

        $props = $ref->getProperties();
        $ctorArgs = [];

        foreach ($props as $prop) {
            $name = $prop->getName();

            // setAccessible() を使わない方針のため、public 以外は許可しない
            if (!$prop->isPublic()) {
                throw new LogicException(
                    static::class . " のプロパティ '{$name}' は public である必要があります（setAccessible 非使用方針）。"
                );
            }

            $current = $prop->isInitialized($this) ? $prop->getValue($this) : null;
            $ctorArgs[$name] = ($name === $key)
                ? $this->coerceValue($prop->getType(), $value)
                : $current;
        }

        return new static(...$ctorArgs);
    }

    /**
     * setXxx($value) を __call で受ける。
     * 例: $dto->setLimit(10) → set('limit', 10)
     */
    public function __call(string $name, array $arguments)
    {
        if (!str_starts_with($name, 'set')) {
            throw new LogicException("未定義メソッド: {$name}");
        }

        if (strlen($name) <= 3) {
            throw new LogicException("setter 名が不正です: {$name}");
        }

        if (count($arguments) !== 1) {
            throw new LogicException(
                "{$name}() は引数を1つだけ受け取ります。"
            );
        }

        $prop = lcfirst(substr($name, 3));
        $value = $arguments[0];

        return $this->set($prop, $value);
    }
    /**
     * DTO を配列化する。
     *
     * - scalar/string/int/bool/null はそのまま
     * - BackedEnum は ->value
     * - BaseRequestDto は ->toArray()
     * - BaseValueObject は ->getValue()
     * - array は中身も再帰変換（入れ子DTO/VO/Enumも対応）
     *
     * ※ setAccessible() を使わないため、public プロパティのみが対象。
     */
    public function toArray(): array
    {
        $out = [];

        // public プロパティだけ取得される（private/protected は含まれない）
        foreach (get_object_vars($this) as $key => $value) {
            $out[$key] = $this->normalize($value);
        }

        return $out;
    }

    /**
     * クエリ文字列（a=1&b=2）を生成する。
     * ネストがある場合、モード未指定なら例外。
     */
    public function toQuery(?QueryNestOpenMode $queryNestOpenMode = null): string
    {
        $arr = $this->toArray();

        $hasNest = $this->containsNestedArray($arr);

        if ($hasNest && $queryNestOpenMode === null) {
            throw new LogicException('ネストした値があるため QueryNestOpenMode を指定してください。');
        }

        if ($queryNestOpenMode === QueryNestOpenMode::JSONIZE) {
            $arr = $this->jsonizeNested($arr);
        } elseif ($queryNestOpenMode === QueryNestOpenMode::IMPLODE_COMMA) {
            $arr = $this->implodeCommaNested($arr);
        }

        return http_build_query($arr, '', '&', PHP_QUERY_RFC3986);
    }

    public function toJson(int $jsonFlug = 0): string|false
    {
        return json_encode($this->toArray(), $jsonFlug);
    }

    // ----------------- 内部 util -----------------

    private function normalize(mixed $v): mixed
    {
        if ($v === null || is_scalar($v) || is_string($v)) {
            return $v;
        }

        if ($v instanceof BackedEnum) {
            return $v->value;
        }

        if ($v instanceof self) {
            return $v->toArray();
        }

        if ($v instanceof BaseValueObject) {
            return $v->getValue();
        }

        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $vv) {
                $out[$k] = $this->normalize($vv);
            }
            return $out;
        }

        throw new LogicException('toArray() で変換できない型: ' . get_debug_type($v));
    }

    private function containsNestedArray(array $arr): bool
    {
        foreach ($arr as $v) {
            if (is_array($v)) {
                return true;
            }
        }
        return false;
    }

    private function jsonizeNested(array $arr): array
    {
        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $arr[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
        }
        return $arr;
    }

    private function implodeCommaNested(array $arr): array
    {
        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $arr[$k] = implode(',', array_map(
                    fn($x) => is_scalar($x) || $x === null ? (string)$x : json_encode($x),
                    $v
                ));
            }
        }
        return $arr;
    }

    private function coerceValue(?ReflectionType $type, mixed $value): mixed
    {
        // ここは「型変換」の入り口。いつかつかう
        return $value;
    }
}
