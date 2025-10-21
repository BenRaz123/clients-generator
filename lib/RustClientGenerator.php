<?php

/**
 * Rust Client Generator
 * ## Current issues
 * TODO: Should zero-variant enums be represented as Unit structs or zero-variant enums?
 * TODO: Should enum variants have their casing changed?
 * TODO: How should int enums with overlapping discriminators be treated?
 *	- Current solution: as string enums
 * TODO: How should inheritance be treated? How about abstract classess?
 *	- Current solution: 
 * TODO: How should documentation for class fields be handled?
 *	* [ ] In trait impls / methods
 *	* [ ] In fields
 *	* [X] Both
 * TODO: How should abstract classes with no derived classes be treated?
 * TODO: What does insert only mean?
 */
class RustClientGenerator extends ClientGeneratorFromXml
{
	private array $_enums = [];
	private string $_trait_buff = "";
	private string $_classes_buff = "";
	private RustClassInheritanceHierarchy $_classes;

	function __construct($xmlPath, Zend_Config $config, $sourcePath = "rust")
	{
		parent::__construct($xmlPath, $sourcePath, $config);
	}

	function getSingleLineCommentMarker()
	{
		return '//';
	}

	function generate()
	{
		parent::generate();

		$xpath = new \DOMXPath($this->_doc);

		$this->loadEnums($xpath->query("/xml/enums/enum"));

		$enums = "";

		foreach ($xpath->query("/xml/enums/enum") as $node) {
			$enum = RustEnum::from($node);
			if (!$this->shouldIncludeType($enum->raw_ident)) continue;
			$enums .= $enum->toRust();
		}

		$this->addFile("src/kaltura_client/enums.rs", $enums);

		$this->_classes = RustClassInheritanceHierarchy::new();
		$classes = $xpath->query("/xml/classes/class");

		foreach ($classes as $class) {
			/** @var DOMElement $class */
			$base = $class->getAttribute("base");
			if ($base !== "") {
				$found_base = $this->_classes->findFromRawIdent($base);
				if ($found_base !== null)
					$found_base->append(RustClassInheritanceHierarchyNode::newLeaf(RustClass::from($class)));
				else {
					KalturaLog::err("class {$class->getAttribute('name')} inherits from {$base} but it is not loaded yet");
					var_dump($this->_classes);
					exit;
				}
			}
			$this->_classes->append(RustClassInheritanceHierarchyNode::newLeaf(RustClass::from($class)));
		}

		foreach ($this->_classes->flatten() as $class) {
			$this->writeClass($class);
		}
		$this->addFile("src/kaltura_client/classes.rs", $this->_classes_buff);
	}

	private function loadEnums($enums)
	{
		foreach ($enums as $item) {
			$this->_enums[$item->getAttribute("name")] = null;
		}
	}

	private function writeClass(RustClassInheritanceHierarchyNode $class)
	{
		$bases = $class->getBaseClasses();
		if ($class->value->abstract) {
			if (!$class->descendants) {
				KalturaLog::err("abstract class {$class->value->raw_ident} is abstract and has no derived classes. Skipping it.");
				return;
			}
		} else {
			$this->_classes_buff .= "\n";
			if ($class->value->description !== null)
				$this->_classes_buff .= "/// {$class->value->description}\n";
			$this->_classes_buff .= "pub struct {$class->value->raw_ident} {\n";
			foreach ($class->value->members as $member)
				$this->_classes_buff .= $member->toStructMember($this->_classes) . "\n";
			foreach ($class->getBaseClasses() as $base)
				foreach ($base->value->members as $member)
					$this->_classes_buff .= $member->toStructMember($this->_classes, $base->value) . "\n";
			$this->_classes_buff .= "}\n";
		}

		// this class has derived classes
		if ($class->descendants) {
			$this->_trait_buff .= "\n";
			if ($class->value->description)
				$this->_trait_buff .= "/// {$class->value->description}\n";
			$this->_trait_buff .= "pub trait {$class->value->ident}";
			if ($class->up instanceof RustClassInheritanceHierarchyNode)
				$this->_trait_buff .= ": {$class->up->value->ident}";
			$this->_trait_buff .= " {\n";
		}
	}

