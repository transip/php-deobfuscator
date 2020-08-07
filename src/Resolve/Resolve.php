<?php

namespace Transip\Resolve;


use const ast\AST_CONST;
use const ast\AST_EMPTY;
use const ast\flags\BINARY_BITWISE_XOR;
use const ast\flags\BINARY_BOOL_OR;
use const ast\flags\BINARY_CONCAT;
use const ast\flags\BINARY_IS_EQUAL;
use ast\Node;
use AstReverter\AstReverter;
use Transip\Dumper\StringDumper;
use Transip\Scope\ArrayConstantScope;
use Transip\Scope\ArrayFunctionScope;
use Transip\Scope\ArrayVariableScope;
use Transip\Scope\ConstantScope;
use Transip\Scope\FunctionScope;
use Transip\Scope\VariableScope;
use const ast\AST_ARG_LIST;
use const ast\AST_ARRAY;
use const ast\AST_ARRAY_ELEM;
use const ast\AST_ASSIGN;
use const ast\AST_ASSIGN_OP;
use const ast\AST_BINARY_OP;
use const ast\AST_CALL;
use const ast\AST_CONDITIONAL;
use const ast\AST_DIM;
use const ast\AST_ECHO;
use const ast\AST_EXPR_LIST;
use const ast\AST_FOR;
use const ast\AST_FOREACH;
use const ast\AST_FUNC_DECL;
use const ast\AST_GLOBAL;
use const ast\AST_IF;
use const ast\AST_IF_ELEM;
use const ast\AST_INCLUDE_OR_EVAL;
use const ast\AST_ISSET;
use const ast\AST_MAGIC_CONST;
use const ast\AST_NAME;
use const ast\AST_RETURN;
use const ast\AST_STMT_LIST;
use const ast\AST_UNARY_OP;
use const ast\AST_VAR;

class Resolve
{
    /**
     * Passed to \ast\parse_code as the $version parameter
     *  this ensures backwards compatibility should the plugin update.
     */
    const AST_VERSION = 50;

    /**
     * These functions are OK to execute.
     * In the general case, they are executed using call_user_func_array.
     * Special cases are file_get_contents and reset:
     *  - file_get_contents always returns this class' fileContents attribute.
     *  - reset first assigns it's argument to a variable, because it won't work otherwise.
     */
    const SAFE_FUNCTIONS = [
        'chr',
        'ord',
        'gzinflate',
        'base64_decode',
        'substr',
        'sha1',
        'preg_replace',
        // NOTE: As this contained the /e flag in php < 7.0, do not execute this extracter on lower PHP versions unless you want to get owned by malware
        'reset',
        'strcmp',
        'str_rot13',
        'str_pad',
        'explode',
        'file_get_contents',
        'strrev',
        'intval',
        'gzuncompress',
        'defined',
        'define',
        'strlen',
        'file',
    ];

    /**
     * The names of variables that are OK to store inside of the variable map
     *  (normally only scalars or AST_ARRAYs go in there).
     */
    const STORE_VARIABLES = [
        '_POST',
        '_GLOBAL',
        '_GET',
        '_SERVER',
        '_COOKIE',
    ];

