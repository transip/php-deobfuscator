<?php

namespace Transip\Reducer;


use ast\Node;
use AstReverter\AstReverter;
use Transip\Resolve\Resolve;
use Transip\Scope\ArrayVariableScope;
use const ast\AST_ASSIGN;
use const ast\AST_CALL;
use const ast\AST_INCLUDE_OR_EVAL;
use const ast\AST_STMT_LIST;
use const ast\AST_UNARY_OP;
use const ast\AST_VAR;
use function ast\parse_code;

class PhpAstSourceReducer
{
    const AST_VERSION = 50;

    /** @var AstReverter */
    private $astReverter;

    public function __construct()
    {
        $this->astReverter = new AstReverter();
    }

    /**
     * @param string $source
     * @param int $maxIterations
     * @param null|ArrayVariableScope $variableMap
     * @return Node
     */
    public function reduceSource(string $source, int $maxIterations = 1, ?ArrayVariableScope $variableMap = null): Node
    {
        if ($variableMap === null) {
            $variableMap = new ArrayVariableScope();
        }

        // TODO: use php-parser-to-php-ast library to fallback on error
        $ast = parse_code($source, $version = static::AST_VERSION);

        $resolver = new Resolve($source, $variableMap);
        $ast = $resolver->resolve($ast);

        return $ast;
    }

    private function traverseAst($ast)
    {
        if (!$ast instanceof Node) {
            return $ast;
        }

        if ($ast->kind !== AST_STMT_LIST ||
            count($ast->children) === 0
        ) {
            return $ast;
        }

        $map = [];

        foreach ($ast->children as $i => $child) {
            if ($this->canMapAssign($child)) {
                $keyName = '$' . $child->children['var']->children['name'];
                $value = $child->children['expr'];

                $map[$keyName] = $value;
            }

            if ($this->isEval($child)) {
                $result = $this->processEval($child, $map);
                $result = $this->traverseAst($result);

                if ($result->kind === AST_STMT_LIST) {
                    array_splice($ast->children, $i, 1, $result->children);
                } else {
                    $ast->children[$i] = $result;
                }
            }
        }

        return $ast;
    }

    private function canMapAssign($node)
    {
        if (!$node instanceof Node) {
            return false;
        }

        if ($node->kind !== AST_ASSIGN) {
            return false;
        }

        if ($node->children['var']->kind !== AST_VAR) {
            return false;
        }

        if (!is_string($node->children['var']->children['name'])) {
            return false;
        }

        return true;
    }

    private function isEval($node)
    {
        if (!$node instanceof Node) {
            return false;
        }

        if ($node->kind === AST_INCLUDE_OR_EVAL &&
            $node->flags & \ast\flags\EXEC_EVAL) {
            return true;
        }

        if ($node->kind === AST_UNARY_OP) {
            return $this->isEval($node->children['expr']);
        }

        return false;
    }

    private function processEval($node, $map)
    {
        if (!$node instanceof Node) {
            return $node;
        }

        if ($node->kind === AST_UNARY_OP) {
            return $this->processEval($node->children['expr'], $map);
        }

        if ($node->kind === AST_INCLUDE_OR_EVAL &&
            $node->children['expr']->kind === AST_CALL) {
            $node->children['expr'] = $this->processCall($node->children['expr'], $map);
        }

        if ($node->kind === AST_INCLUDE_OR_EVAL &&
            is_string($node->children['expr'])) {
            return parse_code('<?php ' . $node->children['expr'], static::AST_VERSION);
        }

        return $node;
    }

    private function processCall($node, $map)
    {
        if (!$node instanceof Node) {
            return $node;
        }

        if (!$node->kind === AST_CALL) {
            return $node;
        }

        $functionName =  $node->children['expr'];

        if (!is_string($functionName) &&
            $functionName->kind === AST_VAR) {
            $functionName = $map['$' . $node->children['expr']->children['name']];
        }

        return $this->executeFunction($node, $functionName);
    }

    private function executeFunction($node, $functionName)
    {
        $arguments = $node->children['args']->children;

        switch($functionName) {
            case 'base64_decode':
                return base64_decode($arguments[0]);
            case 'gzinflate':
                return gzinflate($arguments[0]);
            case 'str_rot13':
                return str_rot13($arguments[0]);
            case 'preg_replace':
                return preg_replace($arguments[0], $arguments[1], $arguments[2]);
            default:
                return $node;
        }
    }
}