	/**
	 * All currently used and reserved keywords that are not allowed for use as identifiers
	 * For more, see [this list](https://doc.rust-lang.org/reference/keywords.html)
	 * @var string[]
	 */
	public const RUST_KEYWORDS = [
		// Strict keywords
		"as",
		"break",
		"const",
		"continue",
		"crate",
		"else",
		"enum",
		"extern",
		"false",
		"fn",
		"for",
		"if",
		"impl",
		"in",
		"let",
		"loop",
		"match",
		"mod",
		"move",
		"mut",
		"pub",
		"ref",
		"return",
		"self",
		"Self",
		"static",
		"struct",
		"super",
		"trait",
		"true",
		"type",
		"unsafe",
		"use",
		"where",
		"while",
		"async",

		// Strict as of 2018 Edition
		"await",
		"dyn",

		// Reserved Keywords
		"abstract",
		"become",
		"box",
		"do",
		"final",
		"macro",
		"override",
		"priv",
		"typeof",
		"unsized",
		"virtual",
		"yield",

		// Reserved as of 2018 edition
		"try",

		// Reserved as of 2024 edition
		"gen",
	];
}

/**
 * If `$ident` is a Rust keyword, then prefix it with a `r#`, which escapes 
 * the keyword. (Note that some keywords, such as `macro_rules` are "weak"
 * keywords meaning they are fine in identifier contexts {@link https://doc.rust-lang.org/reference/keywords.html#weak-keywords}
 *
 *  @see RustClientGenerator::RUST_KEYWORDS
 *  @see https://doc.rust-lang.org/reference/identifiers.html#r-ident.raw
 */
function sanitizeIdent(string $ident): string
{
	foreach (RustClientGenerator::RUST_KEYWORDS as $keyword)
		if ($ident == $keyword)
			return "r#$ident";
	return $ident;
}

class RustClassInheritanceHierarchy
{
	/**
	 * The roots of the class inheritance hierarchy. These classes are not 
	 * derived from any other classes.
	 *
	 * @var RustClassInheritanceHierarchyNode[]
	 */
	public array $roots;

	/**
	 * Appends a node to the root list 	
	 */
	public function append(RustClassInheritanceHierarchyNode $node)
	{
		$node->up = &$this;
		$this->roots[] = $node;
	}


	/**
	 * Returns whether a given class is the base of another class, 
	 * returning null if the class does not exist.
	 */
	public function isBase(string $raw_ident): ?bool
	{
		if (($found = $this->findFromRawIdent($raw_ident)) !== null)
			if ($found->descendants !== null)
				return true;
			else
				return false;
		else
			return null;
	}

	/**
	 * Returns whether a given class is the descendant of a base class,
	 * returning null if the class does not exist.
	 */
	public function isDescendant(string $raw_ident): ?bool
	{
		$found = $this->findFromRawIdent($raw_ident);
		if ($found === null) return null;
		// roots are the only classes not descendants of other classes
		foreach ($this->roots as $root)
			if ($root->value->raw_ident == $raw_ident)
				return false;
		return true;
	}

	public function findFromRawIdent(string $raw_ident): ?RustClassInheritanceHierarchyNode
	{
		return $this->find(function (RustClass $class) use ($raw_ident) {
			return $class->raw_ident === $raw_ident;
		});
	}

	/**
	 * Finds the first {@link RustClassInheritanceHierarchyNode} where `f($this->value)` is true
	 *
	 * @param callable(RustClass $x): bool $f
	 */
	public function find(callable $f): ?RustClassInheritanceHierarchyNode
	{
		foreach ($this->roots as $root)
			if (($found = $root->find($f)) !== null)
				return $found;
		return null;
	}

	/**
	 * Flattens the tree to a list
	 * @return RustClassInheritanceHierarchyNode[]
	 */
	public function flatten(): array
	{
		$ret = [];
		foreach ($this->roots as $root)
			foreach ($root->flatten() as $it)
				$ret[] = $it;
		return $ret;
	}

	/**
	 * Return a new empty tree
	 */
	public static function new(): self
	{
		return new self([]);
	}

	protected function __construct($roots)
	{
		$this->roots = $roots;
	}
}

