<?php

declare(strict_types=1);

namespace Phan\Parse;

use AssertionError;
use ast;
use ast\Node;
use InvalidArgumentException;
use Phan\Analysis\ScopeVisitor;
use Phan\AST\ASTReverter;
use Phan\AST\ContextNode;
use Phan\AST\UnionTypeVisitor;
use Phan\CodeBase;
use Phan\Config;
use Phan\Daemon;
use Phan\Exception\FQSENException;
use Phan\Exception\IssueException;
use Phan\Exception\UnanalyzableException;
use Phan\Issue;
use Phan\Language\Context;
use Phan\Language\Element\Attribute;
use Phan\Language\Element\ClassConstant;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\Comment;
use Phan\Language\Element\EnumCase;
use Phan\Language\Element\Flags;
use Phan\Language\Element\Func;
use Phan\Language\Element\FunctionFactory;
use Phan\Language\Element\FunctionInterface;
use Phan\Language\Element\GlobalConstant;
use Phan\Language\Element\Method;
use Phan\Language\Element\Parameter;
use Phan\Language\Element\Property;
use Phan\Language\ElementContext;
use Phan\Language\FQSEN\FullyQualifiedClassConstantName;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\FQSEN\FullyQualifiedFunctionName;
use Phan\Language\FQSEN\FullyQualifiedGlobalConstantName;
use Phan\Language\FQSEN\FullyQualifiedMethodName;
use Phan\Language\FQSEN\FullyQualifiedPropertyName;
use Phan\Language\FutureUnionType;
use Phan\Language\Type;
use Phan\Language\Type\ArrayShapeType;
use Phan\Language\Type\ArrayType;
use Phan\Language\Type\MixedType;
use Phan\Language\Type\NullType;
use Phan\Language\Type\StringType;
use Phan\Language\UnionType;
use Phan\Library\FileCache;
use Phan\Library\None;

/**
 * The class is a visitor for AST nodes that does parsing. Each
 * visitor populates the $code_base with any
 * globally accessible structural elements and will return a
 * possibly new context as modified by the given node.
 *
 * @property-read CodeBase $code_base
 *
 * @phan-file-suppress PhanUnusedPublicMethodParameter implementing faster no-op methods for common visit*
 * @phan-file-suppress PhanPartialTypeMismatchArgument
 * @phan-file-suppress PhanPartialTypeMismatchArgumentInternal
 *
 * @method Context __invoke(Node $node)
 */
class ParseVisitor extends ScopeVisitor
{

    /**
     * @param Context $context
     * The context of the parser at the node for which we'd
     * like to determine a type
     *
     * @param CodeBase $code_base
     * The global code base in which we store all
     * state
     */
    /*
    public function __construct(
        CodeBase $code_base,
        Context $context
    ) {
        parent::__construct($code_base, $context);
    }
     */

    /**
     * Visit a node with kind `\ast\AST_CLASS`
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     * @throws FQSENException if the node has invalid names
     */
    public function visitClass(Node $node): Context
    {
        if ($node->flags & \ast\flags\CLASS_ANONYMOUS) {
            $class_name = (new ContextNode(
                $this->code_base,
                $this->context,
                $node
            ))->getUnqualifiedNameForAnonymousClass();
        } else {
            $class_name = (string)$node->children['name'];
        }

        // This happens now and then and I have no idea
        // why.
        if ($class_name === '') {
            return $this->context;
        }

        $class_fqsen = FullyQualifiedClassName::fromStringInContext(
            $class_name,
            $this->context
        );

        // Hunt for an available alternate ID if necessary
        $alternate_id = 0;
        while ($this->code_base->hasClassWithFQSEN($class_fqsen)) {
            $class_fqsen = $class_fqsen->withAlternateId(++$alternate_id);
        }

        if ($alternate_id > 0) {
            Daemon::debugf("Using an alternate for %s: %d\n", $class_fqsen, $alternate_id);
        }

        // Build the class from what we know so far
        $class_context = $this->context
            ->withLineNumberStart($node->lineno)
            ->withLineNumberEnd($node->endLineno ?? 0);

        $class = new Clazz(
            $class_context,
            $class_name,
            $class_fqsen->asRealUnionType(),
            $node->flags,
            $class_fqsen
        );
        $class->setDeclId($node->children['__declId']);
        $class->setDidFinishParsing(false);
        $class->setAttributeList(Attribute::fromNodeForAttributeList(
            $this->code_base,
            $class_context,
            $node->children['attributes'] ?? null
        ));
        $type_node = $node->children['type'] ?? null;
        if ($type_node) {
            $class->setEnumType(UnionTypeVisitor::unionTypeFromNode(
                $this->code_base,
                $class_context,
                $type_node
            ));
        }
        try {
            // Set the scope of the class's context to be the
            // internal scope of the class
            $class_context = $class_context->withScope(
                $class->getInternalScope()
            );

            $doc_comment = $node->children['docComment'] ?? '';
            $class->setDocComment($doc_comment);

            // Add the class to the code base as a globally
            // accessible object
            // This must be done before Comment::fromStringInContext
            // so that the class definition is available there.
            $this->code_base->addClass($class);

            // Get a comment on the class declaration
            $comment = Comment::fromStringInContext(
                $doc_comment,
                $this->code_base,
                $class_context,
                $node->lineno,
                Comment::ON_CLASS
            );

            // Add any template types parameterizing a generic class
            foreach ($comment->getTemplateTypeList() as $template_type) {
                $class->getInternalScope()->addTemplateType($template_type);
            }

            // Handle @phan-immutable, @deprecated, @internal,
            // @phan-forbid-undeclared-magic-properties, and @phan-forbid-undeclared-magic-methods
            $class->setPhanFlags($class->getPhanFlags() | $comment->getPhanFlagsForClass());

            $class->setSuppressIssueSet(
                $comment->getSuppressIssueSet()
            );

            // Depends on code_base for checking existence of __get and __set.
            // TODO: Add a check in analyzeClasses phase that magic @property declarations
            // are limited to classes with either __get or __set declared (or interface/abstract
            $class->setMagicPropertyMap(
                $comment->getMagicPropertyMap(),
                $this->code_base
            );

            // Depends on code_base for checking existence of __call or __callStatic.
            // TODO: Add a check in analyzeClasses phase that magic @method declarations
            // are limited to classes with either __get or __set declared (or interface/abstract)
            $class->setMagicMethodMap(
                $comment->getMagicMethodMap(),
                $this->code_base
            );

            // Look to see if we have a parent class
            $extends_node = $node->children['extends'] ?? null;
            if ($extends_node instanceof Node) {
                $parent_class_name = (string)UnionTypeVisitor::unionTypeFromClassNode($this->code_base, $this->context, $extends_node);

                // The name is fully qualified.
                $parent_fqsen = FullyQualifiedClassName::fromFullyQualifiedString(
                    $parent_class_name
                );

                // Set the parent for the class
                $class->setParentType($parent_fqsen->asType(), $extends_node->lineno);
            }

            // If the class explicitly sets its overriding extension type,
            // set that on the class
            $inherited_type_option = $comment->getInheritedTypeOption();
            if ($inherited_type_option->isDefined()) {
                $class->setParentType($inherited_type_option->get());
            }
            $class->setMixinTypes($comment->getMixinTypes());

            // Add any implemented interfaces
            foreach ($node->children['implements']->children ?? [] as $name_node) {
                if (!$name_node instanceof Node) {
                    throw new AssertionError('Expected list of AST_NAME nodes');
                }
                $name = (string)UnionTypeVisitor::unionTypeFromClassNode($this->code_base, $this->context, $name_node);
                $class->addInterfaceClassFQSEN(
                    FullyQualifiedClassName::fromFullyQualifiedString(
                        $name
                    ),
                    $name_node->lineno
                );
            }
        } finally {
            $class->setDidFinishParsing(true);
        }

        return $class_context;
    }

