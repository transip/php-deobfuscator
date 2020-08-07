<?php

namespace Transip\Resolve;

use const ast\AST_BINARY_OP;
use const ast\AST_CONDITIONAL;
use const ast\AST_DIM;
use ast\Node;
use PHPUnit\Framework\TestCase;
use Transip\Dumper\StringDumper;
use Transip\Scope\ArrayVariableScope;
use Transip\Scope\VariableScope;
use const ast\AST_ARG_LIST;
use const ast\AST_ARRAY;
use const ast\AST_ARRAY_ELEM;
use const ast\AST_ASSIGN;
use const ast\AST_ASSIGN_OP;
use const ast\AST_CALL;
use const ast\AST_ECHO;
use const ast\AST_INCLUDE_OR_EVAL;
use const ast\AST_NAME;
use const ast\AST_STMT_LIST;
use const ast\AST_UNARY_OP;
use const ast\AST_VAR;
use const ast\flags\ARRAY_SYNTAX_SHORT;
use const ast\flags\BINARY_CONCAT;
use const ast\flags\EXEC_EVAL;
use const ast\flags\NAME_NOT_FQ;
use const ast\flags\UNARY_SILENCE;

class ResolveTest extends TestCase
{
    /** @var VariableScope */
    private $variableScope;

    /** @var Resolve */
    private $resolve;

    public function setUp()
    {
        $this->variableScope = new ArrayVariableScope();
        $this->resolve = new Resolve(
            'originalFile',
            $this->variableScope
        );
    }

    public function testResolveScalarReturnsScalar()
    {
        $scalar = 'testString';

        $resolved = $this->resolve->resolve($scalar);

        $this->assertEquals($scalar, $resolved);
    }

    public function testResolveEval()
    {
        $evalString = 'echo "kaas";';

        $evalNode = new Node(
            AST_INCLUDE_OR_EVAL,
            EXEC_EVAL,
            [
                'expr' => $evalString
            ]
        );

        $expected = new Node (
            AST_STMT_LIST,
            0,
            [
                new Node(
                    AST_ECHO,
                    0,
                    [
                        'expr' => 'kaas'
                    ]
                )
            ]
        );

        $actual = $this->resolve->resolve($evalNode);

        $this->assertTrue($this->nodesEqual($expected, $actual), $this->getErrorMessage('eval', $expected, $actual));
    }

    public function testResolveUnarySilence()
    {
        $silenceExpr = new Node(
            AST_CALL,
            0,
            [
                'expr' => new Node(AST_NAME, 0, ['name' => 'nocando']),
                'args' => new Node(AST_ARG_LIST, 0, [])
            ]
        );

        $silenceNode = new Node(
            AST_UNARY_OP,
            UNARY_SILENCE,
            [
                'expr' => $silenceExpr
            ]
        );

        $actual = $this->resolve->resolve($silenceNode);

        $expected = $silenceExpr;

        $this->assertTrue($this->nodesEqual($actual, $expected),
            $this->getErrorMessage('unary silence', $expected, $actual));
    }

    public function storeAssignProvider()
    {
        return [
            [new Node(AST_VAR, 0, ['name' => 'test']), 'test', 'test'],
            [
                new Node(AST_VAR, 0, [
                    'name' => new Node(
                        AST_CALL,
                        0, [
                        'expr' => new Node(AST_NAME, 0, ['name' => 'nocando']),
                        'args' => new Node(AST_ARG_LIST, 0, []),
                    ])
                ]),
                'test',
                null
            ],
            [
                new Node(AST_VAR, 0, ['name' => 'test']),
                new Node(AST_CALL, 0, [
                    'expr' => new Node(AST_NAME, 0, ['name' => 'nocando']),
                    'args' => new Node(AST_ARG_LIST, 0, [])
                ]),
                null,
            ]
        ];
    }

    /**
     * @param $key
     * @param $value
     * @param $storedValue
     *
     * @dataProvider storeAssignProvider
     */
    public function testStoreAssign($key, $value, $storedValue)
    {
        $assign = new Node(
            AST_ASSIGN,
            0,
            [
                'var' => $key,
                'expr' => $value,
            ]
        );

        $this->resolve->resolve($assign);

        $expected = $storedValue;

        $actual = null;
        if ($this->variableScope->existsInScope($key)) {
            $actual = $this->variableScope->getFromScope($key);
        }

        $this->assertTrue($this->nodesEqual($expected, $actual),
            $this->getErrorMessage('storeAssign', $expected, $actual));
    }

