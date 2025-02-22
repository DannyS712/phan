<?php

declare(strict_types=1);

namespace Phan\Tests\Language;

use Phan\Config;
use Phan\Language\Context;
use Phan\Language\Type;
use Phan\Language\Type\ArrayShapeType;
use Phan\Language\Type\ArrayType;
use Phan\Language\Type\BoolType;
use Phan\Language\Type\CallableDeclarationType;
use Phan\Language\Type\CallableStringType;
use Phan\Language\Type\CallableType;
use Phan\Language\Type\ClassStringType;
use Phan\Language\Type\ClosureDeclarationParameter;
use Phan\Language\Type\ClosureDeclarationType;
use Phan\Language\Type\ClosureType;
use Phan\Language\Type\FalseType;
use Phan\Language\Type\FloatType;
use Phan\Language\Type\FunctionLikeDeclarationType;
use Phan\Language\Type\GenericArrayType;
use Phan\Language\Type\GenericIterableType;
use Phan\Language\Type\IntType;
use Phan\Language\Type\IterableType;
use Phan\Language\Type\LiteralFloatType;
use Phan\Language\Type\LiteralIntType;
use Phan\Language\Type\LiteralStringType;
use Phan\Language\Type\MixedType;
use Phan\Language\Type\NeverType;
use Phan\Language\Type\NonEmptyMixedType;
use Phan\Language\Type\NonNullMixedType;
use Phan\Language\Type\ObjectType;
use Phan\Language\Type\ResourceType;
use Phan\Language\Type\StaticType;
use Phan\Language\Type\StringType;
use Phan\Language\Type\TrueType;
use Phan\Language\Type\VoidType;
use Phan\Language\UnionType;
use Phan\Tests\CodeBaseAwareTest;

use function get_class;

/**
 * Unit tests of Type
 * @phan-file-suppress PhanThrowTypeAbsentForCall
 */
final class TypeTest extends CodeBaseAwareTest
{
    private function makePHPDocType(string $type_string): Type
    {
        $this->assertRegExp('@^' . Type::type_regex_or_this . '$@', $type_string, "Failed to parse '$type_string'");
        return Type::fromStringInContext($type_string, new Context(), Type::FROM_PHPDOC);
    }

    public function testBracketedTypes(): void
    {
        $this->assertParsesAsType(ArrayType::instance(false), '(array)');
        $this->assertParsesAsType(ArrayType::instance(false), '((array))');
    }

    private const DELIMITED_TYPE_REGEX_OR_THIS = '@^' . Type::type_regex_or_this . '$@';

    /**
     * Assert that all of $type_string is parseable as a single Type, and that that type is $expected_type.
     */
    public function assertParsesAsType(Type $expected_type, string $type_string): void
    {
        $this->assertRegExp(self::DELIMITED_TYPE_REGEX_OR_THIS, $type_string, "Failed to parse '$type_string'");
        $this->assertSameType($expected_type, self::makePHPDocType($type_string));
    }

    public function testBasicTypes(): void
    {
        $this->assertParsesAsType(ArrayType::instance(false), 'array');
        $this->assertParsesAsType(ArrayType::instance(true), '?array');
        $this->assertParsesAsType(ArrayType::instance(true), '?ARRAY');
        $this->assertParsesAsType(BoolType::instance(false), 'bool');
        $this->assertParsesAsType(CallableType::instance(false), 'callable');
        $this->assertParsesAsType(ClosureType::instance(false), 'Closure');
        $this->assertParsesAsType(FalseType::instance(false), 'false');
        $this->assertParsesAsType(FloatType::instance(false), 'float');
        $this->assertParsesAsType(IntType::instance(false), 'int');
        $this->assertParsesAsType(IterableType::instance(false), 'iterable');
        $this->assertParsesAsType(MixedType::instance(false), 'mixed');
        $this->assertParsesAsType(NonEmptyMixedType::instance(false), 'non-empty-mixed');
        $this->assertParsesAsType(NonNullMixedType::instance(false), 'non-null-mixed');
        $this->assertParsesAsType(ObjectType::instance(false), 'object');
        $this->assertParsesAsType(ResourceType::instance(false), 'resource');
        $this->assertParsesAsType(StaticType::instance(false), 'static');
        $this->assertParsesAsType(StringType::instance(false), 'string');
        $this->assertParsesAsType(TrueType::instance(false), 'true');
        $this->assertParsesAsType(VoidType::instance(false), 'void');
    }

    public function testLiteralIntType(): void
    {
        $this->assertParsesAsType(LiteralIntType::instanceForValue(1, false), '1');
        $this->assertParsesAsType(LiteralIntType::instanceForValue(0, false), '0');
        $this->assertParsesAsType(LiteralIntType::instanceForValue(0, false), '-0');
        $this->assertParsesAsType(LiteralIntType::instanceForValue(-1, false), '-1');
        $this->assertParsesAsType(LiteralIntType::instanceForValue(9, false), '9');
        $this->assertParsesAsType(LiteralIntType::instanceForValue(190, false), '190');
        $this->assertParsesAsType(LiteralFloatType::instanceForValue(1111111111111111111111111111111111, false), '1111111111111111111111111111111111');
        $this->assertParsesAsType(LiteralFloatType::instanceForValue(-1.5, false), '-1.5');
        $this->assertParsesAsType(LiteralFloatType::instanceForValue(0.0, false), '0.0');
        $this->assertParsesAsType(LiteralFloatType::instanceForValue(0.25, false), '0.25');
        $this->assertParsesAsType(LiteralFloatType::instanceForValue(0.25, true), '?0.25');
        $this->assertParsesAsType(LiteralIntType::instanceForValue(1, true), '?1');
        $this->assertParsesAsType(LiteralIntType::instanceForValue(-1, true), '?-1');
    }