    /**
     * Visit a node with kind `\ast\AST_USE_TRAIT`
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     *
     * @throws UnanalyzableException if saw an invalid AST node (e.g. from polyfill)
     */
    public function visitUseTrait(Node $node): Context
    {
        // Bomb out if we're not in a class context
        $class = $this->getContextClass();

        // @phan-suppress-next-line PhanThrowTypeMismatchForCall should be impossible
        $trait_fqsen_list = (new ContextNode(
            $this->code_base,
            $this->context,
            $node->children['traits']
        ))->getTraitFQSENList();

        // Add each trait to the class
        foreach ($trait_fqsen_list as $trait_fqsen) {
            $class->addTraitFQSEN($trait_fqsen, $node->children['traits']->lineno ?? 0);
        }

        // Get the adaptations for those traits
        // Pass in the corresponding FQSENs for those traits.
        $trait_adaptations_map = (new ContextNode(
            $this->code_base,
            $this->context,
            $node->children['adaptations']
        ))->getTraitAdaptationsMap($trait_fqsen_list);

        foreach ($trait_adaptations_map as $trait_adaptations) {
            $class->addTraitAdaptations($trait_adaptations);
        }

        return $this->context;
    }

    /**
     * Visit a node with kind `\ast\AST_METHOD`
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     */
    public function visitMethod(Node $node): Context
    {
        // Bomb out if we're not in a class context
        $class = $this->getContextClass();
        $context = $this->context;
        $code_base = $this->code_base;

        $method_name = (string)$node->children['name'];

        $method_fqsen = FullyQualifiedMethodName::make(
            $class->getFQSEN(),
            $method_name
        );

        // Hunt for an available alternate ID if necessary
        $alternate_id = 0;
        while ($code_base->hasMethodWithFQSEN($method_fqsen)) {
            $method_fqsen =
                $method_fqsen->withAlternateId(++$alternate_id);
        }

        $method = Method::fromNode(
            clone($context),
            $code_base,
            $node,
            $method_fqsen,
            $class
        );

        if ($context->isPHPInternal()) {
            // only for stubs
            foreach (FunctionFactory::functionListFromFunction($method) as $method_variant) {
                if (!($method_variant instanceof Method)) {
                    throw new AssertionError("Expected variants of Method to be Method");
                }
                $class->addMethod($code_base, $method_variant, None::instance());
            }
        } else {
            $class->addMethod($code_base, $method, None::instance());
        }

        $method_name_lower = \strtolower($method_name);
        if ('__construct' === $method_name_lower) {
            $class->setIsParentConstructorCalled(false);

            // Handle constructor property promotion of __construct parameters
            foreach ($method->getParameterList() as $i => $parameter) {
                if ($parameter->getFlags() & Parameter::PARAM_MODIFIER_FLAGS) {
                    // @phan-suppress-next-line PhanTypeMismatchArgumentNullable kind is AST_PARAM
                    $this->addPromotedConstructorPropertyFromParam($class, $method, $parameter, $node->children['params']->children[$i]);
                }
            }
        } elseif ('__tostring' === $method_name_lower) {
            if (!$this->context->isStrictTypes()) {
                $class->addAdditionalType(StringType::instance(false));
            }
            // In PHP 8 and later having a __toString method automatically adds the Stringable interface, #4476
            if (Config::get_closest_minimum_target_php_version_id() >= 80000) {
                // @phan-suppress-next-line PhanThrowTypeAbsentForCall should not happen, built in type
                $class->addAdditionalType(Type::fromFullyQualifiedString('\Stringable'));
            }
        }


        // Create a new context with a new scope
        return $this->context->withScope($method->getInternalScope());
    }

    /**
     * Add an instance property from php 8.0 constructor property promotion
     * (`__construct(public int $param)`)
     *
     * This heavily duplicates parts of visitPropGroup
     */
    private function addPromotedConstructorPropertyFromParam(
        Clazz $class,
        Method $method,
        Parameter $parameter,
        Node $parameter_node
    ): void {
        $lineno = $parameter_node->lineno;
        $context = (clone($this->context))->withLineNumberStart($lineno);
        if ($parameter_node->flags & ast\flags\PARAM_VARIADIC) {
            $this->emitIssue(
                Issue::InvalidNode,
                $lineno,
                "Cannot declare variadic promoted property"
            );
            return;
        }
        // TODO: this should probably use FutureUnionType instead.
        $doc_comment = $parameter_node->children['docComment'] ?? '';
        $name = $parameter->getName();
        $method_comment = $method->getComment();
        $variable_comment = $method_comment ? ($method_comment->getParameterMap()[$name] ?? null) : null;
        $property_comment = Comment::fromStringInContext(
            $doc_comment,
            $this->code_base,
            $this->context,
            $lineno,
            Comment::ON_PROPERTY
        );
        $attributes = Attribute::fromNodeForAttributeList(
            $this->code_base,
            $this->context,
            $parameter_node->children['attributes']
        );

        $property = $this->addProperty(
            $class,
            $parameter->getName(),
            $parameter_node->children['default'],
            $parameter->getUnionType()->getRealUnionType(),
            $variable_comment,
            $lineno,
            $parameter_node->flags & Parameter::PARAM_MODIFIER_FLAGS,
            $doc_comment,
            $property_comment,
            $attributes,
            true
        );
        if (!$property) {
            return;
        }
        $property->setAttributeList($parameter->getAttributeList());
        // Get a comment on the property declaration
        $property->setHasWriteReference(); // Assigned from within constructor
        $property->addReference($context); // Assigned from within constructor
        if ($class->isImmutable()) {
            if (!$property->isStatic() && !$property->isWriteOnly()) {
                $property->setIsReadOnly(true);
            }
        }
    }

    /**
     * Visit a node with kind `\ast\AST_PROP_GROUP`
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     */
    public function visitPropGroup(Node $node): Context
    {
        // Bomb out if we're not in a class context
        ['attributes' => $attributes_node, 'props' => $props_node, 'type' => $type_node] = $node->children;
        if (!$props_node instanceof Node) {
            throw new AssertionError('Expected list of properties to be a node');
        }
        if ($type_node) {
            try {
                // Normalize to normalize php8 union types such as int|false|null to ?int|?false
                $real_union_type = (new UnionTypeVisitor($this->code_base, $this->context))->fromTypeInSignature($type_node)->asNormalizedTypes();
            } catch (IssueException $e) {
                Issue::maybeEmitInstance($this->code_base, $this->context, $e->getIssueInstance());
                $real_union_type = UnionType::empty();
            }
            if (Config::get_closest_minimum_target_php_version_id() < 70400) {
                $this->emitIssue(
                    Issue::CompatibleTypedProperty,
                    $type_node->lineno,
                    ((string)$real_union_type) ?: '(unknown)'
                );
            }
        } else {
            $real_union_type = UnionType::empty();
        }

        $class = $this->getContextClass();
        $doc_comment = '';
        $first_child_node = $props_node->children[0] ?? null;
        if ($first_child_node instanceof Node) {
            $doc_comment = $first_child_node->children['docComment'] ?? '';
        }
        // Get a comment on the property declaration
        $comment = Comment::fromStringInContext(
            $doc_comment,
            $this->code_base,
            $this->context,
            $props_node->lineno ?? 0,
            Comment::ON_PROPERTY
        );
        $attributes = Attribute::fromNodeForAttributeList(
            $this->code_base,
            $this->context,
            $attributes_node
        );

        foreach ($props_node->children as $i => $child_node) {
            // Ignore children which are not property elements
            if (!($child_node instanceof Node)
                || $child_node->kind !== \ast\AST_PROP_ELEM
            ) {
                continue;
            }
            $variable = $comment->getVariableList()[$i] ?? null;
            $default_node = $child_node->children['default'];
            $property_name = $child_node->children['name'];
            if (!\is_string($property_name)) {
                throw new AssertionError(
                    'Property name must be a string. '
                    . 'Got '
                    . \print_r($property_name, true)
                    . ' at '
                    . (clone($this->context))->withLineNumberStart($child_node->lineno)
                );
            }
            $this->addProperty(
                $class,
                $property_name,
                $default_node,
                $real_union_type,
                $variable,
                $child_node->lineno,
                $node->flags,
                $doc_comment,
                $comment,
                $attributes,
                false
            );
        }

        return $this->context;
    }