    // Enables fast lookups of the right function to call.
    //  It's not exactly pretty, but that's because I can't have functions
    //  as data.
    const RESOLVE_MAP = [
        AST_INCLUDE_OR_EVAL => [
            \ast\flags\EXEC_EVAL => 'resolveEval',
        ],
        AST_UNARY_OP => [
            \ast\flags\UNARY_SILENCE => 'extractRawExpr',
            0 => 'resolveUnaryOp',
        ],
        AST_ASSIGN => [
            0 => 'storeAssign'
        ],
        AST_ASSIGN_OP => [
            0 => 'storeAssignOp'
        ],
        AST_STMT_LIST => [
            0 => 'resolveStmtList'
        ],
        AST_EXPR_LIST => [
            0 => 'resolveStmtList' // This is just a list of expressions, just like STMT_LIST - but with EXPR's
        ],
        AST_ARG_LIST => [
            0 => 'resolveStmtList'
        ],
        AST_CALL => [
            0 => 'resolveCall'
        ],
        AST_CONST => [
            0 => 'resolveConst'
        ],
        AST_VAR => [
            0 => 'resolveVar'
        ],
        AST_CONDITIONAL => [
            0 => 'resolveConditional'
        ],
        AST_MAGIC_CONST => [
            \ast\flags\MAGIC_FILE => 'resolveMagicFile'
        ],
        AST_BINARY_OP => [
            0 => 'resolveBinaryOp'
        ],
        AST_DIM => [
            0 => 'resolveDim'
        ],
        AST_NAME => [
            0 => 'resolveName'
        ],
        AST_ARRAY => [
            0 => 'resolveArray'
        ],
        AST_IF => [
            0 => 'resolveIf' // This is just a list of AST_IF_ELEM items
        ],
        AST_IF_ELEM => [
            0 => 'resolveIf'
        ],
        AST_FUNC_DECL => [
            0 => 'resolveFuncDecl'
        ],
        AST_GLOBAL => [
            0 => 'resolveGlobal'
        ],
        AST_FOR => [
            0 => 'resolveFor'
        ],
        AST_RETURN => [
            0 => 'resolveReturn'
        ],
        AST_FOREACH => [
            0 => 'resolveForeach'
        ],
        AST_ECHO => [
            0 => 'resolveEcho'
        ],
        AST_ARRAY_ELEM => [
            0 => 'resolveArrayElem'
        ],
        AST_ISSET => [
            0 => 'resolveIsset'
        ],
        AST_EMPTY => [
            0 => 'resolveEmpty'
        ]
    ];

    /**
     * @var ArrayVariableScope
     */
    private $variableMap;

    /** @var FunctionScope */
    private $functionScope;

    /** @var ConstantScope */
    private $constantScope;

    /**
     * @var string The contents of the original file. Used as a return value for file_get_contents calls
     */
    private $fileContents;

    /** @var array */
    private $resolveMap;

    /** @var string[] */
    private $storeVariables;

    /** @var string[] */
    private $safeFunctions;

    /** @var int */
    private $astVersion;

    /**
     * This class is not very OO-like, and that is mostly because there is no way to make a map of partials
     *  that doesn't involve things getting even more out of hand than they've already gone.
     * If we ever do get a language (eg rust) that has proper functions as arguments, partials, functions as values, etc
     *  I'll be able to rewrite this in that, assuming I can get a good PHP AST parser going there.
     *
     * For now, there are a few functions (the ones that use $astVersion, $resolveMap, $safeFunctions and $storeVariables)
     *  that use this class' context but that actually have the relevant variable as an input variable. With a partial,
     *  that argument would be prefilled. The function in question would then be spliced back into the $resolveMap,
     *  except now we would be able to call it with only a Node as an argument.
     * The $resolveMap understandably would then be partialed onto the resolve function, after which that function can be
     *  returned as the new de-facto entrypoint of parsing.
     *
     * All of the functions would in fact return both the VariableScope and the output (whatever that is - some Monad
     *  that works for multiple types, most likely). The VariableScope would then bubble up and down the chain, mutating
     *  as required. In the interest of speed, I wouldn't change it to be immutable (ie copy-on-write).
     *
     * @param string $fileContents
     * @param null|VariableScope $variableScope
     * @param null|FunctionScope $functionScope
     * @param null|ConstantScope $constantScope
     * @param array|null $resolveMap
     * @param array|null $safeFunctions
     * @param array|null $storeVariables
     * @param int|null $astVersion
     */
    public function __construct(
        string $fileContents,
        ?VariableScope $variableScope = null,
        ?FunctionScope $functionScope = null,
        ?ConstantScope $constantScope = null,
        ?array $resolveMap = null,
        ?array $safeFunctions = null,
        ?array $storeVariables = null,
        ?int $astVersion = null
    ) {
        if ($variableScope === null) {
            $variableScope = new ArrayVariableScope();
        }

        if ($functionScope === null) {
            $functionScope = new ArrayFunctionScope();
        }

        if ($constantScope === null) {
            $constantScope = new ArrayConstantScope();
        }

        if ($resolveMap === null) {
            $resolveMap = static::RESOLVE_MAP;
        }

        if ($safeFunctions === null) {
            $safeFunctions = static::SAFE_FUNCTIONS;
        }

        if ($storeVariables === null) {
            $storeVariables = static::STORE_VARIABLES;
        }

        if ($astVersion === null) {
            $astVersion = static::AST_VERSION;
        }

        $this->variableMap = $variableScope;
        $this->functionScope = $functionScope;
        $this->constantScope = $constantScope;
        $this->fileContents = $fileContents;
        $this->resolveMap = $resolveMap;
        $this->safeFunctions = $safeFunctions;
        $this->storeVariables = $storeVariables;
        $this->astVersion = $astVersion;
    }