    public function testStoreAssignOp()
    {
        $variableNode = new Node(
            AST_VAR,
            0,
            ['name' => 'test']
        );

        $variableMap = new ArrayVariableScope([
            '$test' => 'tes',
        ]);

        $resolve = new Resolve('', $variableMap);

        $assignOp = new Node(
            AST_ASSIGN_OP,
            BINARY_CONCAT,
            [
                'var' => $variableNode,
                'expr' => 't',
            ]
        );

        $expected = 'test';

        $resolve->resolve($assignOp);

        $actual = $variableMap->getFromScope($variableNode);

        $this->assertTrue($this->nodesEqual($expected, $actual),
            $this->getErrorMessage('storeAssignOp', $expected, $actual));
    }

    public function resolveCallProvider()
    {
        // Turns a string name into a non fully qualified AST_NAME Node
        $simpleNameElement = function (string $functionName): Node {
            return new Node(
                AST_NAME,
                NAME_NOT_FQ,
                ['name' => $functionName]
            );
        };

        // Takes an array and turns it into an AST_ARRAY
        $arrayToAst = function (array $array): Node {
            $elements = [];

            foreach ($array as $key => $value) {
                $elements[] = new Node(
                    AST_ARRAY_ELEM,
                    0,
                    [
                        'key' => $key,
                        'value' => $value,
                    ]
                );
            }

            return new Node(
                AST_ARRAY,
                ARRAY_SYNTAX_SHORT,
                $elements
            );
        };

        // Takes a functionname and an array of arguments, and turns that into an AST_CALL Node
        $astCall = function (string $functionName, array $arguments) use ($simpleNameElement, $arrayToAst): Node {
            foreach ($arguments as $i => $argument) {
                if (is_array($argument)) {
                    $arguments[$i] = $arrayToAst($argument);
                }
            }

            return new Node(
                AST_CALL,
                0,
                [
                    'expr' => $simpleNameElement($functionName),
                    'args' => new Node(
                        AST_ARG_LIST,
                        0,
                        $arguments
                    )
                ]
            );
        };

        return [
            [$astCall('file_get_contents', []), 'originalFile'],
            [$astCall('reset', [['a']]), 'a'],
            [$astCall('youcandoitnot', []), $astCall('youcandoitnot', [])],
            [$astCall('base64_decode', ['aGVuaw==']), 'henk'],
        ];
    }

    /**
     * @param $callNode
     * @param $expected
     *
     * @dataProvider resolveCallProvider
     */
    public function testResolveCall($callNode, $expected)
    {
        $actual = $this->resolve->resolve($callNode);

        $this->assertTrue($this->nodesEqual($expected, $actual),
            $this->getErrorMessage('resolveCall', $expected, $actual));
    }

    public function testResolveName()
    {
        $concatOperation = new Node(
            AST_BINARY_OP,
            BINARY_CONCAT,
            [
                'left' => 'te',
                'right' => 'st',
            ]
        );

        $nameNode = new Node(
            AST_NAME,
            0,
            [
                'name' => $concatOperation,
            ]
        );

        $expected = 'test';

        $actual = $this->resolve->resolve($nameNode);

        $this->assertTrue($this->nodesEqual($expected, $actual), $this->getErrorMessage('resolveName', $expected, $actual));
    }

    public function testResolveVar()
    {
        $variableNode = new Node(
            AST_VAR,
            0,
            ['name' => 'test']
        );

        $variableScope = new ArrayVariableScope([
            '$nameTest' => 'test',
            '$test' => 'henk',
        ]);

        $resolve = new Resolve('', $variableScope);

        $expected = 'henk';
        $actual = $resolve->resolve($variableNode);

        $this->assertTrue($this->nodesEqual($expected, $actual), $this->getErrorMessage('resolveVar with simple name', $expected, $actual));

        $variableNode = new Node(
            AST_VAR,
            0,
            ['name' => new Node(AST_VAR, 0, ['name' => 'nameTest'])]
        );

        $actual = $resolve->resolve($variableNode);

        $this->assertTrue($this->nodesEqual($expected, $actual), $this->getErrorMessage('resolveVar with variable name', $expected, $actual));
    }