    /**
     * @param ?(ast\Node|string|float|int) $default_node
     * @param list<Attribute> $attributes
     */
    private function addProperty(Clazz $class, string $property_name, $default_node, UnionType $real_union_type, ?Comment\Parameter $variable, int $lineno, int $flags, ?string $doc_comment, Comment $property_comment, array $attributes, bool $from_parameter): ?Property
    {
        $variable_has_literals = $variable && $variable->getUnionType()->hasLiterals();

        // If something goes wrong will getting the type of
        // a property, we'll store it as a future union
        // type and try to figure it out later
        $future_union_type_node = null;

        $context_for_property = (clone($this->context))->withLineNumberStart($lineno);
        $real_type_set = $real_union_type->getTypeSet();

        if ($default_node === null) {
            // This is a declaration such as `public $x;` with no $default_node
            // (we don't assume the property is always null, to reduce false positives)
            // We don't need to compare this to the real union type
            $union_type = $real_union_type;
            $default_type = NullType::instance(false)->asRealUnionType();
        } else {
            if ($default_node instanceof Node) {
                $this->checkNodeIsConstExprOrWarn(
                    $default_node,
                    $from_parameter ? self::CONSTANT_EXPRESSION_IN_PARAMETER : self::CONSTANT_EXPRESSION_IN_PROPERTY
                );
                $union_type = $this->resolveDefaultPropertyNode($default_node);
                if (!$union_type) {
                    // We'll type check this union type against the real union type when the future union type is resolved
                    $future_union_type_node = $default_node;
                    $union_type = UnionType::empty();
                }
            } else {
                // Get the type of the default (not a literal)
                // The literal value needs to be known to warn about incompatible composition of traits
                $union_type = Type::fromObject($default_node)->asPHPDocUnionType();
            }
            $default_type = $union_type;
            // Erase the corresponding real type set to avoid false positives such as `$x->prop['field'] === null` is redundant/impossible.
            $union_type = $union_type->asNonLiteralType()->eraseRealTypeSetRecursively();
            if ($real_union_type->isEmpty()) {
                if ($union_type->isType(NullType::instance(false))) {
                    $union_type = UnionType::empty();
                }
            } else {
                if (!$union_type->isStrictSubtypeOf($this->code_base, $real_union_type)) {
                    $this->emitIssue(
                        Issue::TypeMismatchPropertyDefaultReal,
                        $context_for_property->getLineNumberStart(),
                        $real_union_type,
                        $property_name,
                        ASTReverter::toShortString($default_node),
                        $union_type
                    );
                    $union_type = $real_union_type;
                } else {
                    $original_union_type = $union_type;
                    foreach ($real_union_type->getTypeSet() as $type) {
                        if (!$type->asPHPDocUnionType()->isStrictSubtypeOf($this->code_base, $original_union_type)) {
                            $union_type = $union_type->withType($type);
                        }
                    }
                }
                $union_type = $union_type->withRealTypeSet($real_union_type->getTypeSet())->asNormalizedTypes();
            }
        }

        $property_fqsen = FullyQualifiedPropertyName::make(
            $class->getFQSEN(),
            $property_name
        );
        if ($this->code_base->hasPropertyWithFQSEN($property_fqsen)) {
            $old_property = $this->code_base->getPropertyByFQSEN($property_fqsen);
            if ($old_property->getDefiningFQSEN() === $property_fqsen) {
                // Note: PHPDoc properties are parsed by Phan before real properties, so they take precedence (e.g. they are more visible)
                // PhanRedefineMagicProperty is a separate check.
                if ($old_property->isFromPHPDoc()) {
                    return null;
                }
                $this->emitIssue(
                    Issue::RedefineProperty,
                    $lineno,
                    $property_name,
                    $this->context->getFile(),
                    $lineno,
                    $this->context->getFile(),
                    $old_property->getContext()->getLineNumberStart()
                );
                return null;
            }
        }

        $property = new Property(
            $context_for_property,
            $property_name,
            $union_type,
            $flags,
            $property_fqsen,
            $real_union_type
        );
        $property->setAttributeList($attributes);
        if ($variable) {
            $property->setPHPDocUnionType($variable->getUnionType());
        } else {
            $property->setPHPDocUnionType($real_union_type);
        }
        $property->setDefaultType($default_type);

        $phan_flags = $property_comment->getPhanFlagsForProperty();
        if ($flags & ast\flags\MODIFIER_READONLY) {
            $phan_flags |= Flags::IS_READ_ONLY;
            if (Config::get_closest_minimum_target_php_version_id() < 80100) {
                Issue::maybeEmit(
                    $this->code_base,
                    $context_for_property,
                    Issue::CompatibleReadonlyProperty,
                    $context_for_property->getLineNumberStart(),
                    $property
                );
            }
        }
        $property->setPhanFlags($phan_flags);
        $property->setDocComment($doc_comment);

        // Add the property to the class
        $class->addProperty($this->code_base, $property, None::instance());

        $property->setSuppressIssueSet($property_comment->getSuppressIssueSet());

        if ($future_union_type_node instanceof Node) {
            $future_union_type = new FutureUnionType(
                $this->code_base,
                new ElementContext($property),
                //new ElementContext($property),
                $future_union_type_node
            );
        } else {
            $future_union_type = null;
        }
        // Look for any @var declarations
        if ($variable) {
            $original_union_type = $union_type;
            // We try to avoid resolving $future_union_type except when necessary,
            // to avoid issues such as https://github.com/phan/phan/issues/311 and many more.
            if ($future_union_type) {
                try {
                    $original_union_type = $future_union_type->get()->eraseRealTypeSetRecursively();
                    if (!$variable_has_literals) {
                        $original_union_type = $original_union_type->asNonLiteralType();
                    }
                    // We successfully resolved the union type. We no longer need $future_union_type
                    $future_union_type = null;
                } catch (IssueException $_) {
                    // Do nothing
                }
                if ($future_union_type === null) {
                    if ($original_union_type->isType(ArrayShapeType::empty())) {
                        $union_type = ArrayType::instance(false)->asPHPDocUnionType();
                    } elseif ($original_union_type->isType(NullType::instance(false))) {
                        $union_type = UnionType::empty();
                    } else {
                        $union_type = $original_union_type;
                    }
                    // Replace the empty union type with the resolved union type.
                    $property->setUnionType($union_type->withRealTypeSet($real_type_set));
                }
            }

            // XXX during the parse phase, parent classes may be missing.
            if ($default_node !== null &&
                !$original_union_type->isType(NullType::instance(false)) &&
                !$variable->getUnionType()->canCastToUnionType($original_union_type, $this->code_base) &&
                !$original_union_type->canCastToUnionType($variable->getUnionType(), $this->code_base) &&
                !$property->checkHasSuppressIssueAndIncrementCount(Issue::TypeMismatchPropertyDefault)
            ) {
                $this->emitIssue(
                    Issue::TypeMismatchPropertyDefault,
                    $lineno,
                    (string)$variable->getUnionType(),
                    $property->getName(),
                    ASTReverter::toShortString($default_node),
                    (string)$original_union_type
                );
            }

            $original_property_type = $property->getUnionType();
            $original_variable_type = $variable->getUnionType();
            $variable_type = $original_variable_type->withStaticResolvedInContext($this->context);
            if ($variable_type !== $original_variable_type) {
                // Instance properties with (at)var static will have the same type as the class they're in
                // TODO: Support `static[]` as well when inheriting
                if ($property->isStatic()) {
                    $this->emitIssue(
                        Issue::StaticPropIsStaticType,
                        $variable->getLineno(),
                        $property->getRepresentationForIssue(),
                        $original_variable_type,
                        $variable_type
                    );
                } else {
                    $property->setHasStaticInUnionType(true);
                }
            }
            if ($variable_type->hasGenericArray() && !$original_property_type->hasTypeMatchingCallback(static function (Type $type): bool {
                return \get_class($type) !== ArrayType::class;
            })) {
                // Don't convert `/** @var T[] */ public $x = []` to union type `T[]|array`
                $property->setUnionType($variable_type->withRealTypeSet($real_type_set));
            } else {
                // Set the declared type to the doc-comment type and add
                // |null if the default value is null
                $property->setUnionType($original_property_type->withUnionType($variable_type)->withRealTypeSet($real_type_set));
            }
        }

        // Wait until after we've added the (at)var type
        // before setting the future so that calling
        // $property->getUnionType() doesn't force the
        // future to be reified.
        if ($future_union_type instanceof FutureUnionType) {
            $property->setFutureUnionType($future_union_type);
        }
        if ($class->isImmutable()) {
            if (!$property->isStatic() && !$property->isWriteOnly()) {
                $property->setIsReadOnly(true);
            }
        }
        return $property;
    }

