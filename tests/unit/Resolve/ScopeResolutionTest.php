<?php

namespace Transip\Resolve;

use const ast\AST_VAR;
use ast\Node;
use function ast\parse_code;
use PHPUnit\Framework\TestCase;
use Transip\Reducer\PhpAstSourceReducer;
use Transip\Scope\ArrayVariableScope;

class ScopeResolutionTest extends TestCase
{
    /** @var ArrayVariableScope */
    private $variableScope;

    public function setUp()
    {
        $this->variableScope = new ArrayVariableScope();
    }

    public function testShouldStoreInGlobalScopeAfterGlobalKeyword()
    {
        $source = <<<'EOF'
<?php

$kaas = 'a';

function taart() {
    $kaas = 'c';

    global $kaas;
    
    $kaas = 'b';
}
EOF;

        $variable = new Node(AST_VAR, null, ['name' => 'kaas']);


        $value = 'a';

        $root = parse_code($source, $version = PhpAstSourceReducer::AST_VERSION);
        $resolve = new Resolve($source, $this->variableScope);
        $resolve->resolve($root);

        // If the simple function is not executed, the global value should still be the old version
        $this->assertEquals($value, $this->variableScope->getFromScope($variable));
        $source .= <<<'EOF'

taart();
EOF;

        $value = 'b';
        $root = parse_code($source, $version = PhpAstSourceReducer::AST_VERSION);
        $this->setUp();
        $resolve = new Resolve($source, $this->variableScope);
        $resolve->resolve($root);

        // If it is, the global value should be the new version
        $this->assertEquals($value, $this->variableScope->getFromScope($variable));
    }
}