    public function testResolveConditional()
    {
        $trueNode = new Node(
            AST_CALL,
            0,
            [
                'expr' => new Node(AST_NAME, NAME_NOT_FQ, ['name' => 'no_existimo_true']),
                'args' => new Node(AST_ARG_LIST, 0, []),
            ]
        );

        $falseNode = new Node(
            AST_CALL,
            0,
            [
                'expr' => new Node(AST_NAME, NAME_NOT_FQ, ['name' => 'no_existimo_false']),
                'args' => new Node(AST_ARG_LIST, 0, []),
            ]
        );

        $conditionalNode = new Node(
            AST_CONDITIONAL,
            0,
            [
                'cond' => true,
                'true' => $trueNode,
                'false' => $falseNode,
            ]
        );

        $actual = $this->resolve->resolve($conditionalNode);
        $this->assertTrue($this->nodesEqual($actual, $trueNode), $this->getErrorMessage('testConditional true', $trueNode, $actual));

        $conditionalNode->children['cond'] = false;
        $actual = $this->resolve->resolve($conditionalNode);
        $this->assertTrue($this->nodesEqual($actual, $falseNode), $this->getErrorMessage('testConditional false', $falseNode, $actual));
    }

    public function testResolveBinaryOp()
    {
        $binaryOpNode = new Node(
            AST_BINARY_OP,
            BINARY_CONCAT,
            [
                'left' => 'te',
                'right' => 'st'
            ]
        );

        $expected = 'test';
        $actual = $this->resolve->resolve($binaryOpNode);

        $this->assertTrue($this->nodesEqual($actual, $expected), $this->getErrorMessage('testResolveBinaryOp', $expected, $actual));
    }

    public function testResolveDim()
    {
        $variableScope = new ArrayVariableScope([
            '$test' => ['a']
        ]);

        $resolve = new Resolve('', $variableScope);

        $dim = new Node(
            AST_DIM,
            0,
            [
                'expr' => new Node(AST_VAR, 0, ['name' => 'test']),
                'dim' => 0,
            ]
        );

        $expected = 'a';

        $actual = $resolve->resolve($dim);

        $this->assertTrue($this->nodesEqual($expected, $actual), $this->getErrorMessage('testResolveDim', $expected, $actual));
    }

    public function testResolveArray()
    {
        $variableScope = new ArrayVariableScope([
            '$test' => 'a',
        ]);

        $resolve = new Resolve('', $variableScope);

        $array = new Node(
            AST_ARRAY,
            ARRAY_SYNTAX_SHORT,
            [
                new Node(AST_ARRAY_ELEM, 0, ['key' => 0, 'value' => 'b']),
                new Node(AST_ARRAY_ELEM, 0, ['key' => 1, 'value' => new Node(AST_VAR, 0, ['name' => 'test'])]),
            ]
        );

        $expected = new Node(
            AST_ARRAY,
            ARRAY_SYNTAX_SHORT,
            [
                new Node(AST_ARRAY_ELEM, 0, ['key' => 0, 'value' => 'b']),
                new Node(AST_ARRAY_ELEM, 0, ['key' => 1, 'value' => 'a']),
            ]
        );

        $actual = $resolve->resolve($array);

        $this->assertTrue($this->nodesEqual($actual, $expected), $this->getErrorMessage('testResolveArray', $expected, $actual));
    }

    private function getErrorMessage($operation, $expected, $actual)
    {
        return <<<EOF
Expected {$operation} result is not the same as actual result:
Expected:
{$this->dumpAst($expected)}

Actual:
{$this->dumpAst($actual)}
EOF;
    }

    /**
     * @param mixed $ast
     * @return string
     */
    private function dumpAst($ast): string
    {
        return StringDumper::ast_dump($ast);
    }

    /**
     * Takes in two items.
     * If they're not nodes, returns whether or not they are strictly equal (===).
     * If one of them is a node, returns false.
     * If they are both nodes, compares kind, flags and children for similarity.
     *  If those are all strictly equal, returns true.
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    private function nodesEqual($a, $b)
    {
        if (!$a instanceof Node &&
            !$b instanceof Node
        ) {
            return $a === $b;
        }

        if (!$a instanceof Node ||
            !$b instanceof Node
        ) {
            return false;
        }

        if ($a->kind !== $b->kind) {
            return false;
        }

        if ($a->flags !== $b->flags) {
            return false;
        }

        foreach ($a->children as $key => $child) {
            if (!isset($b->children[$key])) {
                return false;
            }

            if (!$this->nodesEqual($child, $b->children[$key])) {
                return false;
            }
        }

        return true;
    }

}
