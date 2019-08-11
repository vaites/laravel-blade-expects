<?php

namespace Vaites\Laravel\BladeExpects;

use Blade;
use Exception;

use Illuminate\Support\ServiceProvider;

use PhpParser\Error;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

class BladeExpectsServiceProvider extends ServiceProvider
{
    /**
     * Add the custom directive
     *
     * @return void
     */
    public function boot()
    {
        Blade::directive('expects', [$this, 'expects']);
    }

    /**
     * Force the definition of the variables expected by a view and set its default values
     *
     * @param   array   $arguments
     * @return  string
     * @throws  \Exception
     */
    public function expects(string $definition): ?string
    {
        $compiled = null;

        $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);

        try
        {
            /* @var \PhpParser\Node\Expr\Closure */
            /* @var \PhpParser\Node\Param $param */

            // parses the code like a function that must receive parameters
            $closure = head($parser->parse("<?php function({$definition}){}; ?>"))->expr;

            // check each parameter
            foreach($closure->params as $param)
            {
                $name = $param->var->name;
                $var = "\${$param->var->name}";

                // without default value, is a required variable and throws an exception if not defined
                if(is_null($param->default))
                {
                    $exception = '\Vaites\Laravel\BladeExpects\BladeExpectsUndefinedVariableException';
                    $message = "View expects $var variable to be defined";
                    $compiled .= "if(!isset($var)){ throw new $exception('$message'); }\n";
                }
                // with a default value, sets the default value if variable is not set
                else
                {
                    $default = (new Standard())->prettyPrintExpr($param->default);
                    $compiled .= "if(!isset($var)) { $var = $default; }\n";
                }

                // check a primary type
                if($param->type instanceof Identifier)
                {
                    $exception = '\Vaites\Laravel\BladeExpects\BladeExpectsWrongTypeException';

                    $method = "is_{$param->type->name}";
                    $message = "View expects \\\${$name} variable to be {$param->type->name}";
                    $compiled .= "if(!is_null($var) && !$method($var)){ throw new $exception(\"$message\"); }\n";
                }
                // check a class name
                elseif($param->type instanceof Name)
                {
                    $exception = '\Vaites\Laravel\BladeExpects\BladeExpectsWrongClassException';

                    $class = '\\' . implode('\\', $param->type->parts);
                    $message = "View expects \\\${$name} variable to be an instance of {$class}";
                    $compiled .= "if(!is_null($var) && !$var instanceof $class){ throw new $exception(\"$message\"); }\n";
                }
            }
        }
        // a parse error is an invalid usage of the directive
        catch(Error $exception)
        {
            throw new Exception("Invalid @expects usage (" . $exception->getMessage() . ")");
        }

        return "<?php\n\n{$compiled}\n?>";
    }
}