    /**
     * Takes in a Node or something else. If something else, immediately returns it.
     *  If it is a node, this function attempts to find the relevant processing function
     *  in the resolveMap. If none can be found, returns the Node. Otherwise returns the
     *  output of the mapped function.
     *
     * @param mixed $node
     * @return mixed
     */
    public function resolve($node)
    {
        if (!$node instanceof Node) {
            return $node;
        }

        $functionToCall = $this->resolveMap[$node->kind][$node->flags] ??
            $this->resolveMap[$node->kind][0] ??
            null;

        if ($functionToCall === null) {
            return $node;
        }

        return $this->$functionToCall($node);
    }

    /**
     * Does what resolve does, but tries to turn the resulting Node into a scalar if it
     *  is an AST_RETURN.
     *
     * @param Node $node
     * @return Node|mixed
     */
    private function resolveToReturn(Node $node)
    {
        $functionToCall = $this->resolveMap[$node->kind][$node->flags] ??
            $this->resolveMap[$node->kind][0] ??
            null;

        if ($functionToCall === null) {
            return $node;
        }

        $result = $this->$functionToCall($node);

        if ($result->kind === AST_STMT_LIST) {
            $result = $this->extractReturnFromFunctionStmtList($result);
        }

        if ($result->kind === AST_RETURN) {
            return $result;
        }

        return $node;
    }

    /**
     * Takes in an AST_STMT_LIST and yields the first child that is a return statement.
     * If there is none, returns the stmt list
     *
     * @param Node $node
     * @return Node
     */
    private function extractReturnFromFunctionStmtList(Node $node)
    {
        $passthroughKinds = [
            AST_ASSIGN,
            AST_STMT_LIST,
        ];

        foreach($node->children as $child) {
            if ($child->kind === AST_STMT_LIST) {
                $child = $this->extractReturnFromFunctionStmtList($child);
            }

            if ($child->kind === AST_RETURN) {
                return $child;
            }

            if (in_array($child->kind, $passthroughKinds, true)) {
                continue;
            }

            return $node;
        }

        return $node;
    }


    /**
     * Takes in a node that is of kind AST_EVAL, prepends <?php to it's expression
     *  and returns the output of \ast\parse_code
     *
     * @param Node $node
     * @return Node
     */
    private function resolveEval(Node $node): Node
    {
        $expression = $this->resolve($node->children['expr']);

        if (!$this->isScalar($expression)) {
            return $node;
        }

        return \ast\parse_code('<?php ' . $expression, $version = $this->astVersion);
    }

    /**
     * Takes in a node that has an expr attribute and returns it's content.
     * This is used for unwrapping AST_UNARY_OP with the UNARY_SILENCE flag
     *
     * @param Node $node
     * @return Node
     */
    private function extractRawExpr(Node $node): Node
    {
        return $this->resolve($node->children['expr']);
    }

    /**
     * Takes in an AST_ASSIGN node and stores the value of it inside of the variableMap
     *  with the variable as a key.
     * If the variable cannot be converted to a string key, this does nothing.
     * If the value to be stored is not a scalar, an array or a variable that is in STORE_VARIABLES, this does nothing.
     *
     * @param Node $node
     * @return Node
     */
    private function storeAssign(Node $node): Node
    {
        $variable = $node->children['var'];

        if ($variable->kind === AST_VAR) {
            $variable->children['name'] = $this->resolve($variable->children['name']);
        } elseif ($variable->kind === AST_DIM) {
            $variable->children['dim'] = $this->resolve($variable->children['dim']);
        }

        if (!$this->variableMap->canKeyBeStored($variable)) {
            return $node;
        }

        $expression = $node->children['expr'];
        if ($node->children['expr']->children['name'] !== 'GLOBALS') {
            $expression = $this->resolve($node->children['expr']);
            $node->children['expr'] = $expression;
        }
        
        $node->children['var'] = $variable;

        if (!$this->isScalarOrArray($this->toScalar($expression)) &&
            !($expression instanceof Node &&
                $expression->kind === AST_VAR &&
                is_string($expression->children['name']) &&
                in_array($expression->children['name'], $this->storeVariables))
        ) {
            return $node;
        }

        $this->variableMap->addToScope($variable, $expression);

        return $node;
    }

