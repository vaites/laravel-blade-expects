<?php

namespace Vaites\Laravel\BladeExpects;

use File;
use Exception;

use UnexpectedValueException;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;

use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\DocBlock\Tag;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlock\Tags\Var_;
use phpDocumentor\Reflection\Types\Array_;
use phpDocumentor\Reflection\Types\Compound;
use phpDocumentor\Reflection\Types\Float_;
use phpDocumentor\Reflection\Types\Integer;
use phpDocumentor\Reflection\Types\Null_;
use phpDocumentor\Reflection\Types\Object_;
use phpDocumentor\Reflection\Types\String_;

use PhpParser\Error;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Nop;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

use Vaites\Laravel\BladeExpects\Exceptions\PhpTagsNotAllowedException;

class BladeExpectsServiceProvider extends ServiceProvider
{
    /**
     * Is directive enabled?
     *
     * @var bool
     */
    protected $enabled = true;

    /**
     * Are PHP tags allowed?
     *
     * @var bool
     */
    protected $phpTags = true;

    /**
     * Variable types
     *
     * @var array|string[]
     */
    protected $types = ['array', 'int', 'float', 'string'];

    /**
     * PhpParser instance
     *
     * @var \PhpParser\Parser\Multiple
     */
    protected $parser;

    /**
     * Add the custom directive
     *
     * @return void
     */
    public function boot()
    {
        $this->enabled = env('BLADE_EXPECTS_ENABLED', true);
        $this->phpTags = env('BLADE_EXPECTS_PHP_TAGS', true);

        $this->parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);