    /**
     * Resolve the union type of a property's default node.
     * This is being done to resolve the most common cases - e.g. `null`, `false`, and `true`
     *
     * FIXME: Handle 2+2, -1 (unary op), etc.
     */
    private function resolveDefaultPropertyNode(Node $node): ?UnionType
    {
        if ($node->kind === ast\AST_CONST) {
            try {
                return (new ContextNode(
                    $this->code_base,
                    $this->context,
                    $node
                ))->getConst()->getUnionType()->eraseRealTypeSetRecursively();
            } catch (IssueException $_) {
                // ignore
            }
        }
        return null;
    }

    /**
     * Visit a node with kind `\ast\AST_CLASS_CONST_GROUP`
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     *
     */
    public function visitClassConstGroup(Node $node): Context
    {
        $class = $this->getContextClass();
        $attributes = Attribute::fromNodeForAttributeList(
            $this->code_base,
            $this->context,
            $node->children['attributes']
        );
        if (($node->flags & ast\flags\MODIFIER_FINAL) && Config::get_closest_minimum_target_php_version_id() < 80100) {
            $this->emitIssue(
                Issue::CompatibleFinalClassConstant,
                $node->lineno
            );
        }
        if ($node->flags & (ast\flags\MODIFIER_STATIC | ast\flags\MODIFIER_ABSTRACT)) {
            $this->emitIssue(
                Issue::InvalidNode,
                $node->lineno,
                "Invalid modifiers for class constant group"
            );
        }

        foreach ($node->children['const']->children ?? [] as $child_node) {
            if (!$child_node instanceof Node) {
                throw new AssertionError('expected class const element to be a Node');
            }
            $name = $child_node->children['name'];
            if (!\is_string($name)) {
                throw new AssertionError('expected class const name to be a string');
            }

            $fqsen = FullyQualifiedClassConstantName::make(
                $class->getFQSEN(),
                $name
            );
            if ($this->code_base->hasClassConstantWithFQSEN($fqsen)) {
                $old_constant = $this->code_base->getClassConstantByFQSEN($fqsen);
                if ($old_constant->getDefiningFQSEN() === $fqsen) {
                    $this->emitIssue(
                        Issue::RedefineClassConstant,
                        $child_node->lineno,
                        $name,
                        $this->context->getFile(),
                        $child_node->lineno,
                        $this->context->getFile(),
                        $old_constant->getContext()->getLineNumberStart()
                    );
                    continue;
                }
            }

            // Get a comment on the declaration
            $doc_comment = $child_node->children['docComment'] ?? '';
            $comment = Comment::fromStringInContext(
                $doc_comment,
                $this->code_base,
                $this->context,
                $child_node->lineno,
                Comment::ON_CONST
            );

            $line_number_start = $child_node->lineno;
            $flags = $node->flags;
            // Prior to php 8.1, it was impossible to override constants declared in interfaces.
            if ($class->isInterface() && Config::get_closest_minimum_target_php_version_id() < 80100) {
                $flags |= ast\flags\MODIFIER_FINAL;
            }

            $constant = new ClassConstant(
                $this->context
                    ->withLineNumberStart($line_number_start)
                    ->withLineNumberEnd($child_node->endLineno ?? $line_number_start),
                $name,
                UnionType::empty(),
                $flags,
                $fqsen
            );

            $constant->setDocComment($doc_comment);
            $constant->setAttributeList($attributes);

            $this->handleClassConstantComment($constant, $comment);

            $value_node = $child_node->children['value'];
            if ($value_node instanceof Node) {
                if ($this->checkNodeIsConstExprOrWarn($value_node, self::CONSTANT_EXPRESSION_IN_CLASS_CONSTANT)) {
                    // TODO: Avoid using this when it only contains literals (nothing depending on the CodeBase),
                    $constant->setFutureUnionType(
                        new FutureUnionType(
                            $this->code_base,
                            new ElementContext($constant),
                            $value_node
                        )
                    );
                } else {
                    $constant->setUnionType(MixedType::instance(false)->asPHPDocUnionType());
                }
            } else {
                // This is a literal scalar value.
                // Assume that this is the only definition of the class constant and that it's not a stub for something that depends on configuration.
                //
                // TODO: What about internal stubs (isPHPInternal()) - if Phan would treat those like being from phpdoc,
                // it should do the same for FutureUnionType
                $constant->setUnionType(Type::fromObject($value_node)->asRealUnionType());
            }
            $constant->setNodeForValue($value_node);

            $class->addConstant($this->code_base, $constant);
        }

        return $this->context;
    }