class RustClassInheritanceHierarchyNode
{
	/**
	 * Pointer up the hierarchy
	 */
	public RustClassInheritanceHierarchyNode|RustClassInheritanceHierarchy|null $up = null;
	public RustClass $value;
	/**
	 * @var ?RustClassInheritanceHierarchyNode[]
	 */
	public ?array $descendants;

	/**
	 * Appends a node to the descendants list 
	 */
	public function append(self $node)
	{
		$node->up = &$this;
		$this->descendants[] = $node;
	}

	/**
	 * Traverses the inheritance heirarchy, returning an array of all classes 
	 * this node inherits from
	 *
	 * @throws Exception when unreachable code reached, let it bubble up because 
	 * it means something has gone terribly wrong. $this->up is of type 
	 * `null|RustClassInheritanceHierarchy|RustClassInheritanceHierarchyNode`
	 * and an exception is issued if it is somehow not of those types, which 
	 * should be impossible.
	 *
	 * @return RustClassInheritanceHierarchyNode[]
	 */
	public function getBaseClasses(): array
	{
		$up = $this->up;
		if ($up === null) {
			KalturaLog::err("Up pointer set to null. Printing \$this: \n" . getVarDump($this));
			return [];
		}

		if ($up instanceof RustClassInheritanceHierarchy)
			return [];

		if ($up instanceof RustClassInheritanceHierarchyNode)
			return array_merge([$up], $up->getBaseClasses());

		throw new Exception("unreachable area reached");
	}

	/**
	 * Calls `$f` on each member->value and returns the first member one where `$f` returns true.
	 * @param callable(RustClass $x): bool $f
	 */
	public function find(callable $f): ?self
	{
		if ($f($this->value))
			return $this;

		if ($this->descendants !== null)
			foreach ($this->descendants as $node)
				if (($found = $node->find($f)) !== null)
					return $found;

		return null;
	}

	public static function newLeaf(RustClass $value): self
	{
		return new self($value, null);
	}

	public function format(int $depth = 0): string
	{
		$indent = str_repeat("\t", $depth);
		$s = "{$indent}{$this->value->raw_ident} ";
		if ($this->descendants !== null)
			foreach ($this->descendants as $descendant)
				$s .= "\n" . $descendant->format($depth + 1);
		return $s;
	}

	/**
	 * Flattens the tree to a list
	 * @return RustClassInheritanceHierarchyNode[]
	 */
	public function flatten(): array
	{
		$ret = [$this];
		if ($this->descendants)
			foreach ($this->descendants as $descendant)
				foreach ($descendant->flatten() as $flattened)
					$ret[] = $flattened;
		return $ret;
	}

	//// we are manually implementing `__debugInfo()` here so that it does not try to show the up pointer, which results in a cycle.
	//function __debugInfo(): array
	//{
	//	return [
	//		"value" => $this->value,
	//		"descendants" => $this->descendants
	//	];	
	//}

	protected function __construct(RustClass $value, ?array $descendants)
	{
		$this->value = $value;
		$this->descendants = $descendants;
	}
}

class RustClass
{
	/*
	 * This class is abstract. It should only be implemented as an interface.
	 */
	public bool $abstract;
	public bool $deprecated;
	public ?string $description;
	public string $raw_ident;
	public string $ident;
	/**
	 * @var RustClassMember[]
	 */
	public array $members;

	function __debugInfo(): array
	{
		return ["RustClass" => $this->raw_ident];
	}

	public static function from(\DOMElement $xml): self
	{
		$raw_ident = $xml->getAttribute("name");
		$ident = sanitizeIdent($raw_ident);
		if (($desc = $xml->getAttribute("description")) !== "")
			$description = $desc;
		else $description = null;

		if ($xml->getAttribute("abstract") !== "")
			$abstract = true;
		else $abstract = false;
		if ($xml->getAttribute("deprecated") !== "")
			$deprecated = true;
		else $deprecated = false;
		$members = [];
		foreach ($xml->childNodes as $member)
			if ($member instanceof \DOMElement)
				$members[] = RustClassMember::from($member);

		return new self($abstract, $deprecated, $description, $raw_ident, $ident, $members);
	}