    /**
     * This takes in an AST_ASSIGN_OP Node and performs the operation on the variable stored in the variableMap.
     * Does nothing if there is no variable with that name stored or if the operation of this AST_ASSIGN_OP is
     *  not supported.
     *
     * @param Node $node
     * @return Node
     */
    private function storeAssignOp(Node $node): Node
    {
        $variable = $node->children['var'];

        if ($variable->kind === AST_VAR) {
            $variable->children['name'] = $this->resolve($variable->children['name']);
        } elseif ($variable->kind === AST_DIM) {
            $variable->children['dim'] = $this->resolve($variable->children['dim']);
        }

        if (!$this->variableMap->canKeyBeStored($variable)) {
            return $node;
        }

        if (!$this->variableMap->existsInScope($variable)) {
            return $node;
        }

        $expression = $node->children['expr'];
        $expression = $this->resolve($expression);

        if (!$this->isScalar($expression)) {
            return $node;
        }

        $storedValue = $this->variableMap->getFromScope($variable);

        switch ($node->flags) {
            case \ast\flags\BINARY_CONCAT:
                $storedValue .= $expression;
                break;
            case \ast\flags\BINARY_ADD:
                $storedValue += $expression;
                break;
            case \ast\flags\BINARY_SUB:
                $storedValue -= $expression;
                break;
            default:
                return $node;
        }

        $this->variableMap->addToScope($variable, $storedValue);

        return $node;
    }

    /**
     * Takes in an AST_STMT_LIST and resolves all of it's children.
     * If the result of resolving it is a different node, the original node is replaced.
     * If the result of resolving it is an array, the original node is removed and the array is spliced in place of it.
     *
     * @param Node $node
     * @return Node
     */
    private function resolveStmtList(Node $node): Node
    {
        $childrenCount = count($node->children);

        for ($i = 0; $i < $childrenCount; $i++) {
            $child = $node->children[$i];
            $newChild = $this->resolve($child);

            if (is_array($newChild)) {
                array_splice($node->children, $i, 1, $newChild);
                $i--;
                continue;
            } elseif ($newChild !== $child) {
                $node->children[$i] = $newChild;
                $i--;
                continue;
            }
        }

        return $node;
    }

    /**
     * Takes in an AST_CALL. If it can be evaluated, returns the evaluation result.
     * Otherwise, returns the original node (with it's arguments and name resolved as far as possible).
     *
     * @param Node $node
     * @return mixed
     */
    private function resolveCall(Node $node)
    {
        // Attempt to resolve every one of the function's arguments
        foreach ($node->children['args']->children as $i => $argument) {
            $node->children['args']->children[$i] = $this->resolve($argument);
        }

        $functionName = $this->resolve($node->children['expr']);

        // If the functionname cannot be resolved to a scalar (ie a string),
        //  sets the node's function name field to the resolved value and
        //  return.
        if (!$this->isScalar($functionName)) {
            $node->children['expr'] = $functionName;
            return $node;
        }

        // If the functionName could be resolved, replace the original functionname
        //  with the derived value.
        $nameElement = new Node(
            AST_NAME,
            \ast\flags\NAME_NOT_FQ,
            [
                'name' => $functionName,
            ]);

        $node->children['expr'] = $nameElement;

        // safeFunctions contains a list of functions that should be safe for partial execution
        if (in_array($functionName, $this->safeFunctions)) {
            return $this->resolveSafeFunction($node);
        }

        if ($this->functionScope->existsInScope($functionName)) {
            return $this->resolveDefinedFunction($node);
        }

        return $node;
    }

    private function resolveConst(Node $node)
    {
        $name = $this->resolve($node->children['name']);

        if (!$this->constantScope->existsInScope($name)) {
            $node->children['name'] = $name;
            return $node;
        }

        return $this->constantScope->getFromScope($name);
    }

