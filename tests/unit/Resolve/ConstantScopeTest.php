<?php

namespace Transip\Resolve;

use PHPUnit\Framework\TestCase;
use Transip\Scope\ArrayConstantScope;
use Transip\Scope\ConstantScope;

class ConstantScopeTest extends TestCase
{
    /** @var ConstantScope */
    private $constantScope;

    public function setUp()
    {
        $this->constantScope = new ArrayConstantScope();
    }

    public function constantScopeProvider()
    {
        $constantDefine = <<<'EOF'
<?php

define('kaas', 'a');
EOF;

        $constantDefinedInFunction = <<<'EOF'
<?php

function test() {
    define('kaas', 'b');
}

test();
EOF;

        $constantDefinedTwice = <<<'EOF'
<?php

define('kaas', 'a');

function test() {
    if (defined('kaas')) {
        define('kaas', 'b');
    }
}

test();
EOF;

        $constantDefined = <<<'EOF'
<?php

if (!defined('kaas')) {
    define('kaas', 'a');
}
EOF;


        return [
            [ $constantDefine, 'kaas','a' ],
            [ $constantDefinedInFunction, 'kaas', 'b' ],
            [ $constantDefinedTwice, 'kaas', 'b' ],
            [ $constantDefined, 'kaas', 'a' ],
        ];
    }

    /**
     * @dataProvider constantScopeProvider
     */
    public function testConstantScope($code, $constant, $value)
    {
        $resolve = new Resolve($code, null, null, $this->constantScope);

        $node = \ast\parse_code($code, Resolve::AST_VERSION);

        $resolve->resolve($node);

        $actual = $this->constantScope->getFromScope($constant);

        $this->assertEquals($value, $actual);
    }
}
