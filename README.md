# Laravel Blade @expects

[Blade templates](https://laravel.com/docs/5.8/blade) are great, but lacks a good way to define the variables it 
requires to work. In a normal template you must check if variables are set and/or set a default value for it. You will
end with code like this:

```blade
    <body class="{{ $section }} {{ $subsection ?? 'default' }}">
        
        <article>
            <h1>{{ $article->title }}</h1>
            
            {!! $article->content !!}
        </article>
    </body>
``` 

Using PHP 5 the thing is even worse. So this package adds a simple `@expects` directive to define the variables expected 
by the view, just like this:

```blade
    @expects(\App\Article $article, string $section,  string $subsection = 'default')
    <body class="{{ $section }} {{ $subsection }}">
            
        <article>
            <h1>{{ $article->title }}</h1>
            
            {!! $article->content !!}
        </article>
    </body>
```

## How it works

The directive is parsed like a closure and extract its parameters. The definition tells what to do:

* If the parameter has no default value, the variable is required and an exception is thrown
* If the parameter has a default value, this value is set if not defined
* The [type declaration](https://www.php.net/manual/en/functions.arguments.php#functions.arguments.type-declaration)
sets the required type and and exception is throw if not matches

## PhpStorm integration

The PhpStorm IDE can recognize the custom Blade directive if is set in *File > Settings > Languages & Frameworks > PHP >
Blade > Directives* by adding a new one with the following properties:

 * Name: expects
 * Has parameters: check
 * Prefix: <?php function(
 * Suffix: ){}; ?>

![test image size](phpstorm.png){:class="img-responsive"}