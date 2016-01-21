<?php

namespace Overblog\GraphBundle\Resolver;

use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use Overblog\GraphBundle\Definition\FieldInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ConfigResolver implements ResolverInterface
{
    /**
     * @var ExpressionLanguage
     */
    private $expressionLanguage;

    /**
     * @var TypeResolver
     */
    private $typeResolver;

    /**
     * @var FieldResolver
     */
    private $fieldResolver;

    // [name => method]
    private $resolverMap = [
        'fields' => 'resolveFields',
        'isTypeOf' => 'resolveResolveCallback',
        'interfaces' => 'resolveInterfaces',
        'types' => 'resolveType',
        'values' => 'resolveValues',
        'resolveType' => 'resolveResolveCallback',
        'resolveCursor' => 'resolveResolveCallback',
        'resolveNode' => 'resolveResolveCallback',
        'nodeType' => 'resolveTypeCallback',
        'connectionFields' => 'resolveFields',
        'edgeFields' => 'resolveFields',
        'outputFields' => 'resolveFields',
        'inputFields' => 'resolveFields',
        'mutateAndGetPayload' => 'resolveResolveCallback',
    ];

    public function __construct(ResolverInterface $typeResolver, ResolverInterface $fieldResolver, ExpressionLanguage $expressionLanguage)
    {
        $this->typeResolver = $typeResolver;
        $this->fieldResolver = $fieldResolver;
        $this->expressionLanguage = $expressionLanguage;
    }

    public function resolve($config)
    {
        if (!is_array($config)) {
            $config = [$config];
        }

        foreach($config as $name => &$values) {
            if (!isset($this->resolverMap[$name]) || empty($values)) {
                continue;
            }
            $resolverMethod = $this->resolverMap[$name];
            $values = $this->$resolverMethod($values);
        }

        return $config;
    }

    private function resolveFields(array $fields)
    {
        foreach ($fields as $field => &$options) {
            if (isset($options['builder']) && is_string($options['builder'])) {
                $alias = $options['builder'];

                $fieldsBuilder = $this->fieldResolver->resolve($alias);

                if ($fieldsBuilder instanceof FieldInterface) {
                    $options = $fieldsBuilder->toFieldsDefinition();
                }
                elseif(is_callable($fieldsBuilder)) {
                    $options = $fieldsBuilder();
                }
                else {
                    $options = get_object_vars($fieldsBuilder);
                }

                continue;
            }

            if (isset($options['type']) && is_string($options['type'])) {
                $options['type'] = $this->resolveTypeCallback($options['type']);
            }

            if (isset($options['args'])) {
                foreach($options['args'] as &$argsOptions) {
                    $argsOptions['type'] = $this->resolveTypeCallback($argsOptions['type']);
                }
            }

            if (isset($options['resolve']) && is_string($options['resolve'])) {
                $options['resolve'] = $this->resolveResolveCallback($options['resolve']);
            }
        }

        return $fields;
    }

    private function resolveTypeCallback($expr)
    {
        return function () use ($expr) {
            $type = $this->typeResolver->resolve($expr);

            if (!$type instanceof Type) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid type! Must be instance of "%s"', 'GraphQL\\Type\\Definition\\Type')
                );
            }

            return $type;
        };
    }

    private function resolveInterfaces(array $rawInterfaces)
    {
        $interfaces = [];

        foreach($rawInterfaces as $alias) {
            $interface = $this->typeResolver->resolve($alias);

            if (!$interface instanceof InterfaceType) {
                throw new UnresolvableException(
                    sprintf(
                        'Invalid interface with alias "%s", must extend "%s".',
                        $alias,
                        'GraphQL\\Type\\Definition\\InterfaceType'
                    )
                );
            }

            $interfaces[] = $interface;
        }

        return $interfaces;
    }

    private function resolveTypes(array $rawTypes)
    {
        $types = [];

        foreach($rawTypes as $alias) {
            $type = $this->typeResolver->resolve($alias);

            if (!$type instanceof Type) {
                throw new UnresolvableException(
                    sprintf(
                        'Invalid type with alias "%s", must extend "%s".',
                        $alias,
                        'GraphQL\\Type\\Definition\\Type'
                    )
                );
            }

            $types[] = $type;
        }

        return $types;
    }

    private function resolveResolveCallback($expression)
    {
        if (empty($expression)) {
            return null;
        }
        $optionResolver = new OptionsResolver();
        $optionResolver->setDefaults([null, null, null]);

        return function (...$args) use ($expression, $optionResolver) {
            $args = $optionResolver->resolve($args);

            $arg1IsResolveInfo = $args[1] instanceof ResolveInfo;

            return $this->expressionLanguage->evaluate(
                $expression,
                [
                    'value' => $args[0],
                    'args' => !$arg1IsResolveInfo ? $args[1] : [],
                    'info' => $arg1IsResolveInfo ? $args[1] : $args[2],
                ]
            );
        };
    }

    private function resolveValues(array $rawValues)
    {
        $values = $rawValues;

        foreach ($values as $name => &$options) {
            if (isset($options['value'])) {
                $options['value'] = $this->expressionLanguage->evaluate($options['value']);
            }
        }

        return $values;
    }
}
