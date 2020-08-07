<?php

namespace Transip\Scope;

use ast\Node;
use const ast\AST_DIM;
use const ast\AST_VAR;

class ArrayVariableScope implements VariableScope
{
    private $globalScope;

    private $scopes;

    private $currentScope;

    public function __construct($map = [])
    {
        $this->globalScope = $map;
        $this->currentScope = &$this->globalScope;

        $this->scopes = [&$this->currentScope];
        $this->currentScope['$GLOBALS'] = &$this->globalScope;
    }

    public function stepIntoScope()
    {
        $newScope = $this->currentScope;
        $this->scopes[] = &$newScope;

        $this->currentScope = &$newScope;
        $this->currentScope['$GLOBALS'] = &$this->globalScope;
    }

    public function stepOutOfScope()
    {
        $this->currentScope = array_pop($this->scopes);
        if (count($this->scopes) === 1) {
            $this->currentScope = &$this->globalScope;
        }

        $this->currentScope['$GLOBALS'] = &$this->globalScope;
    }

    /**
     * @param Node $key
     * @return bool
     */
    public function canKeyBeStored(Node $key): bool
    {
        return $this->transformKey($key)[0] !== '';
    }

    /**
     * @param Node $key
     * @return string[] $variablePart, $dimPart (optional)
     */
    private function transformKey(Node $key)
    {
        if ($key->kind === AST_VAR) {
            return $this->transformVar($key);
        }

        if ($key->kind === AST_DIM) {
            return $this->transformDim($key);
        }

        return [''];
    }

    /**
     * @param Node $key
     * @return string[]
     */
    private function transformVar(Node $key): array
    {
        if ($key->kind !== AST_VAR) {
            return [''];
        }

        if (!is_string($key->children['name'])) {
            return [''];
        }

        return ['$' . $key->children['name']];
    }

    /**
     * @param Node $key
     * @return string[]
     */
    private function transformDim(Node $key): array
    {
        if ($key->kind !== AST_DIM ||
            !$this->isScalar($key->children['dim'])
        ) {
            return [''];
        }

        $isScalarExpr = $this->isScalar($key->children['expr']);
        $isNodeExpr = false;

        if (!$isScalarExpr) {
            $isNodeExpr = $key->children['expr'] instanceof Node;
        }

        if (!$isScalarExpr &&
            !$isNodeExpr) {
            return [''];
        }

        $expr = $key->children['expr'];

        if (!$isScalarExpr) {
            $expr = $this->transformVar($key->children['expr'])[0];
        }

        if ($expr === '') {
            return [''];
        }

        if ($isScalarExpr) {
            $expr = '$' . $expr;
        }

        $dimKey = $key->children['dim'];

        if ($expr === '$GLOBALS') {
            $dimKey = '$' . $dimKey;
        }

        return [$expr, $dimKey];
    }

    /**
     * @param mixed $item
     * @return bool
     */
    private function isScalar($item): bool
    {
        return is_string($item) || is_numeric($item);
    }

    /**
     * @param Node $key
     * @param mixed $value
     * @return bool
     */
    public function addToScope(Node $key, $value): bool
    {
        $transformedKey = $this->transformKey($key);

        if ($transformedKey[0] === '') {
            return false;
        }

        $keyAsString = $transformedKey[0];

        if (!isset($transformedKey[1])) {
            $this->currentScope[$keyAsString] = $value;

            if ($keyAsString === '$GLOBALS') {
                $this->currentScope['$GLOBALS'] = &$this->globalScope;
            }
            return true;
        }

        $this->currentScope[$keyAsString][$transformedKey[1]] = $value;

        return true;
    }

    /**
     * @param Node $key
     */
    public function makeGlobal(Node $key)
    {
        $transformedKey = $this->transformKey($key);

        if ($transformedKey[0] == '') {
            return;
        }

        $this->globalScope[$transformedKey[0]] = $this->globalScope[$transformedKey[0]] ?? null;
        $this->currentScope[$transformedKey[0]] = &$this->globalScope[$transformedKey[0]];
    }

    /**
     * @param Node $key
     * @return bool
     */
    public function existsInScope(Node $key): bool
    {
        $transformedKey = $this->transformKey($key);
        if (count($transformedKey) === 0) {
            return false;
        }

        $value = $this->currentScope;

        foreach ($transformedKey as $key) {
            if (!isset($value[$key])) {
                return false;
            }

            $value = $value[$key];
        }

        return true;
    }

    /**
     * @param Node $key
     * @return mixed
     * @throws NoSuchKeyInScopeException
     */
    public function getFromScope(Node $key)
    {
        $transformedKey = $this->transformKey($key);

        if (!$this->existsInScope($key)) {
            throw new NoSuchKeyInScopeException('Key does not exist in scope: ' . implode(' => ', $transformedKey));
        }

        $value = $this->currentScope;

        foreach ($transformedKey as $key) {
            $value = $value[$key];
        }

        return $value;
    }
}