    public function testLiteralStringType(): void
    {
        $this->assertParsesAsType(LiteralStringType::instanceForValue('a', false), "'a'");
        $this->assertParsesAsType(LiteralStringType::instanceForValue('a', true), "?'a'");
        $this->assertParsesAsType(LiteralStringType::instanceForValue('', false), "''");
        $this->assertParsesAsType(LiteralStringType::instanceForValue('', true), "?''");
        $this->assertParsesAsType(LiteralStringType::instanceForValue('\\', false), "'\\\\'");
        $this->assertParsesAsType(LiteralStringType::instanceForValue("'", false), "'\\''");
        $this->assertParsesAsType(LiteralStringType::instanceForValue('0', false), "'0'");
        $this->assertParsesAsType(LiteralStringType::instanceForValue('abcdefghijklmnopqrstuvwxyz01234567889-,./?:;!#$%^&*_-=+', false), "'abcdefghijklmnopqrstuvwxyz01234567889-,./?:;!#\$%^&*_-=+'");

        $this->assertParsesAsType(LiteralStringType::instanceForValue("<=>\n", false), "'\\x3c\\x3d\\x3e\\x0a'");
    }

    private function assertSameType(Type $expected, Type $actual, string $extra = ''): void
    {
        $message = \sprintf("Expected %s to be %s", (string)$actual, (string)$expected);
        if ($extra !== '') {
            $message .= ": $extra";
        }
        $this->assertEquals($expected, $actual, $message);  // assertEquals has more useful formatting
        $this->assertSame($expected, $actual, $message);
    }

    public function testUnionTypeOfThis(): void
    {
        $this->assertParsesAsType(StaticType::instance(false), '$this');
        $this->assertParsesAsType(StaticType::instance(true), '?$this');
    }

    public function testGenericArray(): void
    {
        $generic_array_type = self::makePHPDocType('int[][]');
        $expected_generic_array_type = self::createGenericArrayTypeWithMixedKey(
            self::createGenericArrayTypeWithMixedKey(
                IntType::instance(false),
                false
            ),
            false
        );
        $this->assertSameType($expected_generic_array_type, $generic_array_type);
        $this->assertSame('int[][]', (string)$expected_generic_array_type);
        $this->assertSameType($expected_generic_array_type, self::makePHPDocType('(int)[][]'));
        $this->assertSameType($expected_generic_array_type, self::makePHPDocType('((int)[])[]'));
    }

    public function testTemplateTypes(): void
    {
        $type = self::makePHPDocType('TypeTestClass<A1,B2>');
        $this->assertSame('\\', $type->getNamespace());
        $this->assertSame('TypeTestClass', $type->getName());
        $parts = $type->getTemplateParameterTypeList();
        $this->assertCount(2, $parts);
        $this->assertTrue($parts[0]->isType(self::makePHPDocType('A1')));
        $this->assertTrue($parts[1]->isType(self::makePHPDocType('B2')));
    }

    public function testTemplateTypesWithArray(): void
    {
        $type = self::makePHPDocType('TypeTestClass<array<string>,array<int>>');  // not exactly a template, but has the same parsing
        $this->assertSame('\\', $type->getNamespace());
        $this->assertSame('TypeTestClass', $type->getName());
        $parts = $type->getTemplateParameterTypeList();
        $this->assertCount(2, $parts);
        $this->assertTrue($parts[0]->isType(self::makePHPDocType('string[]')));
        $this->assertTrue($parts[1]->isType(self::makePHPDocType('int[]')));
    }

    public function testTemplateTypesWithTemplates(): void
    {
        $type = self::makePHPDocType('TypeTestClass<T1<int,string[]>,T2>');  // not exactly a template, but has the same parsing
        $this->assertSame('\\', $type->getNamespace());
        $this->assertSame('TypeTestClass', $type->getName());
        $parts = $type->getTemplateParameterTypeList();
        $this->assertCount(2, $parts);
        $this->assertTrue($parts[0]->isType(self::makePHPDocType('T1<int,string[]>')), "Unexpected value for " . (string)$parts[0]);
        $this->assertTrue($parts[1]->isType(self::makePHPDocType('T2')));
        $inner_parts = $parts[0]->getTypeSet()[0]->getTemplateParameterTypeList();
        $this->assertCount(2, $inner_parts);
        $this->assertTrue($inner_parts[0]->isType(self::makePHPDocType('int')));
        $this->assertTrue($inner_parts[1]->isType(self::makePHPDocType('string[]')));
    }

    public function testTemplateTypesWithNullable(): void
    {
        $type = self::makePHPDocType('TypeTestClass<' . '?int,?string>');  // not exactly a template, but has the same parsing
        $this->assertSame('\\', $type->getNamespace());
        $this->assertSame('TypeTestClass', $type->getName());
        $parts = $type->getTemplateParameterTypeList();
        $this->assertCount(2, $parts);
        $this->assertTrue($parts[0]->isType(self::makePHPDocType('?int')), "Unexpected value for " . (string)$parts[0]);
        $this->assertTrue($parts[1]->isType(self::makePHPDocType('?string')));
    }