    /**
     * @param Node $node
     * @return Node|mixed|string
     */
    private function resolveSafeFunction(Node $node)
    {
        $functionName = $node->children['expr']->children['name'];

        // If any of the arguments is not a scalar or an array, return the input node
        $arguments = $node->children['args']->children;
        foreach ($arguments as $i => $argument) {
            $arguments[$i] = $this->toScalar($argument);

            if (!$this->isScalarOrArray($arguments[$i])) {
                return $node;
            }
        }

        // A shortcircuit special case for file_get_contents.
        //  If we got here, the inputs were scalar. As there
        //  is precisely no context to evaluate what the content
        //  of that file should be, return the original file's source.
        // This is a heuristic function - as far as I've seen it's
        //  behaviour that matches the expected outcome in most
        //  cases.
        if ($functionName === 'file_get_contents') {
            return $this->fileContents;
        }

        // Reset only accepts a variable as it's input, but all of the
        //  arguments are in fact scalars. This fixes that.
        if ($functionName === 'reset') {
            if (count($arguments) < 1) {
                return $node;
            }

            $array = $arguments[0];

            return reset($array);
        }

        if ($functionName === 'defined') {
            return $this->boolToNode($this->constantScope->existsInScope($arguments[0]));
        }

        if ($functionName === 'define') {
            return $this->boolToNode($this->constantScope->addToScope($arguments[0], $arguments[1]));
        }

        if ($functionName === 'file') {
            $fileArray = explode("\n", $this->fileContents);
            $result = array_map(function($item) { return $item . "\n"; }, $fileArray);
        } else {
            $result = call_user_func_array($functionName, $arguments);
        }

        // If the result is an array it needs to be converted
        //  back into something that can be in the tree.
        // Every other acceptable type (scalars, literals, etc)
        //  can directly exist in the AST with no issues.
        if (!is_array($result)) {
            return $result;
        } else {

            $elements = [];

            foreach ($result as $key => $value) {
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
                \ast\flags\ARRAY_SYNTAX_SHORT,
                $elements
            );
        }
    }

    private function boolToNode(bool $boolean) : Node
    {
        $booleanValue = $boolean ? 'true' : 'false';

        return new Node(AST_CONST, null, ['name' => $booleanValue]);
    }

    /**
     * Takes in the Node calling a function already defined somewhere.
     *  Enters a deeper scope (isolating defined / altered variables)
     *  Sets input parameters in said scope
     *  Resolves statements of function scope until a return statement pops out
     *
     * @param Node $node
     * @return Node
     */
    private function resolveDefinedFunction(Node $node)
    {
        $functionName = $node->children['expr']->children['name'];

        if (!$this->functionScope->existsInScope($functionName)) {
            return $node;
        }

        // Enter a function-specific scope
        $this->variableMap->stepIntoScope();

        $functionNode = $this->functionScope->getFromScope($functionName);

        // This is the body of the function
        $stmts = $this->functionScope->getClonedStmtsFromScope($functionName, $this->astVersion);

        $parameters = $functionNode->children['params']->children;

        foreach($parameters as $i => $parameter) {
            // If there was a parameter passed, use that. Otherwise, use the default
            $inputParameter = isset($node->children['args']->children[$i]) ?
                $node->children['args']->children[$i] :
                $parameter->children['default'];

            // Create the AST_VAR node to use as the key
            $key = new Node(
                AST_VAR,
                null,
                ['name' => $parameter->children['name']]
            );

            // Add the input variables of the function to this scope
            $this->variableMap->addToScope($key, $inputParameter);
        }

        $result = $this->resolveToReturn($stmts);

        $this->variableMap->stepOutOfScope();

        if ($result->kind === AST_RETURN) {
            return $result->children['expr'];
        }

        return $node;
    }

    /**
     * Takes in a node with a name and returns the Node's name, resolved as far as it'll go.
     *
     * @param Node $node
     * @return mixed
     */
    private function resolveName(Node $node)
    {
        return $this->resolve($node->children['name']);
    }

    /**
     * Takes in an AST_VAR and returns the value that is stored for it inside of the variableMap
     *  if there is any.
     *
     * @param Node $node
     * @return mixed
     */
    private function resolveVar(Node $node)
    {
        $node->children['name'] = $this->resolveName($node);

        if (!$this->variableMap->existsInScope($node)) {
            return $node;
        }

        $result = $this->variableMap->getFromScope($node);

        return $result;
    }

    /**
     * Takes an AST_CONDITIONAL. If it's condition is a scalar, returns the AST_CONDITIONAL's true or false child,
     *  depending on whether or not it's condition is truthy or falsy.
     *
     * @param Node $node
     * @return Node|mixed
     */
    private function resolveConditional(Node $node)
    {
        $condition = $this->resolve($node->children['cond']);

        if (!$this->isScalar($condition)) {
            return $node;
        }

        $true = $this->resolve($node->children['true']);

        $false = $this->resolve($node->children['false']);

        return $condition ? $true : $false;
    }