    /**
     * Visit a node with kind `\ast\AST_ENUM_CASE`
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     */
    public function visitEnumCase(Node $node): Context
    {
        $class = $this->getContextClass();
        $attributes = Attribute::fromNodeForAttributeList(
            $this->code_base,
            $this->context,
            $node->children['attributes']
        );

        $name = $node->children['name'];
        if (!\is_string($name)) {
            throw new AssertionError('expected enum case name to be a string');
        }
        $fqsen = FullyQualifiedClassConstantName::make($class->getFQSEN(), $name);
        $lineno = $node->lineno;

        if (!$class->isEnum()) {
            $this->emitIssue(Issue::InvalidNode, $lineno, 'Cannot declare an enum case statement in a non-enum');
            return $this->context;
        }

        if ($this->code_base->hasClassConstantWithFQSEN($fqsen)) {
            $old_constant = $this->code_base->getClassConstantByFQSEN($fqsen);
            if ($old_constant->getDefiningFQSEN() === $fqsen) {
                $this->emitIssue(
                    Issue::RedefineClassConstant,
                    $lineno,
                    $name,
                    $this->context->getFile(),
                    $lineno,
                    $this->context->getFile(),
                    $old_constant->getContext()->getLineNumberStart()
                );
                return $this->context;
            }
        }

        // Get a comment on the declaration
        $doc_comment = $node->children['docComment'] ?? '';
        $comment = Comment::fromStringInContext(
            $doc_comment,
            $this->code_base,
            $this->context,
            $lineno,
            Comment::ON_CONST
        );

        $constant = new EnumCase(
            $this->context
                ->withLineNumberStart($lineno)
                ->withLineNumberEnd($node->endLineno ?? $lineno),
            $name,
            UnionType::empty(),
            $node->flags,
            $fqsen
        );

        $constant->setDocComment($doc_comment);
        $constant->setAttributeList($attributes);

        $this->handleClassConstantComment($constant, $comment);

        $value_node = $node->children['expr'];
        if ($value_node instanceof Node) {
            // NOTE: In php itself, the same types of operations are allowed as other constant expressions (i.e. isConstExpr is the correct check).
            //
            // However, const expressions for enum cases are evaluated when compiling an enum,
            // including looking up global constants and class constants,
            // and if that can't be evaluated then it's a fatal compile error.
            $this->checkNodeIsConstExprOrWarn($value_node, self::CONSTANT_EXPRESSION_IN_CLASS_CONSTANT);
        }
        $constant->setUnionType($class->getFQSEN()->asType()->asRealUnionType());
        $constant->setNodeForValue($value_node);

        $class->addEnumCase($this->code_base, $constant);

        foreach ($comment->getVariableList() as $var) {
            if ($var->getUnionType()->hasTemplateTypeRecursive()) {
                $this->emitIssue(
                    Issue::TemplateTypeConstant,
                    $constant->getFileRef()->getLineNumberStart(),
                    (string)$constant->getFQSEN()
                );
                break;
            }
        }

        return $this->context;
    }

    private function handleClassConstantComment(ClassConstant $constant, Comment $comment): void
    {
        $constant->setIsDeprecated($comment->isDeprecated());
        $constant->setIsNSInternal($comment->isNSInternal());
        $constant->setIsOverrideIntended($comment->isOverrideIntended());
        $constant->setIsPHPDocAbstract($comment->isPHPDocAbstract());
        $constant->setSuppressIssueSet($comment->getSuppressIssueSet());
        $constant->setComment($comment);
        foreach ($comment->getVariableList() as $var) {
            if ($var->getUnionType()->hasTemplateTypeRecursive()) {
                $this->emitIssue(
                    Issue::TemplateTypeConstant,
                    $constant->getFileRef()->getLineNumberStart(),
                    (string)$constant->getFQSEN()
                );
                break;
            }
        }
    }

    /**
     * Visit a node with kind `\ast\AST_STATIC` (a static variable)
     */
    public function visitStatic(Node $node): Context
    {
        $default = $node->children['default'];
        if ($default instanceof Node) {
            $this->checkNodeIsConstExprOrWarn($default, self::CONSTANT_EXPRESSION_IN_STATIC_VARIABLE);
        }
        $context = $this->context;
        // Make sure we're actually returning from a method.
        if ($context->isInFunctionLikeScope()) {
            // Get the method/function/closure we're in
            $method = $context->getFunctionLikeInScope($this->code_base);

            // Mark the method as having a static variable
            $method->setHasStaticVariable(true);
        }

        return $context;
    }

    /**
     * Visit a node with kind `\ast\AST_CONST`
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     */
    public function visitConstDecl(Node $node): Context
    {
        foreach ($node->children as $child_node) {
            if (!$child_node instanceof Node) {
                throw new AssertionError("Expected global constant element to be a Node");
            }

            $value_node = $child_node->children['value'];
            if ($value_node instanceof Node && !$this->checkNodeIsConstExprOrWarn($value_node, self::CONSTANT_EXPRESSION_IN_CONSTANT)) {
                // Note: Global constants with invalid value expressions aren't declared.
                // However, class constants are declared with placeholders to make inheritance checks, etc. easier.
                // Both will emit PhanInvalidConstantExpression
                continue;
            }
            self::addConstant(
                $this->code_base,
                $this->context,
                $child_node->lineno,
                $child_node->children['name'],
                $value_node,
                $child_node->flags ?? 0,
                $child_node->children['docComment'] ?? '',
                true
            );
        }

        return $this->context;
    }

    /**
     * Visit a node with kind `\ast\AST_FUNC_DECL`
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     */
    public function visitFuncDecl(Node $node): Context
    {
        $function_name = (string)$node->children['name'];
        $context = $this->context;
        $code_base = $this->code_base;

        // Hunt for an un-taken alternate ID
        $alternate_id = 0;
        do {
            // @phan-suppress-next-line PhanThrowTypeAbsentForCall this is valid
            $function_fqsen = FullyQualifiedFunctionName::fromFullyQualifiedString(
                \rtrim($context->getNamespace(), '\\') . '\\' . $function_name
            )->withAlternateId($alternate_id++);
        } while ($code_base->hasFunctionWithFQSEN($function_fqsen));

        $func = Func::fromNode(
            $context
                ->withLineNumberStart($node->lineno ?? 0)
                ->withLineNumberEnd($node->endLineno ?? 0),
            $code_base,
            $node,
            $function_fqsen
        );

        if ($context->isPHPInternal()) {
            // only for stubs
            foreach (FunctionFactory::functionListFromFunction($func) as $func_variant) {
                if (!($func_variant instanceof Func)) {
                    throw new AssertionError("Expecteded variant of Func to be a Func");
                }
                $code_base->addFunction($func_variant);
            }
        } else {
            $code_base->addFunction($func);
        }

        // Send the context into the function and reset the scope
        $context = $this->context->withScope(
            $func->getInternalScope()
        );

        return $context;
    }

    /**
     * Visit a node with kind `\ast\AST_CLOSURE`
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     */
    public function visitClosure(Node $node): Context
    {
        $closure_fqsen = FullyQualifiedFunctionName::fromClosureInContext(
            $this->context->withLineNumberStart($node->lineno),
            $node
        );

        $func = Func::fromNode(
            $this->context,
            $this->code_base,
            $node,
            $closure_fqsen
        );

        $this->code_base->addFunction($func);

        // Send the context into the function and reset the scope
        // (E.g. to properly check for the presence of `return` statements.
        $context = $this->context->withScope(
            $func->getInternalScope()
        );

        return $context;
    }

    /**
     * Visit a node with kind `\ast\AST_ARROW_FUNC`
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     */
    public function visitArrowFunc(Node $node): Context
    {
        return $this->visitClosure($node);
    }