    public function testIterableType(): void
    {
        $iterable_type = self::makePHPDocType('iterable');
        $iterable_template_type = self::makePHPDocType('iterable<string,int>');
        $traversable_type = self::makePHPDocType('Traversable');
        $spl_object_storage_type = self::makePHPDocType('SplObjectStorage');
        $this->assertCanCastToType($iterable_type, $iterable_template_type);
        $this->assertCanCastToType($iterable_template_type, $iterable_type);
        $this->assertCanCastToType($traversable_type, $iterable_type);
        $this->assertCanCastToType($traversable_type, $iterable_template_type);
        $this->assertTrue($traversable_type->isIterable($this->code_base));
        $this->assertTrue($spl_object_storage_type->isIterable($this->code_base));
        $this->assertCanCastToType($spl_object_storage_type, $iterable_type);
        $this->assertCanCastToType($spl_object_storage_type, $iterable_template_type);
    }

    /**
     * Regression test - Phan parses ?int[] as ?(int[])
     */
    public function testGenericArrayNullable(): void
    {
        $generic_array_type = self::makePHPDocType('?int[]');
        $expected_generic_array_type = self::createGenericArrayTypeWithMixedKey(
            IntType::instance(false),
            true
        );
        $this->assertSameType($expected_generic_array_type, $generic_array_type);
        $generic_array_array_type = self::makePHPDocType('?int[][]');
        $expected_generic_array_array_type = self::createGenericArrayTypeWithMixedKey(
            self::createGenericArrayTypeWithMixedKey(
                IntType::instance(false),
                false
            ),
            true
        );
        $this->assertSameType($expected_generic_array_array_type, $generic_array_array_type);
    }

    public function testIterable(): void
    {
        $string_iterable_type = self::makePHPDocType('iterable<string>');
        $expected_string_iterable_type = GenericIterableType::fromKeyAndValueTypes(
            UnionType::empty(),
            StringType::instance(false)->asPHPDocUnionType(),
            false
        );
        $this->assertSameType($expected_string_iterable_type, $string_iterable_type);

        $string_to_stdclass_array_type = self::makePHPDocType('iterable<string,stdClass>');
        $expectedstring_to_std_class_array_type = GenericIterableType::fromKeyAndValueTypes(
            StringType::instance(false)->asPHPDocUnionType(),
            UnionType::fromFullyQualifiedPHPDocString('\stdClass'),
            false
        );
        $this->assertSameType($expectedstring_to_std_class_array_type, $string_to_stdclass_array_type);
    }

    public function testArrayAlternate(): void
    {
        $string_array_type = self::makePHPDocType('array<string>');
        $expected_string_array_type = self::createGenericArrayTypeWithMixedKey(
            StringType::instance(false),
            false
        );
        $this->assertSameType($expected_string_array_type, $string_array_type);

        $string_array_type2 = self::makePHPDocType('array<mixed,string>');
        $this->assertSameType($expected_string_array_type, $string_array_type2);

        // We track key types.
        $expected_string_array_type_with_int_key = GenericArrayType::fromElementType(
            StringType::instance(false),
            false,
            GenericArrayType::KEY_INT
        );
        $string_array_type3 = self::makePHPDocType('array<int,string>');
        $this->assertSameType($expected_string_array_type_with_int_key, $string_array_type3);

        // Allow space
        $string_array_type4 = self::makePHPDocType('array<mixed, string>');
        $this->assertSameType($expected_string_array_type, $string_array_type4);

        // Combination of int|string in array key results in mixed key
        $string_array_type5 = self::makePHPDocType('array<int|string, string>');
        $this->assertSameType($expected_string_array_type, $string_array_type5);

        // Nested array types.
        $expected_string_array_array_type = self::createGenericArrayTypeWithMixedKey(
            $expected_string_array_type,
            false
        );
        $this->assertParsesAsType($expected_string_array_array_type, 'array<string[]>');
        $this->assertParsesAsType($expected_string_array_array_type, 'array<string>[]');
        $this->assertParsesAsType($expected_string_array_array_type, 'array<array<string>>');
        $this->assertParsesAsType($expected_string_array_array_type, 'array<mixed,array<mixed,string>>');
    }

    public function testArrayNested(): void
    {
        $deeply_nested_array = self::makePHPDocType('array<int,array<mixed,array<mixed,stdClass>>>');
        $this->assertSame('array<int,\stdClass[][]>', (string)$deeply_nested_array);
    }

    public function testArrayExtraBrackets(): void
    {
        $string_array_type = self::makePHPDocType('?(float[])');
        $expected_string_array_type = self::createGenericArrayTypeWithMixedKey(
            FloatType::instance(false),
            true
        );
        $this->assertSameType($expected_string_array_type, $string_array_type);
        $this->assertSame('?float[]', (string)$string_array_type);
    }

    public function testArrayExtraBracketsForElement(): void
    {
        $string_array_type = self::makePHPDocType('(?float)[]');
        $expected_string_array_type = self::createGenericArrayTypeWithMixedKey(
            FloatType::instance(true),
            false
        );
        $this->assertSameType($expected_string_array_type, $string_array_type);
        $this->assertSame('(?float)[]', (string)$string_array_type);
    }

    public function testArrayExtraBracketsAfterNullable(): void
    {
        $string_array_type = self::makePHPDocType('?(float)[]');
        $expected_string_array_type = self::createGenericArrayTypeWithMixedKey(
            FloatType::instance(false),
            true
        );
        $this->assertSameType($expected_string_array_type, $string_array_type);
        $this->assertSame('?float[]', (string)$string_array_type);
    }

    private static function makeBasicClosureParam(string $type_string, ?string$name): ClosureDeclarationParameter
    {
        // is_variadic, is_reference, is_optional
        return new ClosureDeclarationParameter(
            UnionType::fromFullyQualifiedPHPDocString($type_string),
            false,
            false,
            false,
            $name
        );
    }

