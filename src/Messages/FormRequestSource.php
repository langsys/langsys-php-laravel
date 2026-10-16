<?php

namespace Langsys\Laravel\Messages;

use Closure;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteAction;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationRuleParser;
use Langsys\Laravel\Support\ClassDiscovery;
use Langsys\Laravel\Translation\CatalogTranslator;
use Langsys\SDK\Messages\HasMessageTemplate;
use Langsys\SDK\Messages\MessageCatalog;
use Langsys\SDK\Messages\MessageSource;
use Langsys\SDK\Messages\RuleTemplate;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Every validation message the application's FormRequests can send, listed from the code (MSG-7):
 * each rule of each FormRequest a route's action takes, a controller's or a closure's, built by
 * `ValidatorMessages` exactly as a failing request builds it. What cannot be listed ahead of time
 * is reported with its fix; until it is fixed it is shown in the source language, since a
 * validation message never registers at runtime.
 *
 * Validation written inline — `$request->validate([...])`, `Validator::make()`, `validator()` —
 * declares its rules where no listing can read them, so each such call in the app's PHP is
 * reported with its file and line. A Livewire class is the exception: Livewire shows what it
 * validates through `$errors` in the component, so none of it reaches a client as entries.
 */
final class FormRequestSource implements MessageSource
{
    /** Rules that change how others apply and never fail on their own. */
    private const SILENT = ['bail', 'exclude', 'exclude_if', 'exclude_unless', 'exclude_with', 'exclude_without', 'nullable', 'sometimes'];

    /** Rules whose `:value` Laravel fills from the other field's data, which is the rule's second parameter when it fails. */
    private const VALUE_FROM_DATA = ['accepted_if', 'declined_if', 'missing_if', 'present_if', 'prohibited_if', 'required_if'];

    /** Rules comparing a field with another field's size. */
    private const COMPARED = ['gt', 'gte', 'lt', 'lte'];

    /**
     * @param  list<class-string<FormRequest>>  $classes
     * @param  array<string, string>  $inline  Each inline validation, `file:line` => the call.
     */
    public function __construct(private readonly array $classes, private readonly array $inline = [])
    {
    }

    /**
     * The FormRequests the routes' actions take, and the calls that validate inline in the app's
     * PHP: the directories in `langsys.sync_paths`, else app/ and routes/, Blade files left out.
     */
    public static function fromRoutes(Router $router): self
    {
        $classes = [];

        foreach ($router->getRoutes() as $route) {
            foreach (self::_actionParameters($route) as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType && self::_isRequest($type->getName())) {
                    $classes[$type->getName()] = true;
                }
            }
        }

        $inline = [];

        foreach ((array) (config('langsys.sync_paths') ?: [app_path(), base_path('routes')]) as $directory) {
            $inline += self::_inlineValidation((string) $directory);
        }

