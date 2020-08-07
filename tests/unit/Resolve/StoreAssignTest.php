<?php

namespace Transip\Resolve;

use const ast\AST_DIM;
use const ast\AST_VAR;
use ast\Node;
use function ast\parse_code;
use PHPUnit\Framework\TestCase;
use Transip\Command\PhpAstFileCommand;
use Transip\Reducer\PhpAstSourceReducer;
use Transip\Scope\ArrayVariableScope;
use Transip\Scope\VariableScope;

class StoreAssignTest extends TestCase
{
    /** @var VariableScope */
    private $variableScope;

    public function setUp()
    {
        $this->variableScope = new ArrayVariableScope();
    }

    public function storeScalarToVariableProvider()
    {
        return [
            [
                <<<'EOF'
<?php

$test = 'a';
EOF
                ,
                new Node(AST_VAR, null, ['name' => 'test']),
                'a'
            ],
            [
                <<<'EOF'
<?php

$test = 1;
EOF
                ,
                new Node(AST_VAR, null, ['name' => 'test']),
                1
            ],
        ];
    }

    /**
     * @param string $fileContent
     * @param Node $varNode
     * @param mixed $value
     */
    public function resolveSimpleStorageAndCheckVariableName($fileContent, $varNode, $value)
    {
        $resolve = new Resolve($fileContent, $this->variableScope);

        $root = parse_code($fileContent, $version = PhpAstSourceReducer::AST_VERSION);

        $ast = $resolve->resolve($root);

        $this->assertEquals($value, $this->variableScope->getFromScope($varNode));
    }

    /**
     * @dataProvider storeScalarToVariableProvider
     * @param string $fileContent
     * @param Node $name
     * @param mixed $value
     */
    public function testShouldStoreScalarToVariable($fileContent, $name, $value)
    {
        $this->resolveSimpleStorageAndCheckVariableName($fileContent, $name, $value);
    }


    public function storeAssignOpToVariableProvider()
    {
        return [
            [
                <<<'EOF'
<?php

$test = 1;

$test += 1;
EOF
                ,
                new Node(AST_VAR, null, ['name' => 'test']),
                2
            ],
            [
                <<<'EOF'
<?php

$test = 'a';

$test .= 'a';
EOF
                ,
                new Node(AST_VAR, null, ['name' => 'test']),
                'aa'
            ],
            [
                <<<'EOF'
<?php

$kaas = 'a';
$test[$kaas] = 1;

$test['a'] += 1;
EOF
                ,
                new Node(
                    AST_DIM,
                    null,
                    [
                        'expr' => new Node(AST_VAR, null, ['name' => 'test',]),
                        'dim' => 'a',
                    ]
                ),
                2
            ]
        ];
    }

    /**
     * @dataProvider storeAssignOpToVariableProvider
     *
     * @param string $fileContent
     * @param Node $name
     * @param mixed $value
     */
    public function testShouldStoreAssignOpToVariable($fileContent, $name, $value)
    {
        $this->resolveSimpleStorageAndCheckVariableName($fileContent, $name, $value);
    }
}