    /**
     * Takes in an AST_BINARY_OP and returns it's result, if it can be resolved.
     *
     * @param Node $node
     * @return mixed
     */
    private function resolveBinaryOp(Node $node)
    {
        $left = $this->resolve($node->children['left']);
        $node->children['left'] = $left;
        $right = $this->resolve($node->children['right']);
        $node->children['right'] = $right;

        if (!$this->isScalar($left)) {
            return $node;
        }

        if (!$this->isScalar($right)) {
            return $node;
        }

        if ($node->flags === BINARY_CONCAT) {
            return $left . $right;
        } elseif ($node->flags === BINARY_IS_EQUAL) {
            return $left == $right;
        } elseif ($node->flags === BINARY_BITWISE_XOR) {
            return $left ^ $right;
        } elseif ($node->flags === BINARY_BOOL_OR) {
            return $left || $right;
        } else {
            return $node;
        }
    }

    /**
     * Takes in an AST_DIM and returns it's actual value. If it cannot be resolved to a scalar expression with a scalar
     *  dimension, returns whatever it could resolve.
     *
     * @param Node $node
     * @return mixed
     */
    private function resolveDim(Node $node)
    {
        $expression = $this->resolve($node->children['expr']);

        // Otherwise we might resolve $asd['kaas'][0] to what we know of $asd['kaas'] without actually
        //  being able to compute all the way, which is kind of confusing
        if ($expression instanceof Node) {
            $node->children['expr'] = $expression;
        }

        $dimension = $this->resolve($node->children['dim']);
        $node->children['dim'] = $dimension;

        if ($this->variableMap->existsInScope($node)) {
            return $this->variableMap->getFromScope($node);
        }

        if (!$this->isScalar($dimension)) {
            return $node;
        }

        $expression = $this->toScalar($expression);
        if (!$this->isScalarOrArray($expression)) {
            return $node;
        }

        if (!isset($expression[$dimension])) {
            return $node;
        }

        return $expression[$dimension];
    }

    /**
     * @param Node $node of kind AST_ARRAY
     * @return Node of kind AST_ARRAY, with resolved children
     */
    private function resolveArray(Node $node): Node
    {
        foreach ($node->children as $i => $element) {
            $node->children[$i] = $this->resolve($element);
        }

        return $node;
    }

    /**
     * @param Node $node of kind AST_MAGIC_CONST and flag \ast\flags\MAGIC_FILE
     * @return string
     */
    private function resolveMagicFile(Node $node): string
    {
        return 'CURRENTFILE';
    }

    /**
     * @param Node $node of kind AST_IF
     * @return Node the input node, with it's condition and statements resolved
     */
    private function resolveIf(Node $node): Node
    {
        if ($node->kind === AST_IF) {
            foreach($node->children as $i => $ifElement) {
                $result = $this->resolve($ifElement);

                if ($result->kind !== AST_IF_ELEM) {
                    return $result;
                }

                $node->children[$i] = $result;
            }

            return $node;
        }

        $condition = $this->resolve($node->children['cond']);
        $statements = $this->resolve($node->children['stmts']);

        if (!$this->isScalar($condition)) {
            $node->children['cond'] = $condition;
            $node->children['stmts'] = $statements;
            return $node;
        }

        if ($condition == false) {
            return new Node(AST_STMT_LIST, null, []);
        }

        return $statements;
    }

    /**
     * @param Node $node
     * @return Node|mixed
     */
    private function resolveEmpty(Node $node)
    {
        $expr = $this->resolve($node->children['expr']);

        if (!$this->isScalar($expr)) {
            $node->children['expr'] = $expr;
            return $node;
        }

        return empty($expr);
    }

    /**
     * Resolves input node, stepping into a deeper scope before doing so. When done, steps out of the deeper scope.
     * This ensures that the variableMap keeps proper scoping rules in mind.
     *
     * @param Node $node of kind AST_FUNC_DECL
     * @return Node the input node, with it's statements resolved
     */
    private function resolveFuncDecl(Node $node): Node
    {
        $name = $node->children['name'];

        $this->functionScope->addToScope($name, $node);

        return $node;
    }

