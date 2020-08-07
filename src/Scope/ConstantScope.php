<?php


namespace Transip\Scope;


use ast\Node;

interface ConstantScope
{
    public function addToScope(string $name, $value): bool;
    public function existsInScope(string $name): bool;
    public function getFromScope(string $name);
}