    /**
     * Visit a node with kind `\ast\AST_CALL`
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     */
    public function visitCall(Node $node): Context
    {
        // If this is a call to a method that indicates that we
        // are treating the method in scope as a varargs method,
        // then set its optional args to something very high so
        // it can be called with anything.
        $expression = $node->children['expr'];

        if ($expression instanceof Node && $expression->kind === \ast\AST_NAME) {
            $function_name = \strtolower($expression->children['name']);
            if (\in_array($function_name, [
                'func_get_args', 'func_get_arg', 'func_num_args'
            ], true)) {
                if ($this->context->isInFunctionLikeScope()) {
                    $this->context->getFunctionLikeInScope($this->code_base)
                                  ->setNumberOfOptionalParameters(FunctionInterface::INFINITE_PARAMETERS);
                }
            } elseif ($function_name === 'define') {
                $this->analyzeDefine($node);
            } elseif ($function_name === 'class_alias') {
                if (Config::getValue('enable_class_alias_support') && $this->context->isInGlobalScope()) {
                    $this->recordClassAlias($node);
                }
            }
        }
        if (Config::get_backward_compatibility_checks()) {
            $this->analyzeBackwardCompatibility($node);

            // Handle first-class callables which have no args
            foreach ($node->children['args']->children ?? [] as $arg_node) {
                if ($arg_node instanceof Node) {
                    $this->analyzeBackwardCompatibility($arg_node);
                }
            }
        }
        return $this->context;
    }

    private function analyzeDefine(Node $node): void
    {
        $args = $node->children['args']->children ?? [];
        if (\count($args) < 2) {
            // Ignore first-class callables and calls with too few arguments.
            return;
        }
        $name = $args[0];
        if ($name instanceof Node) {
            try {
                $name_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $this->context, $name, false);
            } catch (IssueException $_) {
                // If this is really an issue, we'll emit it in the analysis phase when we have all of the element definitions.
                return;
            }
            $name = $name_type->asSingleScalarValueOrNull();
        }

