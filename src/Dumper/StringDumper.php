<?php

namespace Transip\Dumper;

use function ast\get_kind_name;
use function ast\get_metadata;
use function ast\kind_uses_flags;
use ast\Node;

const AST_DUMP_LINENOS = 1;

class StringDumper
{
    public static function ast_dump($ast, $options = 0): string
    {
        if ($ast instanceof Node) {
            $result = get_kind_name($ast->kind);
            if ($options & AST_DUMP_LINENOS) {
                $result .= " @ $ast->lineno";
                if (isset($ast->endLineno)) {
                    $result .= "-$ast->endLineno";
                }
            }
            if (kind_uses_flags($ast->kind) || $ast->flags != 0) {
                $result .= "\n    flags: " . StringDumper::format_flags($ast->kind, $ast->flags);
            }
            if (isset($ast->name)) {
                $result .= "\n    name: $ast->name";
            }
            if (isset($ast->docComment)) {
                $result .= "\n    docComment: $ast->docComment";
            }
            foreach ($ast->children as $i => $child) {
                $result .= "\n    $i: " . str_replace("\n", "\n    ", StringDumper::ast_dump($child, $options));
            }
            return $result;
        } else if ($ast === null) {
            return 'null';
        } else if (is_string($ast)) {
            return "\"$ast\"";
        } else {
            return (string) $ast;
        }
    }

    protected static function get_flag_info(): array
    {
        static $info;
        if ($info !== null) {
            return $info;
        }
        foreach (get_metadata() as $data) {
            if (empty($data->flags)) {
                continue;
            }
            $flagMap = [];
            foreach ($data->flags as $fullName) {
                $shortName = substr($fullName, strrpos($fullName, '\\') + 1);
                $flagMap[constant($fullName)] = $shortName;
            }
            $info[(int) $data->flagsCombinable][$data->kind] = $flagMap;
        }
        return $info;
    }

    private static function format_flags(int $kind, int $flags): string
    {
        list($exclusive, $combinable) = StringDumper::get_flag_info();
        if (isset($exclusive[$kind])) {
            $flagInfo = $exclusive[$kind];
            if (isset($flagInfo[$flags])) {
                return "{$flagInfo[$flags]} ($flags)";
            }
        } else if (isset($combinable[$kind])) {
            $flagInfo = $combinable[$kind];
            $names = [];
            foreach ($flagInfo as $flag => $name) {
                if ($flags & $flag) {
                    $names[] = $name;
                }
            }
            if (!empty($names)) {
                return implode(" | ", $names) . " ($flags)";
            }
        }
        return (string) $flags;
    }
}