    private function verifyClosureParam(FunctionLikeDeclarationType $expected_closure_type, string $union_type_string, string $normalized_type_string): void
    {
        $this->assertRegExp(self::DELIMITED_TYPE_REGEX_OR_THIS, $union_type_string, "Failed to parse '$union_type_string'");
        $parsed_closure_type = self::makePHPDocType($union_type_string);
        $this->assertSame(get_class($expected_closure_type), get_class($parsed_closure_type), "expected closure/callable class for $normalized_type_string");
        $this->assertSame($normalized_type_string, (string)$parsed_closure_type, "failed parsing $union_type_string");
        $this->assertSame($normalized_type_string, (string)$expected_closure_type, "Bad precondition for $expected_closure_type");
        $this->assertTrue($expected_closure_type->canCastToType($parsed_closure_type, $this->code_base), "failed casting $union_type_string");
        $this->assertTrue($parsed_closure_type->canCastToType($expected_closure_type, $this->code_base), "failed casting $union_type_string");
    }

    public function testClosureAnnotation(): void
    {
        $expected_closure_void_type = new ClosureDeclarationType(
            new Context(),
            [],
            VoidType::instance(false)->asPHPDocUnionType(),
            false,
            false
        );
        foreach (['Closure():void', 'Closure()'] as $union_type_string) {
            $this->verifyClosureParam($expected_closure_void_type, $union_type_string, 'Closure():void');
        }
    }

    public function testCallableAnnotation(): void
    {
        $make_type = static function (?string $param_name): CallableDeclarationType {
            return new CallableDeclarationType(
                new Context(),
                [self::makeBasicClosureParam('string', $param_name)],
                IntType::instance(false)->asPHPDocUnionType(),
                false,
                false
            );
        };
        foreach (['callable(string):int' => $make_type(null), 'callable(string $x):int' => $make_type('x')] as $union_type_string => $expected_closure_type) {
            $this->verifyClosureParam($expected_closure_type, $union_type_string, $union_type_string);
        }
    }

    public function testNullableCallableAnnotation(): void
    {
        $make_type = static function (?string $name): CallableDeclarationType {
            return new CallableDeclarationType(
                new Context(),
                [self::makeBasicClosureParam('string', $name)],
                VoidType::instance(false)->asPHPDocUnionType(),
                false,
                true
            );
        };
        foreach (['?callable(string):void' => $make_type(null), '?callable(string $x)' => $make_type('x')] as $union_type_string => $expected_closure_type) {
            $this->verifyClosureParam($expected_closure_type, $union_type_string, \explode(':', $union_type_string)[0] . ':void');
        }
    }

    public function testClosureBasicAnnotation(): void
    {
        $expected_closure_type = new ClosureDeclarationType(
            new Context(),
            [self::makeBasicClosureParam('int', null), self::makeBasicClosureParam('mixed', null)],
            IntType::instance(false)->asPHPDocUnionType(),
            false,
            false
        );
        foreach (['Closure(int,mixed):int', '\Closure(int,mixed):int'] as $union_type_string) {
            $this->verifyClosureParam($expected_closure_type, $union_type_string, 'Closure(int,mixed):int');
        }
    }

    public function testClosureUnionAnnotation(): void
    {
        $make_closure_scalar_type = static function (?string $name): ClosureDeclarationType {
            return new ClosureDeclarationType(
                new Context(),
                [self::makeBasicClosureParam('?int|?string', $name)],
                UnionType::fromFullyQualifiedPHPDocString('?int|?string'),
                false,
                false
            );
        };
        $this->verifyClosureParam($make_closure_scalar_type(null), 'Closure(?int|?string):(?int|?string)', 'Closure(?int|?string):(?int|?string)');
        $this->verifyClosureParam($make_closure_scalar_type('argName'), 'Closure(?int|?string $argName) : (?int|?string)', 'Closure(?int|?string $argName):(?int|?string)');
    }

    public function testClosureRefVariadicAnnotations(): void
    {
        // is_variadic, is_reference, is_optional
        $string_ref_annotation = new ClosureDeclarationParameter(UnionType::fromFullyQualifiedPHPDocString('string'), false, true, false);
        $variadic_bool_annotation = new ClosureDeclarationParameter(UnionType::fromFullyQualifiedPHPDocString('bool'), true, true, false);

        $expected_closure_type = new ClosureDeclarationType(
            new Context(),
            [$string_ref_annotation, $variadic_bool_annotation],
            UnionType::fromFullyQualifiedPHPDocString('void'),
            false,
            false
        );
        $this->verifyClosureParam($expected_closure_type, 'Closure(string&,bool&...)', 'Closure(string&,bool&...):void');
    }

    public function testClosureOptionalParam(): void
    {
        // is_variadic, is_reference, is_optional
        $optional_string_annotation = new ClosureDeclarationParameter(UnionType::fromFullyQualifiedPHPDocString('?string'), false, false, true);
        $optional_int_annotation = new ClosureDeclarationParameter(UnionType::fromFullyQualifiedPHPDocString('int'), false, false, true);

        $expected_closure_type = new ClosureDeclarationType(
            new Context(),
            [$optional_string_annotation, $optional_int_annotation],
            UnionType::fromFullyQualifiedPHPDocString('void'),
            false,
            false
        );
        $this->verifyClosureParam($expected_closure_type, 'Closure(?string=,int=)', 'Closure(?string=,int=):void');
    }