        Blade::extend([$this, 'compile']);
    }

    /**
     * Parse the custom directive and replace the code
     *
     * @throws  \Exception
     */
    public function compile(string $code, BladeCompiler $compiler): string
    {
        if($this->phpTags === false && preg_match('/(<\?php|<\?=|@php)/', File::get($compiler->getPath())))
        {
            throw new PhpTagsNotAllowedException('PHP code is not allowed in Blade templates');
        }

        $closure = '/@expects\((.+)\)/Usi';
        $docblock = '/@expects(.+)@endexpects/Usi';

        try
        {
            if($this->enabled === true)
            {
                $code = preg_replace_callback($closure, [$this, 'generateCodeFromClosure'], $code);
                $code = preg_replace_callback($docblock, [$this, 'generateCodeFromDocBlock'], $code);
            }
            else
            {
                $code = preg_replace($closure, '', preg_replace($docblock, '', $code));
            }
        }
        catch(Error $exception)
        {
            // a parse error is an invalid usage of the directive
            throw new Exception("Invalid @expects usage (" . $exception->getMessage() . ")");
        }

        return $code;
    }

    /**
     * Closure approach
     */
    protected function generateCodeFromClosure(array $matches): ?string
    {
        /* @var \PhpParser\Node\Param $param */

        $compiled = null;

        [$tag, $definition] = $matches;
        $closure = head($this->parser->parse("<?php function({$definition}){}; ?>"))->expr;

        foreach($closure->params as $param)
        {
            $var = "\${$param->var->name}";

            if(is_null($param->default))
            {
                $compiled .= $this->expectRequiredVariable($var);
            }
            else
            {
                $default = isset($param->default->value) ? $param->default->value : null;
                $compiled .= $this->expectOptionalVariable($var, $default);
            }

            if($param->type instanceof Identifier)
            {
                $compiled .= $this->expectType($var, $param->type->name);
            }
            elseif($param->type instanceof Name)
            {
                $compiled .= $this->expectClassInstance($var, '\\' . implode('\\', $param->type->parts));
            }
        }

        return $compiled ? "<?php\n\n{$compiled}\n ?>" : null;
    }

    /**
     * DocBlock approach
     */
    protected function generateCodeFromDocBlock(array $matches): ?string
    {
        /** @var \PhpParser\Node\Stmt\Nop $block */

        $compiled = null;

        [$tag, $definition] = $matches;

        $docblock = DocBlockFactory::createInstance()->create($definition);

        foreach($docblock->getTags() as $tag)
        {
            if($tag instanceof Param || $tag instanceof Var_)
            {
                $var = "\${$tag->getVariableName()}";

                [$type, $default, $null] = $this->getDocBlockTagDefinition($tag);

                if($null === false && $default === null)
                {
                    $compiled .= $this->expectRequiredVariable($var);
                }
                else
                {
                    $compiled .= $this->expectOptionalVariable($var, $default);
                }

                if($type !== null && in_array($type, $this->types))
                {
                    $compiled .= $this->expectType($var, $type);
                }
                elseif($type !== null)
                {
                    $compiled .= $this->expectClassInstance($var, $type);
                }
            }
        }

        return $compiled ? "<?php\n\n{$compiled}\n ?>" : null;
    }

    /**
     * Get the type, default value and nullabillity of a phpDoc variable description
     */
    protected function getDocBlockTagDefinition(Tag $tag): array
    {
        $type = null;
        $default = null;
        $null = false;

        $types = [];
        if($tag->getType() instanceof Compound)
        {
            foreach($tag->getType() as $item)
            {
                $types[] = $item;
            }
        }
        else
        {
            $types[] = $tag->getType();
        }

        foreach($types as $item)
        {
            if($item instanceof Object_)
            {
                $type = (string) $item;
            }
            elseif($item instanceof Array_)
            {
                $type = 'array';
            }
            elseif($item instanceof Float_)
            {
                $type = 'float';
            }
            elseif($item instanceof Integer)
            {
                $type = 'int';
            }
            elseif($item instanceof String_)
            {
                $type = 'string';
            }
            elseif($item instanceof Null_)
            {
                $null = true;
            }
        }

        if($tag->getDescription() && preg_match('/\(default:(.+)\)/Ui', $tag->getDescription(), $match))
        {
            switch($type)
            {
                case 'array':
                    $default = trim($match[1]);
                    break;

                case 'float':
                    $default = (float) $match[1];
                    break;

                case 'int':
                    $default = (int) $match[1];
                    break;

                case 'string':
                    $default = trim($match[1]);
                    break;
            }
        }

        return [$type, $default, $null];
    }

    /**
     * Generate the code to check a required variable
     */
    protected function expectRequiredVariable(string $var): string
    {
        $exception = \Vaites\Laravel\BladeExpects\Exceptions\UndefinedVariableException::class;
        $message = "View expects $var variable to be defined";

        return "if(!isset($var)){ throw new $exception('$message'); }\n";
    }

    /**
     * Generate the code to define a variable with a default value
     */
    protected function expectOptionalVariable(string $var, $default = null): string
    {
        switch(true)
        {
            case is_float($default):
            case is_int($default):
                $value = $default;
                break;

            case is_null($default):
                $value = 'null';
                break;

            case is_string($default):
                $value = "'" . addslashes($default) . "'";
                break;

            default:
                throw new UnexpectedValueException("$default is not a valid default value for $var");
        }

        return  "if(!isset($var)) { $var = $value; }\n";
    }

    /**
     * Generate the code to verify the type of a variable
     */
    protected function expectType(string $var, string $type): string
    {
        $exception = \Vaites\Laravel\BladeExpects\Exceptions\WrongTypeException::class;

        $method = "is_$type";
        $prep = preg_match('/^[aeiou]/', $type) ? 'an' : 'a';
        $message = "View expects {$var} variable to be $prep $type instead of ";

        return "if(!is_null($var) && !$method($var)){ throw new $exception('$message' . gettype($var)); }\n";
    }

    /**
     * Generate the code to verify the class of an instance
     */
    protected function expectClassInstance(string $var, string $class): string
    {
        $exception = \Vaites\Laravel\BladeExpects\Exceptions\WrongClassException::class;

        $message = "View expects {$var} variable to be an instance of {$class}";

        return "if(!is_null($var) && !$var instanceof $class){ throw new $exception('$message'); }\n";
    }
}
