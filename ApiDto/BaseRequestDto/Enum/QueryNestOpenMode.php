<?php

namespace ApiDto\BaseRequestDto\Enum;

/**
 * クエリ生成時に、ネストした値（配列・コレクション）を
 * どのように展開して渡すかを定義する Enum。
 *
 * 主な用途：
 * - HTTP クエリパラメータの生成
 * - API リクエスト時のパラメータ整形
 * - Repository / QueryService における検索条件の変換
 *
 * ネスト構造を許容しない API や、
 * 展開形式が仕様で決まっている API に対応するために使用する。
 */
enum QueryNestOpenMode
{
    /**
     * ネストした値を JSON 文字列として展開する。
     *
     * 例：
     *   ['a' => [1, 2, 3]]
     *   → a=[1,2,3]
     *
     * JSON 形式のパラメータを受け付ける API 向け。
     */
    case JSONIZE;

    /**
     * ネストした値をカンマ区切りの文字列として展開する。
     *
     * 例：
     *   ['a' => [1, 2, 3]]
     *   → a=1,2,3
     *
     * フラットなクエリパラメータのみを想定した API 向け。
     */
    case IMPLODE_COMMA;
}