    /**
     * Instructs the variableMap to copy the input node's name into the global context.
     *
     * @param Node $node of kind AST_GLOBAL
     * @return Node the input node
     */
    private function resolveGlobal(Node $node): Node
    {
        $variable = $node->children['var'];

        $name = $this->resolve($variable->children['name']);

        $variable->children['name'] = $name;

        $this->variableMap->makeGlobal($variable);

        return $node;
    }

    /**
     * Resolved an AST_FOR node. Also stores it's initialization variable in the variableMap.
     *
     * @param Node $node of kind AST_FOR
     * @return Node the input node, with it's condition and statements resolved
     */
    private function resolveFor(Node $node): Node
    {
        $initialization = $node->children['init'];
        $cond = $node->children['cond'];
        $loop = $node->children['loop'];
        $statements = $node->children['stmts'];

        $this->resolve($initialization);
        $node->children['cond'] = $this->resolve($cond);
        $node->children['stmts'] = $this->resolve($statements);

        return $node;
    }

    /**
     * @param Node $node of kind AST_RETURN
     * @return Node the input node, with it's expression resolved
     */
    private function resolveReturn(Node $node): Node
    {
        $expression = $this->resolve($node->children['expr']);

        $node->children['expr'] = $expression;

        return $node;
    }

    /**
     * @param Node $node of kind AST_FOREACH
     * @return Node input node, with it's expression and statements resolved
     */
    private function resolveForeach(Node $node): Node
    {
        $expression = $this->resolve($node->children['expr']);
        $node->children['expr'] = $expression;

        $value = $node->children['value'];
        $key = $node->children['key'];

        $statements = $this->resolve($node->children['stmts']);
        $node->children['stmts'] = $statements;

        return $node;
    }

    /**
     * @param Node $node of kind AST_ECHO
     * @return Node input node, with it's expression resolved
     */
    private function resolveEcho(Node $node): Node
    {
        $node->children['expr'] = $this->resolve($node->children['expr']);

        return $node;
    }

    /**
     * @param Node $node of kind AST_ARRAY_ELEM
     * @return Node input node, with it's key and value resolved
     */
    private function resolveArrayElem(Node $node): Node
    {
        $node->children['key'] = $this->resolve($node->children['key']);
        $node->children['value'] = $this->resolve($node->children['value']);

        return $node;
    }

    /**
     * @param Node $node of type AST_ISSET
     * @return Node|bool true if the variable being evaluated is a scalar or an array, the input node otherwise
     */
    private function resolveIsset(Node $node)
    {
        $node->children['var'] = $this->resolve($node->children['var']);

        $variable = $this->toScalar($node->children['var']);

        if ($this->isScalarOrArray($variable)) {
            return true;
        }

        return $node;
    }

    /**
     * @param Node $node of type AST_UNARY_OP
     * @return mixed
     */
    private function resolveUnaryOp(Node $node)
    {
        $expr = $this->resolve($node->children['expr']);

        if ($expr instanceof Node) {
            return $node;
        }

        switch($node->flags) {
            case \ast\flags\UNARY_BITWISE_NOT:
                return ~$expr;
                break;
            default:
                return $node;
        }
    }

    /**
     * Takes the input value, and returns it if it is not an instance of Node.
     * If it is, but it is not an AST_ARRAY, returns the input.
     * If it is an AST_ARRAY, attempts to resolve every key/value pair. If every pair is scalar, returns an array.
     *  If not every pair can be resolved, returns the input value.
     *
     * @param mixed $value
     * @return mixed
     */
    private function toScalar($value)
    {
        if (!$value instanceof Node) {
            return $value;
        }

        if ($value->kind !== AST_ARRAY) {
            return $value;
        }

        $elements = [];
        foreach ($value->children as $item) {
            $key = $this->resolve($item->children['key']);
            $itemValue = $this->resolve($item->children['value']);

            if ($this->isScalar($key) && $this->isScalar($itemValue)) {
                $elements[$key] = $itemValue;
            } else {
                return $value;
            }
        }

        return $elements;
    }

    /**
     * @param mixed $value
     * @return bool if $value is a number, a string or a bool
     */
    private function isScalar($value): bool
    {
        return is_string($value) || is_numeric($value) || is_bool($value);
    }

    /**
     * Combines $this->isScalar with is_array
     *
     * @param $value
     * @return bool
     */
    private function isScalarOrArray($value): bool
    {
        return is_array($value) || $this->isScalar($value);
    }
}
