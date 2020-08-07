<?php


namespace Transip\Scope;


use ast\Node;

interface FunctionScope
{
    public function addToScope(string $name, Node $content) : bool;
    public function existsInScope(string $name) : bool;
    public function getFromScope(string $name) : Node;

    /**
     * Gets a cloned AST that represents the same code as the original
     *  statements in the function body.
     *
     * @param string $name
     * @param int $version
     * @return Node
     */
    public function getClonedStmtsFromScope(string $name, int $version): Node;
}