    /**
     * @dataProvider canCastToTypeProvider
     */
    public function testCanCastToType(string $from_type_string, string $to_type_string): void
    {
        $from_type = self::makePHPDocType($from_type_string);
        $to_type = self::makePHPDocType($to_type_string);
        $this->assertTrue($from_type->canCastToType($to_type, $this->code_base), "expected $from_type_string to be able to cast to $to_type_string");
    }

    /** @return list<list> */
    public function canCastToTypeProvider(): array
    {
        return [
            ['int', 'int'],
            ['1', 'int'],
            ['int', '1'],
            ['1', '?int'],
            ['?1', '?int'],
            ['?string', "?''"],
            ["?''", '?string'],
            ["''", '?string'],
            ["?'a string'", '?string'],
            ["?'a string'", "?'a string'"],
            ['int', 'float'],
            ['int', 'mixed'],
            ['ArrayObject', 'Countable'],
            ['ArrayObject', 'ArrayObject'],
            ['ArrayObject', 'iterable'],
            ['ArrayObject', '?iterable'],
            ['ArrayObject', '?Countable'],
            ['?ArrayObject', '?Countable'],
            ['?Exception', '?Throwable'],
            ['mixed', 'int'],
            ['null', 'mixed'],
            ['null[]', 'mixed[]'],
            ['?Closure(int):int', '?Closure'],
            ['?Closure(int):int', '?callable'],
            ['?Closure', '?Closure(int):int'],
            ['?Closure(\'0,2\'):int', '?Closure(string):int'],
            ['?callable(int):int', '?callable'],
            ['?callable', '?callable(int):int'],
            ['\'literal\'', 'string'],
        ];
    }

    /**
     * @dataProvider cannotCastToTypeProvider
     */
    public function testCannotCastToType(string $from_type_string, string $to_type_string): void
    {
        $from_type = self::makePHPDocType($from_type_string);
        $to_type = self::makePHPDocType($to_type_string);
        $this->assertFalse($from_type->canCastToType($to_type, $this->code_base), "expected $from_type_string to be unable to cast to $to_type_string");
    }

    /** @return list<list> */
    public function cannotCastToTypeProvider(): array
    {
        return [
            ['?int', 'int'],
            ['?1', 'int'],
            ['?int', '1'],
            ['0', '1'],
            ['float', 'int'],
            ['callable', 'Closure'],
            ['?Closure(int):int', '?Closure(int):void'],
            ['?Closure(int):int', 'Closure(int):int'],
            ['?Closure(int):int', '?Closure(string):int'],
            ['?Closure(int):int', '?Closure(\'0,2\'):int'],
            ['?Closure(\'0,2\',\'other\'):int', '?Closure(string):int'],
            ['?callable(int):int', '?callable(int):void'],
            ['?callable(int):int', 'callable(int):int'],
            ['?callable(int):int', '?callable(string):int'],

            ['?float', 'float'],
            ['?string', 'string'],
            ['?bool', 'bool'],
            ['?true', 'true'],
            ['?true', 'bool'],
            ['?false', 'false'],
            ['?object', 'object'],
            ['?iterable', 'iterable'],
            ['?array', 'array'],
            ['Countable', 'ArrayObject'],
            ['iterable', 'ArrayObject'],
            ['?iterable', '?ArrayObject'],
            ['?Countable', '?ArrayObject'],
            ['\'literal\'', '?int'],
            // not sure about desired semantics of ['?mixed', 'mixed'],
        ];
    }

    /** @return list<list> */
    public function declaredTypeProvider(): array
    {
        return [
            [false, "'literal'", '?int'],
            [true, "'literal'", '?string'],
            [true, "'literal'", 'mixed'],
        ];
    }

    /**
     * @dataProvider declaredTypeProvider
     */
    public function testCanCastToDeclaredType(bool $expected, string $from_type_string, string $to_type_string): void
    {
        $from_type = self::makePHPDocType($from_type_string);
        $to_type = self::makePHPDocType($to_type_string);
        $this->assertSame($expected, $from_type->canCastToDeclaredType($this->code_base, new Context(), $to_type), "unexpected canCastToDeclaredType result for $from_type_string to $to_type_string");
    }