	function __construct(bool $abstract, bool $deprecated, ?string $description, string $raw_ident, string $ident, array $members)
	{
		$this->abstract = $abstract;
		$this->deprecated = $deprecated;
		$this->description = $description;
		$this->raw_ident = $raw_ident;
		$this->ident = $ident;
		$this->members = $members;
	}
}

//TODO: add multilingual
class RustClassMember
{
	public string $ident;
	public string $raw_ident;
	public RustClassMemberType $type;
	public ?string $customType;
	//TODO:change enumType and arrayType to references?
	public ?string $arrayType;
	public ?string $enumType;
	public ?string $description;
	public bool $readOnly;
	public bool $insertOnly;
	public bool $writeOnly;
	public bool $isTime;

	/**
	 * Converts to a trait getter/setter prototype (depending on the insertOnly/writeOnly permisions)
	 */
	public function toPrototype(RustClassInheritanceHierarchy $tree): string {
		$s =  "";	
		if (!$this->readOnly) {
			if ($this->description)
				$s .= "/// {$this->description}\n";
			$s .= "fn get_{$this->formatIdent($this->raw_ident)}(&self) -> {}";
		}
	}

	public function formatIdent(string $ident):string {
		//TODO: turn into snake case
		return sanitizeIdent(camelToSnake($ident));
	}

	private function formatType(RustClassInheritanceHierarchy $tree): string {
		switch ($this->type) {
		case RustClassMemberType::BigInt:
			return "i64";
		case RustClassMemberType::Bool:
			return "bool";
		case RustClassMemberType::String:
			if ($this->enumType)
				return "enums::{$this->enumType}";
			return "String";
		case RustClassMemberType::Integer:
			if ($this->enumType)
				return "enums::{$this->enumType}";
			return "i32";
		case RustClassMemberType::Custom:
			$customClassNode = $tree->find(fn($x) => $x->ident==$this->arrayType);
			if ($found->descendants)
				$s .= "Vec<impl traits::{$this->arrayType}>";
			else
				$s .= "Vec<{$this->arrayType}>";
			return $this->customType;
		}
	}

	public function toStructMember(RustClassInheritanceHierarchy $tree, ?RustClass $asInheritedFrom = null): string
	{
		$s = "";
		if ($asInheritedFrom !== null)
			$s .= "\t/// (inherited from [::kaltura_client::traits::{$asInheritedFrom->raw_ident}])\n";
		if ($this->description)
			$s .= "\t/// {$this->description}\n";
		$s .= "\tpub(crate) {$this->ident}: ";
		if ($this->enumType !== null)
			$s .= "enums::{$this->enumType}";
		elseif ($this->arrayType !== null){
			$found = $tree->find(fn($x) => $x->ident==$this->arrayType);
			if ($found->descendants)
				$s .= "Vec<impl traits::{$this->arrayType}>";
			else
				$s .= "Vec<{$this->arrayType}>";
		} else {
			$s .= match ($this->type) {
				RustClassMemberType::Integer => "i32",
				RustClassMemberType::BigInt => "i64",
				RustClassMemberType::String => "String",
				RustClassMemberType::Bool => "bool",
				default => null,
			};
		}

		$s .= ",";
		return $s;
	}

	public static function from(\DOMElement $xml): self
	{
		$raw_ident = $xml->getAttribute("name");
		$ident = sanitizeIdent($raw_ident);

		$customType = $xml->getAttribute("type");
		$type = RustClassMemberType::from($customType);

		if ($type !== RustClassMemberType::Custom) 
			$customType = null;

		if (($at = $xml->getAttribute("arrayType")) !== "")
			$arrayType = sanitizeIdent($at);
		else $arrayType = null;

		if (($et = $xml->getAttribute("enumType")) !== "")
			$enumType = sanitizeIdent($et);
		else $enumType = null;

		if (($enumType !== null) && (($type !== RustClassMemberType::Integer) && ($type !== RustClassMemberType::String))) {
			KalturaLog::warning("\tclass member {$raw_ident} encountered which is based off of a non-string or int discriminated enum");
			var_dump($enumType);
			var_dump($type);
		}

		$readOnly = $xml->getAttribute("readOnly") === 1;
		$writeOnly = $xml->getAttribute("writeOnly") === 1;
		$insertOnly = $xml->getAttribute("insertOnly") === 1;

		if (($writeOnly || $insertOnly) && $readOnly) KalturaLog::warning("class member {$raw_ident} has both (writeOnly/insertOnly) and readOnly set");
		if ($writeOnly && $insertOnly) KalturaLog::warning("class member {$raw_ident} has both writeOnly and readOnly set");

		$isTime = $xml->getAttribute("isTime") === 1;
		if (($desc = $xml->getAttribute("description")) !== "")
			$description = $desc;
		else $description = null;

		return new self(
			$ident,
			$raw_ident,
			$type,
			$customType,
			$arrayType,
			$enumType,
			$description,
			$readOnly,
			$insertOnly,
			$writeOnly,
			$isTime
		);
	}

