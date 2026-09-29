<?php

namespace Langsys\Laravel\Messages;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationRuleParser;
use Langsys\SDK\Messages\MessageCatalog;
use Langsys\SDK\Messages\MessageSource;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Every validation message the application's FormRequests can send, listed from the code (MSG-7):
 * each rule of each FormRequest a route's controller action takes, built by `ValidatorMessages`
 * exactly as a failing request builds it. What cannot be listed ahead of time is reported with its
 * fix; it still registers the first time it is sent (MSG-8).
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

                if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), FormRequest::class)) {
                    $classes[$type->getName()] = true;
                }
            }
        }

        return new self(array_keys($classes));
    }

    public function collect(MessageCatalog $catalog)
    {
        foreach ($this->classes as $class) {
            $source = class_basename($class);

            try {
                [$rules, $messages, $attributes] = self::_declared($class);
            } catch (Throwable $e) {
                $catalog->problem($source, "can't build its rules outside a request: {$e->getMessage()}", 'build rules() from the class alone, or list these messages as templates the app declares');

                continue;
            }

            foreach ($rules as $field => $fieldRules) {
                $this->_collectField($catalog, $source, (string) $field, $fieldRules, $rules, $messages, $attributes);
            }
        }
    }

    private function _collectField(MessageCatalog $catalog, string $source, string $field, mixed $fieldRules, array $rules, array $messages, array $attributes): void
    {
        // A wildcard field fails under a concrete path (`lines.0.qty`). With no label Laravel writes
        // that path into the sentence, so each index is its own phrase and none can be listed.
        if (str_contains($field, '*') && !array_key_exists($field, $attributes)) {
            $catalog->problem($source, 'has no label, so each index is written into its own sentence', 'give it a label in attributes()', $field);

            return;
        }

        $attribute = str_replace('*', '0', $field);
        $data = Arr::undot([$attribute => null]);

        // MSG-10: advice, not a failure. Laravel's derived name is sometimes a raw key.
        if (!array_key_exists($field, $attributes)) {
            $shown = Validator::make($data, $rules, $messages, $attributes)->getDisplayableAttribute($attribute);
            $catalog->problem($source, "has no declared label, so Laravel prints \"$shown\"", 'declare one in attributes() if that is not what users should read', $field);
        }

        foreach ((new ValidationRuleParser($data))->explode([$attribute => $fieldRules])->rules[$attribute] ?? [] as $rule) {
            if (!is_string($rule)) {
                $catalog->problem($source, 'uses the rule object ' . get_class($rule) . ', which declares no template', 'its message registers the first time it is sent', $field);

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

            $validator = Validator::make($data, $rules, $messages, $attributes);
            $entry = ValidatorMessages::forRule($validator, $attribute, $name, $parameters);

            if ($entry === null) {
                $catalog->problem($source, "uses the rule '$snake', which Laravel ships no message for", 'give it a message in messages()', $field);

                continue;
            }

            $catalog->add($entry->getTemplate(), $source, $field);
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
        $request = new $class();
        $request->setContainer(app())->setRedirector(app('redirect'));

        $call = fn (string $method) => method_exists($request, $method) ? (array) app()->call([$request, $method]) : [];

        return [$call('rules'), $call('messages'), $call('attributes')];
    }
}