        return new self(array_keys($classes), $inline);
    }

    /**
     * What a route's action takes: a controller method's parameters, or a closure's, the closure
     * read as Laravel reads it to run the route once `route:cache` has serialized it.
     *
     * @return list<\ReflectionParameter>
     */
    private static function _actionParameters(Route $route): array
    {
        $uses = $route->getAction('uses');

        if (is_string($uses) && RouteAction::containsSerializedClosure($route->getAction())) {
            $uses = unserialize($uses)->getClosure();
        }

        if ($uses instanceof Closure) {
            return (new ReflectionFunction($uses))->getParameters();
        }

        [$controller, $method] = array_pad(explode('@', $route->getActionName(), 2), 2, null);

        return $method !== null && method_exists($controller, $method) ? (new ReflectionMethod($controller, $method))->getParameters() : [];
    }

    /**
     * Each call in a directory's PHP that validates inline, read from the file's tokens: a
     * `validate()` or `validateWithBag()` handed its rules on the request (`$request`, `request()`)
     * or on a class validating through Laravel's `ValidatesRequests`, `Validator::make()` or
     * `Validator::validate()`, and the `validator()` helper. A `validate()` with no arguments
     * checks a validator built elsewhere, which is reported where it was built; one on any other
     * object is that object's own. A Blade file is left out: the PHP in it is a view's, or a
     * Livewire component's (Volt), which shows its validation through `$errors`.
     *
     * @return array<string, string>  `file:line` => the call
     */
    private static function _inlineValidation(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && !str_ends_with($file->getFilename(), '.blade.php')) {
                $files[$file->getPathname()] = $file;
            }
        }

        ksort($files);
        $found = [];

        foreach ($files as $path => $file) {
            $code = (string) $file->openFile()->fread(max(1, $file->getSize()));
            $class = ClassDiscovery::declaredClass($code);
            $calls = array_filter(self::_inlineCalls($code), fn (array $call) => $call[2] !== 'this' || self::_validatesRequests($class));

            if ($calls === [] || self::_isLivewire($class)) {
                continue;
            }

            // Named as `langsys:sync` names a file it reads: from the app's root.
            $label = str_starts_with($path, base_path() . DIRECTORY_SEPARATOR) ? substr($path, strlen(base_path()) + 1) : $path;

            foreach ($calls as [$line, $call]) {
                $found["$label:$line"] = $call;
            }
        }

        return $found;
    }

    /** @return list<array{0: int, 1: string, 2: ?string}> The line, the call, and for `validate()` whose it is: `request` or `this`. */
    private static function _inlineCalls(string $code): array
    {
        $tokens = array_values(array_filter(token_get_all($code), fn ($token) => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $calls = [];

        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            $before = $tokens[$i - 1] ?? null;
            $operator = is_array($before) ? $before[0] : $before;
            $name = strtolower($token[1]);

            $receiver = in_array($operator, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) ? self::_receiver($tokens, $i - 2) : null;

            $call = match (true) {
                in_array($operator, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) => $receiver !== null && in_array($name, ['validate', 'validatewithbag'], true) && ($tokens[$i + 2] ?? null) !== ')' ? "->{$token[1]}()" : null,
                $operator === T_DOUBLE_COLON => in_array($name, ['make', 'validate'], true) && is_array($tokens[$i - 2] ?? null) && str_ends_with('\\' . $tokens[$i - 2][1], '\\Validator') ? "Validator::{$token[1]}()" : null,
                default => $name === 'validator' && !in_array($operator, [T_FUNCTION, T_NEW, T_ATTRIBUTE], true) ? 'validator()' : null,
            };

            if ($call !== null) {
                $calls[] = [$token[2], $call, $receiver];
            }
        }

        return $calls;
    }

    /** Whose `->` call ends at a token: the request's (`$request`, `request()`), the class's own (`$this`), or another object's. */
    private static function _receiver(array $tokens, int $end): ?string
    {
        $token = $tokens[$end] ?? null;

        return match (true) {
            is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$request' => 'request',
            is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$this'    => 'this',
            $token === ')' && ($tokens[$end - 1] ?? null) === '(' && is_array($tokens[$end - 2] ?? null) && strtolower($tokens[$end - 2][1]) === 'request' => 'request',
            default => null,
        };
    }

    /** A class validating through Laravel's `ValidatesRequests`, as a controller does: `$this->validate($request, [...])`. */
    private static function _validatesRequests(?string $class): bool
    {
        try {
            return $class !== null && class_exists($class) && in_array(\Illuminate\Foundation\Validation\ValidatesRequests::class, class_uses_recursive($class), true);
        } catch (Throwable) {
            return false;
        }
    }

    private static function _isLivewire(?string $class): bool
    {
        try {
            return $class !== null && class_exists(\Livewire\Component::class) && (is_subclass_of($class, \Livewire\Component::class) || is_subclass_of($class, \Livewire\Form::class));
        } catch (Throwable) {
            return false;
        }
    }

    /** A FormRequest, or a laravel-data request DTO: the two ways Laravel apps declare a request's rules. */
    private static function _isRequest(string $class): bool
    {
        return is_subclass_of($class, FormRequest::class)
            || (class_exists(\Spatie\LaravelData\Data::class) && is_subclass_of($class, \Spatie\LaravelData\Data::class));
    }

    public function collect(MessageCatalog $catalog)
    {
        // Every template is source text: the labels, Laravel's lines and a rule object's message are
        // read in the language the app is written in, whatever the console's locale.
        $translator = app('translator');
        $locale = $translator->getLocale();
        $translator->setLocale(config('app.fallback_locale'));

        try {
            // The listing's reads are probes, not misses the app's missing-key handler hears of.
            $translator instanceof CatalogTranslator ? $translator->quietly(fn () => $this->_collect($catalog)) : $this->_collect($catalog);
        } finally {
            $translator->setLocale($locale);
        }

        foreach ($this->inline as $where => $call) {
            $catalog->problem($where, "validates inline with $call, so the messages it can send are not listed", 'declare these rules in a FormRequest a route\'s action takes');
        }
    }

    private function _collect(MessageCatalog $catalog): void
    {
        foreach ($this->classes as $class) {
            $source = class_basename($class);

            try {
                [$rules, $messages, $attributes, $hook] = self::_declared($class);
            } catch (Throwable $e) {
                $catalog->problem($source, "can't build its rules outside a request: {$e->getMessage()}", 'build rules() from the class alone, or list these messages as templates the app declares');

                continue;
            }

            $configure = self::_configure($catalog, $source, $hook);

            // One field that cannot be listed is reported; the rest of the app is still listed.
            foreach ($rules as $field => $fieldRules) {
                try {
                    $this->_collectField($catalog, $source, (string) $field, $fieldRules, $rules, $messages, $attributes, $configure);
                } catch (Throwable $e) {
                    $catalog->problem($source, "cannot be listed: {$e->getMessage()}", 'give this field a message the listing can read without a request, or report it if the rule is a package\'s', (string) $field);
                }
            }
        }
    }

    /**
     * The validator a failing request is checked by, configured as the framework configures it:
     * a FormRequest's `withValidator()`, or a laravel-data DTO's, which can set labels and option
     * names (`setAttributeNames()`, `setValueNames()`) the declared arrays do not carry. A hook that
     * cannot run outside a request is skipped, and said once.
     *
     * @param  ?\Closure(\Illuminate\Validation\Validator): void  $hook
     * @return \Closure(\Illuminate\Validation\Validator): \Illuminate\Validation\Validator
     */
    private static function _configure(MessageCatalog $catalog, string $source, ?\Closure $hook): \Closure
    {
        $failed = false;

        return function (\Illuminate\Validation\Validator $validator) use ($catalog, $source, $hook, &$failed) {
            if ($hook === null || $failed) {
                return $validator;
            }

            try {
                $hook($validator);
            } catch (Throwable $e) {
                $failed = true;
                $catalog->advise($source, "withValidator() cannot run outside a request: {$e->getMessage()}", 'labels and option names it sets are not in the listing; declare them in attributes() to list them');
            }

            return $validator;
        };
    }

    private function _collectField(MessageCatalog $catalog, string $source, string $field, mixed $fieldRules, array $rules, array $messages, array $attributes, \Closure $configure): void
    {
        $make = fn (array $data) => $configure(Validator::make($data, $rules, $messages, $attributes));
        $attribute = str_replace('*', '0', $field);
        $data = Arr::undot([$attribute => null]);
        $labelled = $make($data);
        $declared = array_key_exists($field, $attributes) || array_key_exists($field, $labelled->customAttributes) || array_key_exists($attribute, $labelled->customAttributes);

        // A wildcard field fails under a concrete path (`lines.0.qty`). With no label Laravel writes
        // that path into the sentence, so each index is its own phrase and none can be listed.
        if (str_contains($field, '*') && !$declared) {
            $catalog->problem($source, 'has no label, so each index is written into its own sentence', 'give it a label in attributes()', $field);

            return;
        }

        // MSG-10: advice, never a failure, `--strict` included. Laravel's derived name is sometimes a raw key.
        if (!$declared) {
            $shown = $labelled->getDisplayableAttribute($attribute);
            $catalog->advise($source, "has no declared label, so Laravel prints \"$shown\"", 'declare one in attributes() if that is not what users should read', $field);
        }

        foreach ((new ValidationRuleParser($data))->explode([$attribute => $fieldRules])->rules[$attribute] ?? [] as $rule) {
            if (!is_string($rule)) {
                $this->_collectRuleObject($catalog, $source, $field, $attribute, $rule, $make($data));

                continue;
            }

            [$name, $parameters] = ValidationRuleParser::parse($rule);
            $snake = Str::snake($name);

            if (in_array($snake, self::SILENT, true)) {
                continue;
            }

            // A conditional rule writes the other field's value into its sentence, so each value it
            // names is a sentence of its own: `required_if:type,business,nonprofit` is two. A size
            // rule naming another field reads as that field's label while it is empty and as a
            // number once it holds one: `gt:deposit` is two as well.
            $values = match (true) {
                in_array($snake, self::VALUE_FROM_DATA, true) && isset($parameters[0], $parameters[1]) => array_slice($parameters, 1),
                in_array($snake, self::COMPARED, true) && isset($parameters[0]) && !is_numeric($parameters[0]) => [null, 1],
                default => [null],
            };

            foreach ($values as $value) {
                $seeded = $value === null ? $data : array_replace_recursive($data, Arr::undot([$parameters[0] => $value]));
                $entry = ValidatorMessages::forRule($make($seeded), $attribute, $name, $parameters);

                if ($entry === null) {
                    $catalog->problem($source, "uses the rule '$snake', which Laravel ships no message for", 'give it a message in messages()', $field);

                    continue 2;
                }

                $catalog->add($entry->getTemplate(), $source, $field);
            }
        }
    }

    /**
     * A rule object that declares its message — Laravel's `Rule` contract, `message()` — is listed
     * once per field, the field's label written in by Laravel's own replacer. One that only calls
     * `$fail()` has no message to read ahead of time.
     */
    private function _collectRuleObject(MessageCatalog $catalog, string $source, string $field, string $attribute, object $rule, \Illuminate\Validation\Validator $validator): void
    {
        $rule = $rule instanceof \Illuminate\Validation\InvokableValidationRule ? $rule->invokable() : $rule;
        $label = $validator->getDisplayableAttribute($attribute);

        // As Laravel hands it over before the rule runs: `Rules\Enum` reads its line through it.
        if ($rule instanceof ValidatorAwareRule) {
            $rule->setValidator($validator);
        }

        // FRM-2: a rule that states its template is listed from it, markers intact.
        if ($rule instanceof HasMessageTemplate) {
            $catalog->addRule($rule, $label, '', $source, $field);

            return;
        }

        // A message the app declared for the rule is Laravel's pick over the rule's own, and is the
        // app's template as a declared message for any rule is.
        if (($declared = ValidatorMessages::declaredFor($validator, $attribute, get_class($rule))) !== null) {
            $catalog->add($validator->makeReplacements($declared, $attribute, get_class($rule), []), $source, $field);

            return;
        }

        // Laravel's `Password` states its messages only once it has failed: each line it can print
        // is listed from its rules, as a failing request sends it.
        if ($rule instanceof Password && method_exists($rule, 'appliedRules')) {
            foreach (ValidatorMessages::forPassword($validator, $attribute, $rule) as $entry) {
                $catalog->add($entry->getTemplate(), $source, $field);
            }

            // A rule object it was given is listed, or reported, as one on the field would be.
            foreach ((array) $rule->appliedRules()['customRules'] as $nested) {
                if (is_object($nested)) {
                    $this->_collectRuleObject($catalog, $source, $field, $attribute, $nested, $validator);
                }
            }

            return;
        }

        $messages = method_exists($rule, 'message') ? array_filter((array) $rule->message(), 'is_string') : [];

        // A rule that knows its message only once it has failed, such as Laravel's `File`. One of
        // Laravel's takes no template from the app, so its fix is a message declared for the field.
        if ($messages === []) {
            $fix = str_starts_with(get_class($rule), 'Illuminate\\') ? "declare its message in messages() as '$field." . get_class($rule) . "'" : RuleTemplate::missingTemplateProblem('')[1];
            $catalog->problem($source, 'uses the rule object ' . get_class($rule) . ', which declares no message ahead of time', $fix, $field);

            return;
        }

        // Laravel's own rule objects state Laravel's own line, and no app can give them a template.
        if (str_starts_with(get_class($rule), 'Illuminate\\')) {
            foreach ($messages as $message) {
                $catalog->add($validator->makeReplacements((string) $message, $attribute, get_class($rule), []), $source, $field);
            }

            return;
        }

        // A filled message is all Laravel's contract gives: it is listed, and the core reports the
        // missing template, since a value filled into it would become part of the phrase.
        foreach ($messages as $message) {
            $catalog->addRule($rule, $label, $validator->makeReplacements((string) $message, $attribute, get_class($rule), []), $source, $field);
        }
    }

    /**
     * A FormRequest's rules, messages and labels, read without a request: the container does not
     * resolve it, since resolving validates.
     *
     * @return array{0: array, 1: array, 2: array, 3: ?\Closure}
     */
    private static function _declared(string $class): array
    {
        if (!is_subclass_of($class, FormRequest::class)) {
            return self::_dataDeclared($class);
        }

        $request = new $class();
        $request->setContainer(app())->setRedirector(app('redirect'));

        $call = fn (string $method) => method_exists($request, $method) ? (array) app()->call([$request, $method]) : [];

        $hook = method_exists($request, 'withValidator') ? fn ($validator) => $request->withValidator($validator) : null;

        return [$call('rules'), $call('messages'), $call('attributes'), $hook];
    }

    /**
     * A laravel-data request DTO's rules, messages and labels, resolved by laravel-data itself
     * against a payload carrying every nested object and collection, so their rules are listed
     * too: laravel-data only resolves a nested object's rules when the payload has it.
     *
     * @return array{0: array, 1: array, 2: array, 3: ?\Closure}
     */
    private static function _dataDeclared(string $class): array
    {
        $payload = self::_samplePayload($class);
        $path = \Spatie\LaravelData\Support\Validation\ValidationPath::create();
        $rules = app(\Spatie\LaravelData\Resolvers\DataValidationRulesResolver::class)
            ->execute($class, $payload, $path, \Spatie\LaravelData\Support\Validation\DataRules::create());
        $declared = app(\Spatie\LaravelData\Resolvers\DataValidationMessagesAndAttributesResolver::class)->execute($class, $payload, $path);

        return [$rules, $declared['messages'] ?? [], $declared['attributes'] ?? [], fn ($validator) => $class::withValidator($validator)];
    }

    /** @param  list<class-string>  $chain  The classes above this one, so a recursive DTO stops. */
    private static function _samplePayload(string $class, array $chain = []): array
    {
        $payload = [];

        foreach (app(\Spatie\LaravelData\Support\DataConfig::class)->getDataClass($class)->properties as $property) {
            $nested = $property->type->dataClass;

            $payload[$property->inputMappedName ?? $property->name] = match (true) {
                $nested !== null && in_array($nested, $chain, true)             => null,
                $property->type->kind->isDataObject()                           => self::_samplePayload($nested, [...$chain, $class]),
                $property->type->kind->isDataCollectable() && $nested !== null => [self::_samplePayload($nested, [...$chain, $class])],
                default                                                         => 'sample',
            };
        }

        return $payload;
    }
}