	private function __construct(
		string $ident,
		string $raw_ident,
		RustClassMemberType $type,
		?string $customType,
		?string $arrayType,
		?string $enumType,
		?string $description,
		bool $readOnly,
		bool $insertOnly,
		bool $writeOnly,
		bool $isTime
	) {
		$this->ident = $ident;
		$this->raw_ident = $raw_ident;
		$this->type = $type;
		$this->customType = $customType;
		$this->arrayType = $arrayType;
		$this->enumType = $enumType;
		$this->description = $description;
		$this->readOnly = $readOnly;
		$this->insertOnly = $insertOnly;
		$this->writeOnly = $writeOnly;
		$this->isTime = $isTime;
	}
}

enum RustClassMemberType
{
/**
	 * 32 bit integer (`i32` in Rust)
	 */
	case Integer;
/**
	 * 64 bit integer (`i64` in Rust)
	 */
	case BigInt;
/**
	 * String
	 */
	case String;
/**
	 * Boolean
	 */
	case Bool;
/**
	 * Vector (mut) Or &[T] (immut)
	 */
	case Array;
/**
	 * A type that we are defining here
	 */
	case Custom;

	public static function from(string $x): self
	{
		return match ($x) {
			"int" => self::Integer,
			"bigint" => self::BigInt,
			"string" => self::String,
			"bool" => self::Bool,
			"array" => self::Array,
			default => self::Custom,
		};
	}
}

class RustEnum
{
	public string $ident;
	public string $raw_ident;
	public ?string $description;
	public RustEnumType $type;
	/**
	 * @var RustEnumVariant[]
	 */
	public array $items = [];

	/**
	 * Constructs a {@link RustEnum} from a {@link \DOMElement}
	 * Note that *it is a valid invariant* for {@link RustEnum::$items} to be of length 0.
	 * What representation they will be is, as of yet, indetermined.
	 *
	 * TODO:fix doc comment issue
	 * @throws Exception if can't parse enum type ({@see RustEnumType::fromString()})
	 */
	public static function from(\DOMElement $node): self
	{
		$raw_ident = $node->getAttribute("name");
		$ident = sanitizeIdent($raw_ident);
		try {
			$type = RustEnumType::fromString($node->getAttribute("enumType"));
		} catch (Exception $e) {
			throw new Exception("couldn't parse enum element with name='`{$node->getAttribute('name')}`' into enum: {$e->getMessage()}");
		}

		$description = null;
		if (($desc = $node->getAttribute("description")) !== "")
			$description = $desc;

		$items = [];
		foreach ($node->childNodes as $variant) {
			if ($variant instanceof \DOMElement) {
				$items[] = RustEnumVariant::from($variant);
			}
		}

		return new self($ident, $raw_ident, $type, $items, $description);
	}

	/**
	 * Writes an enum as a rust enum. Behavior depends on the {@link RustEnum::$type}:
	 *	1. If the enum is of type int, the numeric value will be used as the discriminator
	 *	2. If the enum is of type string, the enum will implement Deref<str> and Display
	 *	3. If the enum is empty, make it a unit struct
	 */
	public function toRust(): string
	{
		return match ($this->type) {
			RustEnumType::Integer => $this->writeIntEnum(),
			RustEnumType::String => $this->writeStringEnum(),
		};
	}

