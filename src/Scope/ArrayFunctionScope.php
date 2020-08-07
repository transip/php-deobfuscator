<?php


namespace Transip\Scope;

use ast\Node;
use AstReverter\AstReverter;

class ArrayFunctionScope implements FunctionScope
{
    /** @var array of string => Node */
    private $functionMap;

    /** @var array of string */
    private $functionStmtMap;

    public function __construct(array $map = [])
    {
        $this->functionMap = $map;
        $this->functionStmtMap = [];
    }

    public function addToScope(string $name, Node $content): bool
    {
        $this->functionMap[$name] = $content;
        return true;
    }

    public function existsInScope(string $name): bool
    {
        return isset($this->functionMap[$name]);
    }

    public function getFromScope(string $name): Node
    {
        if (!$this->existsInScope($name)) {
            throw new NoSuchKeyInScopeException("No function called {$name} found in map");
        }

        return $this->functionMap[$name];
    }

    public function getClonedStmtsFromScope(string $name, int $version): Node
    {
        $stmtsString = $this->getStringStmtsFromScope($name);
        return \ast\parse_code($stmtsString, $version);
    }

    private function getStringStmtsFromScope(string $name): string
    {
        if (!isset($this->functionStmtMap[$name])) {
            $functionNode = $this->getFromScope($name);
            $stmts = $functionNode->children['stmts'];

            $reverter = new AstReverter();
            $stringStmts = $reverter->getCode($stmts);

            $this->functionStmtMap[$name] = $stringStmts;
        }

        return $this->functionStmtMap[$name];
    }
}