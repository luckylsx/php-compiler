<?php

# This file is generated, changes you make will be lost.
# Make your changes in /home/ircmaxell/Workspace/PHP-Compiler/PHP-Compiler/lib/JIT.pre instead.

// First, expand statements
/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPTypes\Type;
use PHPCompiler\JIT\Analyzer;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;

use PHPCompiler\Func as CoreFunc;
use PHPCompiler\JIT\Func as JITFunc;
use PHPCompiler\NativeType\NativeArray as NativeArray;

use PHPLLVM;

class JIT {

    private static int $functionNumber = 0;
    private static int $blockNumber = 0;

    public int $optimizationLevel = 3;


    private array $stringConstant = [];
    private array $intConstant = [];
    private array $builtIns = [];

    private array $queue = [];

    public Context $context;

    public function __construct(Context $context) {
        $this->context = $context;
    }

    public function compile(Block $block): PHPLLVM\Value {
        $return = $this->compileBlock($block);
        $this->runQueue();
        return $return;
    }

    public function compileFunc(CoreFunc $func): void {
        if ($func instanceof CoreFunc\PHP) {
            $this->compileBlock($func->block, $func->getName());
            $this->runQueue();
            return;
        } elseif ($func instanceof CoreFunc\JIT) {
            // No need to do anything, already compiled
            return;
        } elseif ($func instanceof CoreFunc\Internal) {
            $this->context->functions[strtolower($func->getName())] = $func->jit($this);
            return;
        }
        throw new \LogicException("Unknown func type encountered: " . get_class($func));
    }

    private function runQueue(): void {
        while (!empty($this->queue)) {
            $run = array_shift($this->queue);
            $this->compileBlockInternal($run[0], $run[1], ...$run[2]);
        }
    }

    private function compileBlock(Block $block, ?string $funcName = null): PHPLLVM\Value {
        if (!is_null($funcName)) {
            $internalName = $funcName;
        } else {
            $internalName = "internal_" . (++self::$functionNumber);
        }
        $args = [];
        $rawTypes = [];
        $argVars = [];
        if (!is_null($block->func)) {
            $callbackType = '';
            if ($block->func->returnType instanceof Op\Type\Literal) {
                switch ($block->func->returnType->name) {
                    case 'void':
                        $callbackType = 'void';
                        break;
                    case 'int':
                        $callbackType = 'long long';
                        break;
                    case 'string':
                        $callbackType = '__string__*';
                        break;
                    default:
                        throw new \LogicException("Non-void return types not supported yet");
                }
            } else {
                throw new \LogicException("Non-typed functions not implemented yet");
            }
            $returnType = $this->context->getTypeFromString($callbackType);
            $callbackType .= '(*)(';
            $callbackSep = '';
            foreach ($block->func->params as $idx => $param) {
                if (empty($param->result->usages)) {
                    // only compile for param
                    assert($param->declaredType instanceof Op\Type\Literal);
                    $rawType = Type::fromDecl($param->declaredType->name);
                } else {
                    $rawType = $param->result->type;
                }
                $type = $this->context->getTypeFromType($rawType);
                $callbackType .= $callbackSep . $this->context->getStringFromType($type);
                $callbackSep = ', ';
                $rawTypes[] = $rawType;
                $args[] = $type;
            }
            $callbackType .= ')';
        } else {
            $callbackType = 'void(*)()';
            $returnType = $this->context->getTypeFromString('void');
        }

        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType(
                $returnType,
                false,
                ...$args
            )
        );

        foreach ($args as $idx => $arg) {
            $argVars[] = new Variable($this->context, $rawTypes[$idx]->type, Variable::KIND_VALUE, $func->getParam($idx));
        }

        if (!is_null($funcName)) {
            $this->context->functions[strtolower($funcName)] = $func;
        }