	protected function writeIntEnum(): string
	{
		$variant_values = [];
		foreach ($this->items as $item) $variant_values[] = $item->value;
		if (has_dupes($variant_values)) {
			KalturaLog::warning("int enum \"{$this->raw_ident}\" has duplicate values (values: [" . implode(',', $variant_values) . "]), treating it as a string enum instead.");
			return $this->writeStringEnum();
		}

		$s = "\n";
		if ($this->description !== null)
			$s .= "/// {$this->description}\n";
		$s .= "pub enum {$this->ident} {\n";
		foreach ($this->items as $variant) {
			//TODO: remove
			//$s .= "\t/// {$variant->value}\n";
			$s .= "\t#[allow(non_camel_case_types)]\n";
			$s .= "\t{$variant->ident} = {$variant->value},\n";
		}
		$s .= "}\n";
		return $s;
	}

	protected function writeStringEnum(): string
	{
		$s = "";
		if ($this->description !== null)
			$s .= "/// {$this->description}\n";
		$s .= "pub enum {$this->ident} {\n";
		foreach ($this->items as $variant) {
			$s .= "\t/// `{$variant->value}`\n";
			$s .= "\t#[allow(non_camel_case_types)]\n";
			$s .= "\t{$variant->ident},\n";
		}
		$s .= "}\n";
		$s .= "impl ::core::convert::AsRef<str> for {$this->ident} {\n";
		$s .= "\tfn as_ref(&self) -> &str {\n";
		$s .= "\t\tmatch *self {\n";
		foreach ($this->items as $variant)
			$s .= "\t\t\tSelf::{$variant->ident} => \"{$variant->value}\",\n";
		$s .= "\t\t}\n";
		$s .= "\t}\n";
		$s .= "}\n";
		$s .= "impl ::core::fmt::Display for {$this->ident} {\n";
		$s .= "\tfn fmt(&self, f: &mut ::core::fmt::Formatter<'_>) -> ::core::fmt::Result {\n";
		$s .= "\t\twrite!(f, \"{}\", <Self as ::core::convert::AsRef<str>>::as_ref(&self))\n";
		$s .= "\t}\n";
		$s .= "}\n";
		return $s;
	}

	function __toString()
	{
		return $this->toRust();
	}

	/*
	 * @param items RustEnumVariant[]
	 */
	private function __construct(string $ident, string $raw_ident, RustEnumType $type, array $items, ?string $description = null)
	{
		$this->ident = $ident;
		$this->raw_ident = $raw_ident;
		$this->type = $type;
		$this->items = $items;
		$this->description = $description;
	}
}

//TODO(benraz123): document
class RustEnumVariant
{
	//TODO: add `raw_ident`
	public string $ident;
	public string $value;

	public static function from(\DOMElement $item): self
	{
		$ident = sanitizeIdent($item->getAttribute("name"));
		$value = $item->getAttribute("value");

		return new self($ident, $value);
	}

	private function __construct(string $ident, string $value)
	{
		$this->ident = $ident;
		$this->value = $value;
	}
}

/**
 * The type of enum being used, either a string enum where the discriminators 
 * are of type `&'static str` or an int enum, where the discriminators are of 
 * type `usize`
 */
enum RustEnumType
{
	case String;
	case Integer;
	/**
	 * Constructs a {@link RustEnumType} from a string
	 * @throws Exception when `$s` is not either `int` or `string`
	 */
	public static function fromString(string $s): self
	{
		switch ($s) {
			case "int":
				return self::Integer;
			case "string":
				return self::String;
			default:
				throw new Exception("invalid enum type (currently can only handle \"string\" or \"int\": \"$s\"");
		}
	}
}

function getVarDump($x): string
{
	ob_start();
	var_dump($x);
	return ob_get_clean();
}

/**
 * Find out whether `$input_array` contains any duplicates.
 *
 * @see https://stackoverflow.com/questions/3145607/php-check-if-an-array-has-duplicates for an explanation of the algorithm
 */
function has_dupes(array $input_array): bool
{
	return count($input_array) !== count(array_flip($input_array));
}

function camelToSnake($camelCase) { 
	$pattern = '/(?<=\\w)(?=[A-Z])|(?<=[a-z])(?=[0-9])/'; 
	$snakeCase = preg_replace($pattern, '_', $camelCase); 
	return strtolower($snakeCase); 
} 
