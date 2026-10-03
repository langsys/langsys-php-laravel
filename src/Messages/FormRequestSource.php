<?php

namespace Langsys\Laravel\Messages;

use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationRuleParser;
use Langsys\SDK\Messages\HasMessageTemplate;
use Langsys\SDK\Messages\MessageCatalog;
use Langsys\SDK\Messages\MessageSource;
use Langsys\SDK\Messages\RuleTemplate;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Every validation message the application's FormRequests can send, listed from the code (MSG-7):
 * each rule of each FormRequest a route's controller action takes, built by `ValidatorMessages`
 * exactly as a failing request builds it. What cannot be listed ahead of time is reported with its
 * fix; until it is fixed it is shown in the source language, since nothing registers at runtime.
 */
final class FormRequestSource implements MessageSource
{
    /** Rules that change how others apply and never fail on their own. */
    private const SILENT = ['bail', 'exclude', 'exclude_if', 'exclude_unless', 'exclude_with', 'exclude_without', 'nullable', 'sometimes'];

    /** Rules whose `:value` Laravel fills from the other field's data, which is the rule's second parameter when it fails. */
    private const VALUE_FROM_DATA = ['accepted_if', 'declined_if', 'missing_if', 'present_if', 'prohibited_if', 'required_if'];

    /** @param  list<class-string<FormRequest>>  $classes */
    public function __construct(private readonly array $classes)
    {
    }

    /** The FormRequests the routes' controller actions take. */
    public static function fromRoutes(Router $router): self
    {
        $classes = [];

        foreach ($router->getRoutes() as $route) {
            [$controller, $method] = array_pad(explode('@', $route->getActionName(), 2), 2, null);

            if ($method === null || !method_exists($controller, $method)) {
                continue;
            }

            foreach ((new ReflectionMethod($controller, $method))->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType && self::_isRequest($type->getName())) {
                    $classes[$type->getName()] = true;
                }
            }
        }

        return new self(array_keys($classes));
    }

    /** A FormRequest, or a laravel-data request DTO: the two ways Laravel apps declare a request's rules. */
    private static function _isRequest(string $class): bool
    {
        return is_subclass_of($class, FormRequest::class)
            || (class_exists(\Spatie\LaravelData\Data::class) && is_subclass_of($class, \Spatie\LaravelData\Data::class));
    }

    public function collect(MessageCatalog $catalog)
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

            if (in_array($snake, self::VALUE_FROM_DATA, true) && isset($parameters[0], $parameters[1])) {
                $data = array_replace_recursive($data, Arr::undot([$parameters[0] => $parameters[1]]));
            }

            $validator = $make($data);
            $entry = ValidatorMessages::forRule($validator, $attribute, $name, $parameters);

            if ($entry === null) {
                $catalog->problem($source, "uses the rule '$snake', which Laravel ships no message for", 'give it a message in messages()', $field);

                continue;
            }

            $catalog->add($entry->getTemplate(), $source, $field);
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

        $messages = method_exists($rule, 'message') ? array_filter((array) $rule->message(), 'is_string') : [];

        // `Password` implements Laravel's contract too, but only knows its message once it has failed.
        if ($messages === []) {
            $catalog->problem($source, 'uses the rule object ' . get_class($rule) . ', which declares no message ahead of time', RuleTemplate::missingTemplateProblem('')[1], $field);

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
     * @return array{0: array, 1: array, 2: array}
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
     * @return array{0: array, 1: array, 2: array}
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