    /** @return list<array{0:bool, 1: string, 2: string, 3?: bool}> */
    public function isSubtypeOfProvider(): array
    {
        return [
            [false, 'ArrayObject', 'stdClass'],
            [false, 'stdClass', 'iterable'],
            [true, 'ArrayObject', 'iterable'],
            [true, 'non-null-mixed', 'mixed'],
            [true, 'non-empty-mixed', 'non-null-mixed'],
            [false, 'false', 'true'],
            [true, 'false', 'bool'],
            [true, 'true', 'bool'],
            [true, 'false', '?bool'],
            [true, "Closure(int):int", 'Closure'],
            [false, "Closure(int):int", 'Closure(string):int'],
            [true, 'never', 'Closure(int):int'],
            [true, 'never', 'null'],
            [true, 'ArrayObject<int>', 'ArrayObject'],
            [false, 'ArrayObject<int>', 'ArrayObject<string>'],
            [false, 'Traversable<int,int>', 'Traversable<string,int>'],
            [true, 'iterable<string>', 'iterable'],
            [true, 'array{}', 'array'],
            [true, 'string[]', 'array'],
            [true, 'array{}', 'iterable'],
            [true, 'iterable<string>', 'iterable<mixed>'],
            [true, 'array{foo:ArrayObject}', 'array{foo:Traversable}'],
            [true, 'array{foo:ArrayObject}', 'non-empty-array'],
            [false, 'array{foo:ArrayObject}', 'non-empty-list'],
            [false, 'array{foo:ArrayObject}', 'list'],
            [true, 'array{}', 'list'],
            [true, 'array{0:string}', 'list'],
            [true, 'array{0:string}', 'list<string>'],
            [true, 'array{0:string, 1?:string}', 'list<string>'],
            [false, 'array{1:string}', 'list<string>'],
            [false, 'array{0?:string,1:string}', 'list<string>'],
            [true, 'array{}', 'associative-array'],
            [false, 'list', 'associative-array'],
            [false, 'array{foo:ArrayObject}', 'array<int, ArrayObject>'],
            [true, 'array{foo:ArrayObject}', 'array<string, ArrayObject>'],
            [true, 'array{foo:ArrayObject}', 'non-empty-array<ArrayObject>'],
            [true, 'array{foo:ArrayObject}', 'non-empty-array<Traversable>'],
            [true, 'array{foo:ArrayObject}', 'non-empty-associative-array<ArrayObject>'],
            [false, 'array{foo:ArrayObject}', 'non-empty-associative-array<int, ArrayObject>'],
            [false, 'array{foo:ArrayObject}', 'associative-array<int, ArrayObject>'],
            [false, 'array{foo:ArrayObject}', 'array<int, ArrayObject>'],
            [true, 'array{foo:ArrayObject}', 'ArrayObject[]'],
            [true, 'array{foo:ArrayObject}', 'array<Traversable>'],
            [true, 'array{foo:ArrayObject}', 'array{}'],
            [false, 'array{foo:ArrayObject}', 'array{foo:SplObjectStorage}'],
            [true, 'callable-object', 'callable'],
            [true, 'callable-object', 'object'],
            [true, 'callable-object', 'mixed'],
            [true, 'Closure(int):string', 'callable-object'],
            [false, 'Closure(int):string', 'callable-string'],
            [false, 'Closure(int):string', 'callable-array'],
            [true, 'Closure', 'callable-object'],
            [false, 'Closure', 'callable-array'],
            [false, 'Closure', 'callable-string'],
            [true, 'Closure', 'callable'],
            [true, 'stdClass', 'object'],
            [true, 'Closure', 'object'],
            [false, 'Closure', 'iterable'],
            [false, 'stdClass', 'callable-object'],
            [true, 'callable-string', 'string'],
            [true, 'callable-string', 'callable'],
            [false, 'callable-string', 'callable-object'],
            [false, 'callable-string', 'callable-array'],
            [false, 'callable-array', 'callable-string'],
            [false, 'callable-array', 'string[]', true],
            [false, 'string[]', 'callable-array', true],
            [true, 'callable-array', 'callable'],
            [true, 'callable-array', 'array'],
            [false, 'callable', 'string'],
            [false, 'string', 'callable', true],
            [true, "'literal'", 'string'],
            [true, "'literal'", 'non-empty-string'],
            [true, "''", 'string'],
            [true, "'is_string'", 'callable-string'],
            [true, "'foo0\\\\some_fn'", 'callable-string'],
            [false, "'foo0\\\\\\\\some_fn'", 'callable-string'],
            [false, "'1a'", 'callable-string'],
            [true, "'A0::b0'", 'callable-string'],
            [false, "'A0:::b0'", 'callable-string'],
            [false, "'not callable'", 'callable-string'],
            [false, "''", 'non-empty-string'],
            [true, '1', 'int'],
            [true, '1', 'non-zero-int'],
            [true, 'non-zero-int', 'int'],
            [true, 'non-zero-int', 'scalar'],
            [false, '0', 'non-zero-int'],
            [true, '1', 'non-zero-int'],
            [true, '1.0', 'float'],
            [false, '1.0', 'int'],
            [true, 'non-empty-array', 'array'],
            [true, 'non-empty-array<string>', 'array<string>'],
            [true, 'non-empty-list<string>', 'non-empty-array<string>'],
            [true, 'non-empty-list<string>', 'list<string>'],
            [true, 'non-empty-array<ArrayObject>', 'array<object>'],
            [false, 'non-empty-array<ArrayObject>', 'array<string>'],
        ];
    }

    /**
     * @dataProvider isSubtypeOfProvider
     * @suppress PhanAccessMethodInternal
     */
    public function testIsSubtypeOf(bool $expected, string $from_type_string, string $to_type_string, bool $only_check_subtype = false): void
    {
        $code_base = $this->code_base;
        $from_type = self::makePHPDocType($from_type_string);
        $to_type = self::makePHPDocType($to_type_string);
        $this->assertSame($expected, $from_type->isSubtypeOf($to_type, $code_base), "unexpected result for $from_type_string isSubtypeOf $to_type_string");
        if (!$only_check_subtype) {
            $this->assertSame($expected, $from_type->canCastToType($to_type, $code_base), "unexpected result for $from_type_string canCastToType $to_type_string");
            $this->assertSame($expected, $from_type->canCastToTypeWithoutConfig($to_type, $code_base), "unexpected result for $from_type_string canCastToTypeWithoutConfig $to_type_string");
        }
        if ($expected) {
            $this->assertFalse($to_type->isSubtypeOf($from_type, $code_base), "unexpected result for $to_type_string isSubtypeOf $from_type_string");

            $this->assertTrue($to_type instanceof NeverType || $from_type->canCastToDeclaredType($code_base, new Context(), $to_type), "unexpected result for $from_type_string canCastToDeclaredType $to_type_string");
            $this->assertTrue($from_type instanceof NeverType || $to_type->canCastToDeclaredType($code_base, new Context(), $from_type), "unexpected result for $to_type_string canCastToDeclaredType $from_type_string");
            $this->assertTrue($to_type instanceof NeverType || $from_type->weaklyOverlaps($to_type, $code_base), "unexpected result for $from_type_string weaklyOverlaps $to_type_string");
            $this->assertTrue($from_type instanceof NeverType || $to_type->weaklyOverlaps($from_type, $code_base), "unexpected result for $to_type_string weaklyOverlaps $from_type_string");
        }
    }