        $this->queue[] = [$func, $block, $argVars];
        if ($callbackType === 'void(*)()') {
            $this->context->addExport($internalName, $callbackType, $block);
        }
        return $func;
    }
    
    private function compileBlockInternal(
        PHPLLVM\Value $func,
        Block $block,
        Variable ...$args
    ): PHPLLVM\BasicBlock {
        if ($this->context->scope->blockStorage->contains($block)) {
            return $this->context->scope->blockStorage[$block];
        }
        self::$blockNumber++;
        $origBasicBlock = $basicBlock = $func->appendBasicBlock('block_' . self::$blockNumber);
        $this->context->scope->blockStorage[$block] = $basicBlock;
        // Handle hoisted variables
        foreach ($block->orig->hoistedOperands as $operand) {
            $var = $this->context->makeVariableFromOp($func, $basicBlock, $block, $operand);
        }
        $builder = $this->context->builder;
        $builder->positionAtEnd($basicBlock);

        for ($i = 0, $length = count($block->opCodes); $i < $length; $i++) {
            $op = $block->opCodes[$i];
            switch ($op->type) {
                case OpCode::TYPE_ARG_RECV:
                    $this->assignOperand($block->getOperand($op->arg1), $args[$op->arg2]);
                    break;
                case OpCode::TYPE_ASSIGN:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg3));
                    $this->assignOperand($block->getOperand($op->arg2), $value);
                    $this->assignOperand($block->getOperand($op->arg1), $value);
                    break;  
                // case OpCode::TYPE_ARRAY_DIM_FETCH:
                //     $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                //     $dimOp = $block->getOperand($op->arg3);
                //     $dim = $this->context->getVariableFromOp($dimOp);
                //     if ($value->type & Variable::IS_NATIVE_ARRAY && $this->context->analyzer->needsBoundsCheck($value, $dimOp)) {
                //         // compile bounds check
                //         $builder->call(
                //             $this->context->lookupFunction('__nativearray__boundscheck'),
                //             $dim->value,
                //             $this->context->constantFromInteger($value->nextFreeElement)
                //         );
                //     }
                //     $this->assignOperand(
                //         $block->getOperand($op->arg1),
                //         $value->dimFetch($dim)
                //     );
                //     break;
                // case OpCode::TYPE_INIT_ARRAY:
                // case OpCode::TYPE_ADD_ARRAY_ELEMENT:
                //     $result = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                //     if ($result->type & Variable::IS_NATIVE_ARRAY) {
                //         if (is_null($op->arg3)) {
                //             $idx = $result->nextFreeElement;
                //         } else {
                //             // this is safe, since we only compile to native array if it's checked to be good
                //             $idx = $block->getOperand($op->arg3)->value;
                //         }
                //         $this->context->helper->assign(
                //             $gccBlock,
                //             \gcc_jit_context_new_array_access(
                //                 $this->context->context,
                //                 $this->context->location(),
                //                 $result->rvalue,
                //                 $this->context->constantFromInteger($idx, 'size_t')
                //             ),
                //             $this->context->getVariableFromOp($block->getOperand($op->arg2))->rvalue
                //         );
                //         $result->nextFreeElement = max($result->nextFreeElement, $idx + 1);
                //     } else {
                //         throw new \LogicException('Hash tables not implemented yet');
                //     }
                //     break;
                case OpCode::TYPE_CONCAT:
                    $result = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    $left = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $right = $this->context->getVariableFromOp($block->getOperand($op->arg3));
                    $this->context->type->string->concat($result, $left, $right);
                    break;
                case OpCode::TYPE_CAST_BOOL:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $this->assignOperand($block->getOperand($op->arg1), $value->castTo(Variable::TYPE_NATIVE_BOOL));
                    break;
                case OpCode::TYPE_ECHO:
                case OpCode::TYPE_PRINT:
                    $argOffset = $op->type === OpCode::TYPE_ECHO ? $op->arg1 : $op->arg2;
                    $arg = $this->context->getVariableFromOp($block->getOperand($argOffset));
                    $charType = $this->context->context->int8Type()->pointerType(0);
                    $argValue = $arg->value;
                    switch ($arg->type) {                            
                        case Variable::TYPE_STRING:            
                            $global___cfcd208495d565ef66e7dff9f98764da = $this->context->constantFromString("%.*s");
            // TODO: Cast here, so it works
            $local___cfcd208495d565ef66e7dff9f98764da = $this->context->builder->pointerCast($global___cfcd208495d565ef66e7dff9f98764da, $this->context->getTypeFromString('char*'));
            $fmt = $local___cfcd208495d565ef66e7dff9f98764da;
    $offset___cfcd208495d565ef66e7dff9f98764da = $this->context->structFieldMap[$argValue->typeOf()->getElementType()->getName()]['length'];
            $length = $this->context->builder->load(
                $this->context->builder->structGep($argValue, $offset___cfcd208495d565ef66e7dff9f98764da)
            );
    $offset___cfcd208495d565ef66e7dff9f98764da = $this->context->structFieldMap[$argValue->typeOf()->getElementType()->getName()]['value'];
            $value = $this->context->builder->load(
                $this->context->builder->structGep($argValue, $offset___cfcd208495d565ef66e7dff9f98764da)
            );
    $this->context->builder->call(
                $this->context->lookupFunction('printf') , 
                $fmt
                , $length
                , $value
                
            );
    
                            break;
                        case Variable::TYPE_NATIVE_LONG:
                            $global___c4ca4238a0b923820dcc509a6f75849b = $this->context->constantFromString("%lld");
            // TODO: Cast here, so it works
            $local___c4ca4238a0b923820dcc509a6f75849b = $this->context->builder->pointerCast($global___c4ca4238a0b923820dcc509a6f75849b, $this->context->getTypeFromString('char*'));
            $fmt = $local___c4ca4238a0b923820dcc509a6f75849b;
    $this->context->builder->call(
                $this->context->lookupFunction('printf') , 
                $fmt
                , $argValue
                
            );
    
                            break;
                        case Variable::TYPE_NATIVE_DOUBLE:
                            $global___c81e728d9d4c2f636f067f89cc14862c = $this->context->constantFromString("%G");
            // TODO: Cast here, so it works
            $local___c81e728d9d4c2f636f067f89cc14862c = $this->context->builder->pointerCast($global___c81e728d9d4c2f636f067f89cc14862c, $this->context->getTypeFromString('char*'));
            $fmt = $local___c81e728d9d4c2f636f067f89cc14862c;
    $this->context->builder->call(
                $this->context->lookupFunction('printf') , 
                $fmt
                , $argValue
                
            );
    
                            break;
                        default: 
                            throw new \LogicException("Echo for type $arg->type not implemented");
                    }
                    if ($op->type === OpCode::TYPE_PRINT) {
                        $this->assignOperand(
                            $block->getOperand($op->arg1),
                            new Variable($this->context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $this->context->constantFromInteger(1), null)
                        );
                    }
                    break;
                // case OpCode::TYPE_MUL:
                // case OpCode::TYPE_PLUS:
                // case OpCode::TYPE_MINUS:
                // case OpCode::TYPE_DIV:
                // case OpCode::TYPE_MODULO:
                // case OpCode::TYPE_BITWISE_AND:
                // case OpCode::TYPE_BITWISE_OR:
                // case OpCode::TYPE_BITWISE_XOR:
                // case OpCode::TYPE_GREATER_OR_EQUAL:
                // case OpCode::TYPE_SMALLER_OR_EQUAL:
                // case OpCode::TYPE_GREATER:
                // case OpCode::TYPE_SMALLER:
                // case OpCode::TYPE_IDENTICAL:
                // case OpCode::TYPE_EQUAL:
                // case OpCode::TYPE_UNARY_MINUS:
                // case OpCode::TYPE_CASE:
                case OpCode::TYPE_JUMP:
                    $newBlock = $this->compileBlockInternal($func, $op->block1, ...$args);
                    $this->context->freeDeadVariables($func, $basicBlock, $block);
                    $builder->branch($newBlock);
                    return $origBasicBlock;
                case OpCode::TYPE_JUMPIF:
                    $if = $this->compileBlockInternal($func, $op->block1, ...$args);
                    $else = $this->compileBlockInternal($func, $op->block2, ...$args);
                    $condition = $this->context->castToBool(
                        $this->context->getVariableFromOp($block->getOperand($op->arg1))->value
                    );

                    $this->context->freeDeadVariables($func, $basicBlock, $block);
                    $builder->branchIf($condition, $if, $else);
                    return $origBasicBlock;
                case OpCode::TYPE_RETURN_VOID:
                    $this->context->freeDeadVariables($func, $basicBlock, $block);
                    $this->context->builder->returnVoid();
    
                    return $origBasicBlock;
                case OpCode::TYPE_RETURN:
                    $return = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    $return->addref();
                    $retval = $return->value;
                    $this->context->freeDeadVariables($func, $basicBlock, $block);
                    $this->context->builder->returnValue($retval);
    
                    return $origBasicBlock;
                // case OpCode::TYPE_FUNCDEF:
                //     $nameOp = $block->getOperand($op->arg1);
                //     assert($nameOp instanceof Operand\Literal);
                //     $this->compileBlock($op->block1, $nameOp->value);
                //     break;
                // case OpCode::TYPE_FUNCCALL_INIT:
                //     $nameOp = $block->getOperand($op->arg1);
                //     if (!$nameOp instanceof Operand\Literal) {
                //         throw new \LogicException("Variable function calls not yet supported");
                //     }
                //     $lcname = strtolower($nameOp->value);
                //     if (!isset($this->context->functions[$lcname])) {
                //         throw new \RuntimeException("Call to undefined function $lcname");
                //     }
                //     $this->context->scope->toCall = $this->context->functions[$lcname];
                //     $this->context->scope->args = [];
                //     break;
                // case OpCode::TYPE_ARG_SEND:
                //     $this->context->scope->args[] = $this->context->getVariableFromOp($block->getOperand($op->arg1))->rvalue;
                //     break;
                // case OpCode::TYPE_FUNCCALL_EXEC_NORETURN:
                //     if (is_null($this->context->scope->toCall)) {
                //         // short circuit
                //         break;
                //     }
                //     $this->context->helper->eval($gccBlock, $this->context->scope->toCall->call(...$this->context->scope->args));
                //     break;
                // case OpCode::TYPE_FUNCCALL_EXEC_RETURN:
                //     $this->context->helper->assign(
                //         $gccBlock,
                //         $this->context->getVariableFromOp($block->getOperand($op->arg1))->lvalue,
                //         $this->context->scope->toCall->call(...$this->context->scope->args)
                //     );
                //     break;
                // case OpCode::TYPE_DECLARE_CLASS:
                //     $this->context->pushScope();
                //     $this->context->scope->classId = $this->context->type->object->declareClass($block->getOperand($op->arg1));
                //     $this->compileClass($op->block1, $this->context->scope->classId);
                //     $this->context->popScope();
                //     break;
                // case OpCode::TYPE_NEW:
                //     $class = $this->context->type->object->lookupOperand($block->getOperand($op->arg2));
                //     $this->context->helper->assign(
                //         $gccBlock,
                //         $this->context->getVariableFromOp($block->getOperand($op->arg1))->lvalue,
                //         $this->context->type->object->allocate($class)
                //     );
                //     $this->context->scope->toCall = null;
                //     $this->context->scope->args = [];
                //     break;
                // case OpCode::TYPE_PROPERTY_FETCH:
                //     $result = $block->getOperand($op->arg1);
                //     $obj = $block->getOperand($op->arg2);
                //     $name = $block->getOperand($op->arg3);
                //     assert($name instanceof Operand\Literal);
                //     assert($obj->type->type === Type::TYPE_OBJECT);
                //     $this->context->scope->variables[$result] = $this->context->type->object->propertyFetch(
                //         $this->context->getVariableFromOp($obj)->rvalue,
                //         $obj->type->userType,
                //         $name->value
                //     );
                //     break;
                default:
                var_dump($block);
                    throw new \LogicException("Unknown JIT opcode: ". $op->getType());
            }
        }
        throw new \LogicException("Reached the end of the loop, this shouldn't happen...");
    }

    private function compileClass(?Block $block, int $classId) {
        if ($block === null) {
            return;
        }
        foreach ($block->opCodes as $op) {
            switch ($op->type) {
                case OpCode::TYPE_DECLARE_PROPERTY:
                    $name = $block->getOperand($op->arg1);
                    assert($name instanceof Operand\Literal);
                    assert(is_null($op->arg2)); // no defaults for now
                    $type = Variable::getTypeFromType($block->getOperand($op->arg3)->type);
                    $this->context->type->object->defineProperty($classId, $name->value, $type);
                    break;
                default:
                    var_dump($op);
                    throw new \LogicException('Other class body types are not jittable for now');
            }
            
        }
    }

    private function assignOperand(Operand $result, Variable $value): void {
        if (empty($result->usages) && !$this->context->scope->variables->contains($result)) {
            return;
        }
        $result = $this->context->getVariableFromOp($result);
        if ($value->type === $result->type) {
            if ($value->type === Variable::TYPE_STRING) {
                $this->context->refcount->delref($result->value);
                $this->context->refcount->addref($value->value);
            } elseif ($value->type & Variable::IS_NATIVE_ARRAY) {
                // copy over the nextfreelement
                $result->nextFreeElement = $value->nextFreeElement;
            }
            $this->context->builder->store($value->value, $result->value);
            return;
        }

    }

}