        if (!\is_string($name)) {
            return;
        }
        self::addConstant(
            $this->code_base,
            $this->context,
            $node->lineno,
            $name,
            $args[1],
            0,
            '',
            true,
            true
        );
    }

    /**
     * Visit a node with kind `\ast\AST_STATIC_CALL`
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     */
    public function visitStaticCall(Node $node): Context
    {
        $call = $node->children['class'];

        if ($call instanceof Node && $call->kind === \ast\AST_NAME) {
            $func_name = \strtolower($call->children['name']);
            if ($func_name === 'parent') {
                // Make sure it is not a crazy dynamic parent method call
                if (!($node->children['method'] instanceof Node)) {
                    $meth = \strtolower($node->children['method']);

                    if ($meth === '__construct' && $this->context->isInClassScope()) {
                        $class = $this->getContextClass();
                        $class->setIsParentConstructorCalled(true);
                    }
                }
            }
        }

        return $this->context;
    }

    /**
     * Analyze a node for syntax backward compatibility, if that option is enabled
     */
    private function analyzeBackwardCompatibility(Node $node): void
    {
        if (Config::get_backward_compatibility_checks()) {
            (new ContextNode(
                $this->code_base,
                $this->context,
                $node
            ))->analyzeBackwardCompatibility();
        }
    }

    /**
     * Visit a node with kind `\ast\AST_RETURN`
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     *
     * TODO: Defer analysis of the inside of methods until the class gets hydrated.
     */
    public function visitReturn(Node $node): Context
    {
        $this->analyzeBackwardCompatibility($node);

        // Make sure we're actually returning from a method.
        if (!$this->context->isInFunctionLikeScope()) {
            return $this->context;
        }

        // Get the method/function/closure we're in
        $method = $this->context->getFunctionLikeInScope(
            $this->code_base
        );

        // Mark the method as returning something if expr is not null
        if (isset($node->children['expr'])) {
            $method->setHasReturn(true);
        }

        return $this->context;
    }

    /**
     * Visit a node with kind `\ast\AST_YIELD`
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     *
     * TODO: Defer analysis of the inside of methods until the method/function gets hydrated.
     */
    public function visitYield(Node $node): Context
    {
        return $this->analyzeYield($node);
    }

    /**
     * Visit a node with kind `\ast\AST_YIELD_FROM`
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     */
    public function visitYieldFrom(Node $node): Context
    {
        return $this->analyzeYield($node);
    }


    /**
     * Visit a node with kind `\ast\AST_YIELD_FROM` or kind `\ast_YIELD`
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     */
    private function analyzeYield(Node $node): Context
    {
        $this->analyzeBackwardCompatibility($node);

        // Make sure we're actually returning from a method.
        if (!$this->context->isInFunctionLikeScope()) {
            return $this->context;
        }

        // Get the method/function/closure we're in
        $method = $this->context->getFunctionLikeInScope(
            $this->code_base
        );

        // Mark the method as yielding something (and returning a generator)
        $method->setHasYield(true);
        $method->setHasReturn(true);

        return $this->context;
    }

    /**
     * Visit a node with kind `\ast\AST_PRINT`
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     */
    public function visitPrint(Node $node): Context
    {
        // Analyze backward compatibility for the arguments of this print statement.
        $this->analyzeBackwardCompatibility($node);
        return $this->context;
    }
    /**
     * Visit a node with kind `\ast\AST_ECHO`
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     */
    public function visitEcho(Node $node): Context
    {
        // Analyze backward compatibility for the arguments of this echo statement.
        $this->analyzeBackwardCompatibility($node);
        return $this->context;
    }

    /**
     * Visit a node with kind `\ast\AST_METHOD_CALL`
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     */
    public function visitMethodCall(Node $node): Context
    {
        // Analyze backward compatibility for the arguments of this method call
        $this->analyzeBackwardCompatibility($node);
        return $this->context;
    }

    public function visitAssign(Node $node): Context
    {
        if (!Config::get_backward_compatibility_checks()) {
            return $this->context;
        }
        // Analyze the assignment for compatibility with some
        // breaking changes between PHP5 and PHP7.
        $var_node = $node->children['var'];
        if ($var_node instanceof Node) {
            $this->analyzeBackwardCompatibility($var_node);
        }
        $expr_node = $node->children['expr'];
        if ($expr_node instanceof Node) {
            $this->analyzeBackwardCompatibility($expr_node);
        }
        return $this->context;
    }

    public function visitDim(Node $node): Context
    {
        if (!Config::get_backward_compatibility_checks()) {
            return $this->context;
        }

        $expr = $node->children['expr'];
        if (!($expr instanceof Node)) {
            return $this->context;
        }

        // check for $$var[]
        if ($expr->kind === \ast\AST_VAR
            && ($expr->children['name']->kind ?? null) === \ast\AST_VAR
        ) {
            $temp = $expr->children['name'];
            $depth = 1;
            while ($temp instanceof Node) {
                if (!isset($temp->children['name'])) {
                    throw new AssertionError("Expected to find a name in context, something else found.");
                }
                $temp = $temp->children['name'];
                $depth++;
            }
            $dollars = \str_repeat('$', $depth);
            $cache_entry = FileCache::getOrReadEntry($this->context->getFile());
            $line = $cache_entry->getLine($node->lineno);
            if (!\is_string($line)) {
                return $this->context;
            }
            if (\strpos($line, '{') === false
                || \strpos($line, '}') === false
            ) {
                $this->emitIssue(
                    Issue::CompatibleExpressionPHP7,
                    $node->lineno ?? 0,
                    "{$dollars}{$temp}[]"
                );
            }

        // $foo->$bar['baz'];
        } elseif ($expr->kind === \ast\AST_PROP &&
            ($expr->children['expr']->kind ?? null) === ast\AST_VAR &&
            ($expr->children['prop']->kind ?? null) === ast\AST_VAR
        ) {
            $cache_entry = FileCache::getOrReadEntry($this->context->getFile());
            $line = $cache_entry->getLines()[$node->lineno] ?? null;
            if (!\is_string($line)) {
                return $this->context;
            }
            if (\strpos($line, '{') === false
                || \strpos($line, '}') === false
            ) {
                $this->emitIssue(
                    Issue::CompatiblePHP7,
                    $node->lineno ?? 0
                );
            }
        }

        return $this->context;
    }

    /**
     * Add a constant to the codebase
     *
     * @param CodeBase $code_base
     * The global code base in which we store all
     * state
     *
     * @param Context $context
     * The context of the parser at the node which declares the constant
     *
     * @param int $lineno
     * The line number where the node declaring the constant was found
     *
     * @param string $name
     * The name of the constant
     *
     * @param Node|mixed $value
     * Either a node or a constant to be used as the value of
     * the constant.
     *
     * @param int $flags
     * Any flags on the definition of the constant
     *
     * @param string $comment_string
     * A possibly empty comment string on the declaration
     *
     * @param bool $use_future_union_type
     * Should this lazily resolve the value of the constant declaration?
     *
     * @param bool $is_fully_qualified
     * Is the provided $name already fully qualified?
     */
    public static function addConstant(
        CodeBase $code_base,
        Context $context,
        int $lineno,
        string $name,
        $value,
        int $flags,
        string $comment_string,
        bool $use_future_union_type,
        bool $is_fully_qualified = false
    ): void {
        $i = \strrpos($name, '\\');
        if ($i !== false) {
            $name_fragment = (string)\substr($name, $i + 1);
        } else {
            $name_fragment = $name;
        }
        if (\in_array(\strtolower($name_fragment), ['true', 'false', 'null'], true)) {
            Issue::maybeEmit(
                $code_base,
                $context,
                Issue::ReservedConstantName,
                $lineno,
                $name
            );
            return;
        }
        try {
            // Give it a fully-qualified name
            if ($is_fully_qualified) {
                $fqsen = FullyQualifiedGlobalConstantName::fromFullyQualifiedString(
                    $name
                );
            } else {
                $fqsen = FullyQualifiedGlobalConstantName::fromStringInContext(
                    $name,
                    $context
                );
            }
        } catch (InvalidArgumentException | FQSENException $_) {
            Issue::maybeEmit(
                $code_base,
                $context,
                Issue::InvalidConstantFQSEN,
                $lineno,
                $name
            );
            return;
        }

        // Create the constant
        $constant = new GlobalConstant(
            $context->withLineNumberStart($lineno),
            $name,
            // NOTE: With php 8.1 enums, global constants can be assigned to enums,
            // so this can be any valid type starting in php 8.1.
            UnionType::fromFullyQualifiedRealString('mixed'),
            $flags,
            $fqsen
        );
        // $is_fully_qualified is true for define('name', $value)
        // define() is typically used to conditionally set constants or to set them to variable values.
        // TODO: Could add 'configuration_constant_set' to add additional constants to treat as dynamic such as PHP_OS, PHP_VERSION_ID, etc. (convert literals to non-literal types?)
        $constant->setIsDynamicConstant($is_fully_qualified);

        if ($code_base->hasGlobalConstantWithFQSEN($fqsen)) {
            $other_constant = $code_base->getGlobalConstantByFQSEN($fqsen);
            $other_context = $other_constant->getContext();
            if (!$other_context->equals($context)) {
                // Be consistent about the constant's type and only track the first declaration seen when parsing (or redeclarations)
                // Note that global constants don't have alternates.
                return;
            }
            // Keep track of old references to the new constant
            $constant->copyReferencesFrom($other_constant);

            // Otherwise, add the constant now that we know about all of the elements in the codebase
        }

        // Get a comment on the declaration
        $comment = Comment::fromStringInContext(
            $comment_string,
            $code_base,
            $context,
            $lineno,
            Comment::ON_CONST
        );

        if ($use_future_union_type) {
            if ($value instanceof Node) {
                // TODO: Avoid using this when it only contains literals (nothing depending on the CodeBase),
                // e.g. `['key' => 'value']`
                $constant->setFutureUnionType(
                    new FutureUnionType(
                        $code_base,
                        $context,
                        $value
                    )
                );
            } else {
                $constant->setUnionType(Type::fromObject($value)->asRealUnionType());
            }
        } else {
            $constant->setUnionType(UnionTypeVisitor::unionTypeFromNode($code_base, $context, $value));
        }

        $constant->setNodeForValue($value);
        $constant->setDocComment($comment_string);

        $constant->setIsDeprecated($comment->isDeprecated());
        $constant->setIsNSInternal($comment->isNSInternal());

        $code_base->addGlobalConstant(
            $constant
        );
    }

    /**
     * @return Clazz
     * Get the class on this scope or fail real hard
     */
    private function getContextClass(): Clazz
    {
        // throws AssertionError if not in class scope
        return $this->context->getClassInScope($this->code_base);
    }

    /**
     * Return the existence of a class_alias from one FQSEN to the other.
     * Modifies $this->codebase if successful.
     *
     * Supports 'MyClass' and MyClass::class
     *
     * @param Node $node - An AST_CALL node with name 'class_alias' to attempt to resolve
     */
    private function recordClassAlias(Node $node): void
    {
        $args = $node->children['args']->children ?? [];
        if (\count($args) < 2 || \count($args) > 3) {
            return;
        }
        $code_base = $this->code_base;
        $context = $this->context;
        try {
            $original_fqsen = (new ContextNode($code_base, $context, $args[0]))->resolveClassNameInContext();
            $alias_fqsen = (new ContextNode($code_base, $context, $args[1]))->resolveClassNameInContext();
        } catch (IssueException $exception) {
            Issue::maybeEmitInstance(
                $code_base,
                $context,
                $exception->getIssueInstance()
            );
            return;
        }

        if ($original_fqsen === null || $alias_fqsen === null) {
            return;
        }

        // Add the class alias during parse phase.
        // Figure out if any of the aliases are wrong after analysis phase.
        $this->code_base->addClassAlias($original_fqsen, $alias_fqsen, $context, $node->lineno ?? 0);
    }

    /**
     * Visit a node with kind `\ast\AST_NAMESPACE`
     * Store the maps for use statements in the CodeBase to use later during analysis.
     *
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new context resulting from parsing the node
     */
    public function visitNamespace(Node $node): Context
    {
        $context = $this->context;
        // @phan-suppress-next-line PhanAccessMethodInternal addParsedNamespaceMap and getNamespaceMap
        $this->code_base->addParsedNamespaceMap($context->getFile(), $context->getNamespace(), $context->getNamespaceId(), $context->getNamespaceMap());
        return parent::visitNamespace($node);
    }

    // common no-ops
    public function visitArrayElem(Node $node): Context
    {
        return $this->context;
    }
    public function visitVar(Node $node): Context
    {
        return $this->context;
    }
    public function visitName(Node $node): Context
    {
        return $this->context;
    }
    public function visitCallableConvert(Node $node): Context
    {
        return $this->context;
    }
    public function visitArgList(Node $node): Context
    {
        return $this->context;
    }
    public function visitStmtList(Node $node): Context
    {
        return $this->context;
    }
    public function visitNullsafeProp(Node $node): Context
    {
        return $this->context;
    }
    public function visitProp(Node $node): Context
    {
        return $this->context;
    }
    public function visitArray(Node $node): Context
    {
        return $this->context;
    }
    public function visitBinaryOp(Node $node): Context
    {
        return $this->context;
    }

    /**
     * @internal
     */
    public const ALLOWED_CONST_EXPRESSION_KINDS = [
        ast\AST_ARRAY_ELEM => true,
        ast\AST_ARRAY => true,
        ast\AST_BINARY_OP => true,
        ast\AST_CLASS_CONST => true,
        ast\AST_CLASS_NAME => true,
        ast\AST_CONDITIONAL => true,
        ast\AST_CONST => true,
        ast\AST_DIM => true,
        ast\AST_MAGIC_CONST => true,
        ast\AST_NAME => true,
        ast\AST_UNARY_OP => true,
        ast\AST_UNPACK => true,
    ];

    /**
     * @internal
     */
    public const ALLOWED_CONST_EXPRESSION_KINDS_WITH_NEW = [
        ast\AST_ARRAY_ELEM => true,
        ast\AST_ARRAY => true,
        ast\AST_BINARY_OP => true,
        ast\AST_CLASS_CONST => true,
        ast\AST_CLASS_NAME => true,
        ast\AST_CONDITIONAL => true,
        ast\AST_CONST => true,
        ast\AST_DIM => true,
        ast\AST_MAGIC_CONST => true,
        ast\AST_NAME => true,
        ast\AST_UNARY_OP => true,
        ast\AST_UNPACK => true,

        ast\AST_NEW => true,
        ast\AST_ARG_LIST => true,
        ast\AST_NAMED_ARG => true,
    ];

    public const CONSTANT_EXPRESSION_IN_ATTRIBUTE = 1;
    public const CONSTANT_EXPRESSION_IN_PARAMETER = self::CONSTANT_EXPRESSION_IN_ATTRIBUTE;
    public const CONSTANT_EXPRESSION_IN_CONSTANT = self::CONSTANT_EXPRESSION_IN_ATTRIBUTE;
    public const CONSTANT_EXPRESSION_IN_STATIC_VARIABLE = self::CONSTANT_EXPRESSION_IN_ATTRIBUTE;

    public const CONSTANT_EXPRESSION_IN_CLASS_CONSTANT = 2;
    public const CONSTANT_EXPRESSION_IN_PROPERTY = self::CONSTANT_EXPRESSION_IN_CLASS_CONSTANT;
    public const CONSTANT_EXPRESSION_FORBID_NEW_EXPRESSION = self::CONSTANT_EXPRESSION_IN_CLASS_CONSTANT;

    /**
     * If the expression $node contains invalid AST kinds for a constant expression, then this warns.
     *
     * @param 1|2 $const_expr_context determines what ast node kinds can be used in a constant expression
     */
    public function checkNodeIsConstExprOrWarn(Node $node, int $const_expr_context): bool
    {
        try {
            self::checkIsAllowedInConstExpr($node, $const_expr_context);
            return true;
        } catch (InvalidArgumentException $e) {
            $this->emitIssue(
                Issue::InvalidConstantExpression,
                $node->lineno,
                $e->getMessage()
            );
            return false;
        }
    }

    /**
     * This is meant to avoid causing errors in Phan where Phan expects a constant to be found.
     *
     * @param Node|string|float|int|bool|null $n
     *
     * @return void - If this doesn't throw, then $n is a valid constant AST.
     *
     * @throws InvalidArgumentException if this is not allowed in a constant expression
     * Based on zend_bool zend_is_allowed_in_const_expr from Zend/zend_compile.c
     *
     * @internal
     */
    public static function checkIsAllowedInConstExpr($n, int $const_expr_context): void
    {
        if (!($n instanceof Node)) {
            return;
        }
        if (!\array_key_exists($n->kind, self::ALLOWED_CONST_EXPRESSION_KINDS)) {
            if ($const_expr_context === self::CONSTANT_EXPRESSION_IN_STATIC_VARIABLE && \array_key_exists($n->kind, self::ALLOWED_CONST_EXPRESSION_KINDS_WITH_NEW)) {
                if (Config::get_closest_minimum_target_php_version_id() < 80100) {
                    throw new InvalidArgumentException(ASTReverter::toShortString($n) . " (new expression requires minimum_target_php_version >= '8.1')");
                }
            } else {
                throw new InvalidArgumentException(ASTReverter::toShortString($n));
            }
        }
        foreach ($n->children as $child_node) {
            self::checkIsAllowedInConstExpr($child_node, $const_expr_context);
        }
    }

    /**
     * @param Node|string|float|int|bool|null $n
     * @param 1|2 $const_expr_context
     * @param string &$error_message $error_message @phan-output-reference
     * @return bool - If true, then $n is a valid constant AST.
     */
    public static function isConstExpr($n, int $const_expr_context, string &$error_message = ''): bool
    {
        try {
            self::checkIsAllowedInConstExpr($n, $const_expr_context);
            return true;
        } catch (InvalidArgumentException $e) {
            $error_message = $e->getMessage();
            return false;
        }
    }

    protected const ALLOWED_NON_VARIABLE_EXPRESSION_KINDS = [
        // Contains everything from ALLOWED_CONST_EXPRESSION_KINDS
        ast\AST_ARRAY_ELEM => true,
        ast\AST_ARRAY => true,
        ast\AST_BINARY_OP => true,
        ast\AST_CLASS_CONST => true,
        ast\AST_CLASS_NAME => true,
        ast\AST_CONDITIONAL => true,
        ast\AST_CONST => true,
        ast\AST_DIM => true,
        ast\AST_MAGIC_CONST => true,
        ast\AST_NAME => true,
        ast\AST_UNARY_OP => true,

        // In addition to expressions where the real type can be statically inferred (assuming types of child nodes were correctly inferred)
        ast\AST_ARG_LIST => true,
        ast\AST_CALL => true,
        ast\AST_CLONE => true,
        ast\AST_EMPTY => true,
        ast\AST_ISSET => true,
        ast\AST_NEW => true,
        ast\AST_PRINT => true,
        ast\AST_SHELL_EXEC => true,
        ast\AST_STATIC_CALL => true,
        ast\AST_STATIC_PROP => true,
        ast\AST_UNPACK => true,

        // Stop here
        ast\AST_CLOSURE => false,
        ast\AST_CLASS => false,
    ];

    /**
     * This is meant to tell Phan expects an expression not depending on the current scope (e.g. global, loop) to be found.
     *
     * @param Node|string|float|int|bool|null $n
     *
     * @return void - If this doesn't throw, then $n is a valid constant AST.
     *
     * @throws InvalidArgumentException if this is not allowed in a constant expression
     * Based on zend_bool zend_is_allowed_in_const_expr from Zend/zend_compile.c
     *
     * @internal
     */
    private static function checkIsNonVariableExpression($n): void
    {
        if (!($n instanceof Node)) {
            return;
        }
        $value = self::ALLOWED_NON_VARIABLE_EXPRESSION_KINDS[$n->kind] ?? null;
        if ($value === true) {
            foreach ($n->children as $child_node) {
                self::checkIsNonVariableExpression($child_node);
            }
            return;
        }
        if ($value !== false) {
            throw new InvalidArgumentException();
        }
        // Skip checking child nodes for anonymous classes, closures
    }

    /**
     * @param Node|string|float|int|bool|null $n
     * @return bool - If true, then the inferred type for $n does not depend on the current scope, but isn't necessarily constant (e.g. static method invocation in loop, global)
     */
    public static function isNonVariableExpr($n): bool
    {
        try {
            self::checkIsNonVariableExpression($n);
            return true;
        } catch (InvalidArgumentException $_) {
            return false;
        }
    }
}