    /** @return list<array{0: string, 1: string}> */
    public function nonWeakOverlappingTypeProvider(): array
    {
        return [
            ['ArrayObject', 'stdClass'],
            // ['non-null-mixed', 'null'], // null == false
            ['non-empty-mixed', 'void'],
            ['non-empty-mixed', 'null'],
            ['false', 'non-empty-array'],
            ['null', 'true'],
            ["'a'", "'b'"],
            ["''", '1'],
            ['callable', 'null'],
            ['resource', 'null'],
            ['object', 'array'],
            ['Closure', 'iterable'],
            ['Closure(int):int', 'iterable'],
            ['Closure', 'iterable<int>'],
            ['Closure(int):int', 'iterable<int,string>'],
            ['Closure(int):int', 'stdClass'],
        ];
    }

    /**
     * @dataProvider nonWeakOverlappingTypeProvider
     * @suppress PhanAccessMethodInternal
     */
    public function testNonWeakOverlappingType(string $from_type_string, string $to_type_string): void
    {
        $from_type = self::makePHPDocType($from_type_string);
        $to_type = self::makePHPDocType($to_type_string);
        $this->assertFalse($from_type->canCastToType($to_type, $this->code_base), "canCastToType should be false");
        $this->assertFalse($from_type->canCastToDeclaredType($this->code_base, new Context(), $to_type), "canCastToDeclaredType should be false");
        $this->assertFalse($from_type->weaklyOverlaps($to_type, $this->code_base), "$from_type_string weaklyOverlaps $to_type_string should be false");
        $this->assertFalse($to_type->weaklyOverlaps($from_type, $this->code_base), "$to_type_string weaklyOverlaps $from_type_string should be false");
    }

    /** @return list<array{0: string}> */
    public function isSubtypeOfSelfProvider(): array
    {
        $values = [];
        foreach ($this->isSubtypeOfProvider() as [1 => $from_type_string, 2 => $to_type_string]) {
            $values[$from_type_string] = [$from_type_string];
            $values[$to_type_string] = [$to_type_string];
        }
        return \array_values($values);
    }

    /**
     * @dataProvider isSubtypeOfSelfProvider
     */
    public function testIsSubtypeOfSelf(string $from_type_string): void
    {
        $from_type = self::makePHPDocType($from_type_string);
        $this->assertTrue($from_type->isSubtypeOf(MixedType::instance(false), $this->code_base), "unexpected result for $from_type_string isSubtypeOf mixed");
        $this->assertTrue($from_type->isSubtypeOf($from_type, $this->code_base), "unexpected result for $from_type_string isSubtypeOf itself");
        $this->assertTrue($from_type->isSubtypeOf($from_type->withIsNullable(true), $this->code_base), "unexpected result for $from_type_string isSubtypeOf nullable version of itself");
    }

    /**
     * @dataProvider arrayShapeProvider
     */
    public function testArrayShape(string $normalized_union_type_string, string $type_string): void
    {
        $this->assertRegExp('@^' . Type::type_regex . '$@', $type_string, "Failed to parse '$type_string' with type_regex");
        $this->assertRegExp('@^' . Type::type_regex_or_this . '$@', $type_string, "Failed to parse '$type_string' with type_regex_or_this");
        $actual_type = self::makePHPDocType($type_string);
        $expected_flattened_type = UnionType::fromStringInContext($normalized_union_type_string, new Context(), Type::FROM_PHPDOC);
        $this->assertSame($normalized_union_type_string, $expected_flattened_type->__toString());
        if (!$actual_type instanceof ArrayShapeType) {
            throw new \RuntimeException(\sprintf("Failed to create expected class for %s: saw %s instead of %s", $type_string, get_class($actual_type), ArrayShapeType::class));
        }
        $actual_flattened_type = UnionType::of($actual_type->withFlattenedArrayShapeOrLiteralTypeInstances());
        $this->assertTrue($expected_flattened_type->isEqualTo($actual_flattened_type), "expected $actual_flattened_type to equal $expected_flattened_type");
    }

    /** @return array<int,array> */
    public function arrayShapeProvider(): array
    {
        return [
            [
                'array',
                'array{}'
            ],
            [
                'array<string,int>',
                'array{field:int}'
            ],
            [
                'array<string,int>',
                'array{field:int=}'
            ],
            [
                'array<string,int>|array<string,string>',
                'array{field:int|string}'
            ],
            [
                'array<int,int>|array<int,string>',
                'array{0:int,1:string}'
            ],
            [
                'array<int,int>|array<int,string>|array<string,\stdClass>',
                'array{0:int, 1:string, key : stdClass}'
            ],
            [
                'array<int,\stdClass>|array<int,int>|array<int,string>',
                'array{0:int,1:string,2:stdClass}'
            ],
            [
                'array<string,int>',
                'array{string:int}'
            ],
            [
                'array<string,\T<int>>',
                'array{field:T<int>}'
            ],
            [
                'array<string,?int>',
                'array{field:?int}',
            ],
            [
                'array<string,?int>|array<string,int[]>',
                'array{field:int[],field2:?int}'
            ],
            [
                'array<string,array>',
                'array{field:array{}}'
            ],
            [
                'array<string,array<string,int>>',
                'array{field:array{innerField:int}}'
            ],
            [
                'array<string,array<int,string>>',
                'array{field:array{0:\'test,other\',1:\'x\'}}'
            ],
        ];
    }

    /** @dataProvider unparsableTypeProvider */
    public function testUnparsableType(string $type_string): void
    {
        $this->assertNotRegExp('@^' . Type::type_regex . '$@', $type_string, "Failed to parse '$type_string' with type_regex");
        $this->assertNotRegExp('@^' . Type::type_regex_or_this . '$@', $type_string, "Failed to parse '$type_string' with type_regex_or_this");
    }

    /** @return array<int,array> */
    public function unparsableTypeProvider(): array
    {
        return [
            ['array{'],
            ['{}'],
            ['array{,field:int}'],
            ['array{field:}'],
            ['array{ field:int}'],
            ['array{::int}'],
            ["-'a'"],
            ["'@var'"],  // Ambiguous to support @, force hex escape
            ["'\\'"],
            ["'''"],
            ["'\\\\\\'"],
        ];
    }

    private static function createGenericArrayTypeWithMixedKey(Type $type, bool $is_nullable): GenericArrayType
    {
        return GenericArrayType::fromElementType($type, $is_nullable, GenericArrayType::KEY_MIXED);
    }

    public function testClassString(): void
    {
        $class_string_type = Type::fromFullyQualifiedString('class-string');
        $expected_class_string_type = ClassStringType::instance(false);
        $this->assertSameType($expected_class_string_type, $class_string_type);
        $this->assertSame('class-string', (string)$class_string_type);
    }

    public function testCallableString(): void
    {
        $callable_string_type = Type::fromFullyQualifiedString('?callable-string');
        $expected_callable_string_type = CallableStringType::instance(true);
        $this->assertSameType($expected_callable_string_type, $callable_string_type);
        $this->assertSame('?callable-string', (string)$callable_string_type);
    }

    private function assertCannotCastToType(Type $source, Type $target, string $details): void
    {
        $this->assertFalse($source->canCastToType($target, $this->code_base), "expected type $source not to be able to cast to type $target when $details");
    }

    private function assertCanCastToType(Type $source, Type $target, string $details = ''): void
    {
        $this->assertTrue($source->canCastToType($target, $this->code_base), "expected type $source to be able to cast to type $target" . ($details !== '' ? "when $details" : ""));
    }

    public function testCastingLiteralStringToInt(): void
    {
        $empty_string = LiteralStringType::instanceForValue('', false);
        $zero_string = LiteralStringType::instanceForValue('0', false);
        $decimal_string = LiteralStringType::instanceForValue('1234567890', false);
        $float_string = LiteralStringType::instanceForValue('1.5', false);
        $hex_string = LiteralStringType::instanceForValue('0x2', false);
        $regular_string = LiteralStringType::instanceForValue('foo', false);
        $int_type = IntType::instance(false);
        $float_type = FloatType::instance(false);
        $false_type = FalseType::instance(false);
        $true_type = TrueType::instance(false);
        try {
            Config::setValue('scalar_implicit_cast', false);
            foreach ([$int_type, $float_type] as $a_numeric_type) {
                $this->assertCannotCastToType($empty_string, $a_numeric_type, 'scalar_implicit_cast is disabled');
                $this->assertCannotCastToType($zero_string, $a_numeric_type, 'scalar_implicit_cast is disabled');
                $this->assertCannotCastToType($decimal_string, $a_numeric_type, 'scalar_implicit_cast is disabled');
                $this->assertCannotCastToType($hex_string, $a_numeric_type, 'scalar_implicit_cast is disabled');
                $this->assertCannotCastToType($regular_string, $a_numeric_type, 'scalar_implicit_cast is disabled');
                $this->assertCannotCastToType($float_string, $a_numeric_type, 'scalar_implicit_cast is disabled');
            }
            $this->assertCannotCastToType($empty_string, $true_type, 'scalar_implicit_cast is disabled');
            $this->assertCannotCastToType($empty_string, $false_type, 'scalar_implicit_cast is disabled');
            Config::setValue('scalar_implicit_cast', true);
            foreach ([$int_type, $float_type] as $a_numeric_type) {
                $this->assertCannotCastToType($empty_string, $a_numeric_type, 'scalar_implicit_cast is enabled');
                $this->assertCanCastToType($zero_string, $a_numeric_type, 'scalar_implicit_cast is enabled');
                $this->assertCanCastToType($decimal_string, $a_numeric_type, 'scalar_implicit_cast is enabled');
                $this->assertCannotCastToType($hex_string, $a_numeric_type, 'scalar_implicit_cast is enabled');
                $this->assertCannotCastToType($regular_string, $a_numeric_type, 'scalar_implicit_cast is enabled');
            }
            $this->assertCanCastToType($float_string, $float_type, 'scalar_implicit_cast is enabled');
            $this->assertCannotCastToType($float_string, $int_type, 'scalar_implicit_cast is enabled');
            $this->assertCannotCastToType($empty_string, $true_type, 'scalar_implicit_cast is disabled');
            $this->assertCanCastToType($empty_string, $false_type, 'scalar_implicit_cast is disabled');
            $this->assertCanCastToType($zero_string, $false_type, 'scalar_implicit_cast is disabled');
            $this->assertCannotCastToType($regular_string, $false_type, 'scalar_implicit_cast is disabled');
            $this->assertCanCastToType($regular_string, $true_type, 'scalar_implicit_cast is disabled');
        } finally {
            Config::setValue('scalar_implicit_cast', false);
        }
    }
}
