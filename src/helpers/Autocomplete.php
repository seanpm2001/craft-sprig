<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\sprig\helpers;

use Craft;
use craft\helpers\ArrayHelper;

use yii\base\InvalidConfigException;
use yii\di\ServiceLocator;

use phpDocumentor\Reflection\DocBlockFactory;

class Autocomplete
{
    const COMPLETION_KEY = '__completions';
    const EXCLUDED_PROPERTY_NAMES = [
        'controller',
        'Controller',
        'CraftEdition',
        'CraftSolo',
        'CraftPro',
    ];
    const EXCLUDED_PROPERTY_REGEXES = [
        '^_',
    ];
    const EXCLUDED_METHOD_REGEXES = [
        '^_',
    ];

    // Faux enum, from: https://microsoft.github.io/monaco-editor/api/enums/monaco.languages.completionitemkind.html
    const CompletionItemKind = [
        'Class' => 5,
        'Color' => 19,
        'Constant' => 14,
        'Constructor' => 2,
        'Customcolor' => 22,
        'Enum' => 15,
        'EnumMember' => 16,
        'Event' => 10,
        'Field' => 3,
        'File' => 20,
        'Folder' => 23,
        'Function' => 1,
        'Interface' => 7,
        'Issue' => 26,
        'Keyword' => 17,
        'Method' => 0,
        'Module' => 8,
        'Operator' => 11,
        'Property' => 9,
        'Reference' => 21,
        'Snippet' => 27,
        'Struct' => 6,
        'Text' => 18,
        'TypeParameter' => 24,
        'Unit' => 12,
        'User' => 25,
        'Value' => 13,
        'Variable' => 4,
    ];

    /**
     * Core function that generates the autocomplete array
     */
    public static function generate()
    {
        $completionList = [];
        // Iterate through the globals in the Twig context
        /* @noinspection PhpInternalEntityUsedInspection */
        $globals = Craft::$app->view->getTwig()->getGlobals();
        foreach ($globals as $key => $value) {
            if (!in_array($key, self::EXCLUDED_PROPERTY_NAMES, true)) {
                $type = gettype($value);
                switch ($type) {
                    case 'object':
                        self::parseObject($completionList, $key, $value, '');
                        break;

                    case 'array':
                    case 'boolean':
                    case 'double':
                    case 'integer':
                    case 'string':
                        $kind = self::CompletionItemKind['Variable'];
                        $path = $key;
                        $normalizedKey = preg_replace("/[^A-Za-z]/", '', $key);
                        if (ctype_upper($normalizedKey)) {
                            $kind = self::CompletionItemKind['Constant'];
                        }
                        ArrayHelper::setValue($completionList, $path, [
                            self::COMPLETION_KEY => [
                                'detail' => "{$value}",
                                'kind' => $kind,
                                'label' => $key,
                                'insertText' => $key,
                            ]
                        ]);
                        break;
                }
            }
        }
        
        return $completionList;
    }

    public static function parseObject(array &$completionList, string $name, $object, string $path = '')
    {
        // Create the docblock factory
        $factory = DocBlockFactory::createInstance();

        $path = trim(implode('.', [$path, $name]), '.');
        // The class itself
        self::getClassCompletion($completionList, $object, $factory, $name, $path);
        // ServiceLocator Components
        self::getComponentCompletion($completionList, $object, $path);
        // Class properties
        self::getPropertyCompletion($completionList, $object, $factory, $path);
        // Class methods
        self::getMethodCompletion($completionList, $object, $factory, $path);
    }

    /**
     * @param array $completionList
     * @param $object
     * @param DocBlockFactory $factory
     * @param string $name
     * @param $path
     */
    protected static function getClassCompletion(array &$completionList, $object, DocBlockFactory $factory, string $name, $path)
    {
        try {
            $reflectionClass = new \ReflectionClass($object);
        } catch (\ReflectionException $e) {
            return;
        }
        // Information on the class itself
        $className = $reflectionClass->getName();
        $type = 'Class';
        $docs = $reflectionClass->getDocComment();
        if ($docs) {
            $docblock = $factory->create($docs);
            if ($docblock) {
                $summary = $docblock->getSummary();
                if (!empty($summary)) {
                    $docs = $summary;
                }
                $description = $docblock->getDescription()->render();
                if (!empty($description)) {
                    $docs = $description;
                }
            }
        }
        ArrayHelper::setValue($completionList, $path, [
            self::COMPLETION_KEY => [
                'detail' => "{$className}",
                'documentation' => $docs,
                'kind' => self::CompletionItemKind['Class'],
                'label' => $name,
                'insertText' => $name,
            ]
        ]);
    }

    /**
     * @param array $completionList
     * @param $object
     * @param $path
     */
    protected static function getComponentCompletion(array &$completionList, $object, $path)
    {
        if ($object instanceof ServiceLocator) {
            foreach ($object->getComponents() as $key => $value) {
                $componentObject = null;
                try {
                    $componentObject = $object->get($key);
                } catch (InvalidConfigException $e) {
                }
                if ($componentObject) {
                    self::parseObject($completionList, $key, $componentObject, $path);
                }
            }
        }
    }

    /**
     * @param array $completionList
     * @param $object
     * @param DocBlockFactory $factory
     * @param string $path
     */
    protected static function getPropertyCompletion(array &$completionList, $object, DocBlockFactory $factory, string $path)
    {
        try {
            $reflectionClass = new \ReflectionClass($object);
        } catch (\ReflectionException $e) {
            return;
        }
        $reflectionProperties = $reflectionClass->getProperties();
        foreach ($reflectionProperties as $reflectionProperty) {
            $propertyName = $reflectionProperty->getName();
            // Exclude some properties
            $propertyAllowed = true;
            foreach(self::EXCLUDED_PROPERTY_REGEXES as $excludePattern) {
                $pattern = '`'.$excludePattern.'`i';
                if (preg_match($pattern, $propertyName) === 1) {
                    $propertyAllowed = false;
                }
            }
            // Process the property
            if ($propertyAllowed && $reflectionProperty->isPublic()) {
                $detail = "Property";
                $docblock = null;
                $docs = $reflectionProperty->getDocComment();
                if ($docs) {
                    $docblock = $factory->create($docs);
                    if ($docblock) {
                        $summary = $docblock->getSummary();
                        if (!empty($summary)) {
                            $docs = $summary;
                        }
                        $description = $docblock->getDescription()->render();
                        if (!empty($description)) {
                            $docs = $description;
                        }
                    }
                }
                // Figure out the type
                if ($docblock) {
                    $detail = $docblock->getTagsByName('var') ?? "Property";
                }
                if ($detail === "Property") {
                    if (preg_match('/@var\s+([^\s]+)/', $docs, $matches)) {
                        list(, $type) = $matches;
                        $detail = $type;
                    } else {
                        $detail = "Property";
                    }
                }
                if ($detail === "Property") {
                    if ((PHP_MAJOR_VERSION >= 7 && PHP_MINOR_VERSION >= 4) || (PHP_MAJOR_VERSION >= 8)) {
                        if ($reflectionProperty->hasType()) {
                            $reflectionType = $reflectionProperty->getType();
                            if ($reflectionType && $reflectionType instanceof \ReflectionNamedType) {
                                $type = $reflectionType::getName();
                                $detail = $type;
                            }
                        }
                        if (PHP_MAJOR_VERSION >= 8) {
                            if ($reflectionProperty->hasDefaultValue()) {
                                $value = $reflectionProperty->getDefaultValue();
                                if (is_array($value)) {
                                    $value = json_encode($value);
                                }
                                if (!empty($value)) {
                                    $detail = "{$value}";
                                }
                            }
                        }
                    }
                }
                $thisPath = trim(implode('.', [$path, $propertyName]), '.');
                $label = $propertyName;
                ArrayHelper::setValue($completionList, $thisPath, [
                    self::COMPLETION_KEY => [
                        'detail' => $detail,
                        'documentation' => $docs,
                        'kind' => self::CompletionItemKind['Property'],
                        'label' => $label,
                        'insertText' => $label,
                        'sortText' => '~' . $label,
                    ]
                ]);
                // Recurse through if this is an object
                if (isset($object->$propertyName) && is_object($object->$propertyName)) {
                    if (!in_array($propertyName, self::EXCLUDED_PROPERTY_NAMES, true)) {
                        self::parseObject($completionList, $propertyName, $object->$propertyName, $path);
                    }
                }
            }
        }
    }

    /**
     * @param array $completionList
     * @param $object
     * @param DocBlockFactory $factory
     * @param string $path
     */
    protected static function getMethodCompletion(array &$completionList, $object, DocBlockFactory $factory, string $path)
    {
        try {
            $reflectionClass = new \ReflectionClass($object);
        } catch (\ReflectionException $e) {
            return;
        }
        $reflectionMethods = $reflectionClass->getMethods();
        foreach ($reflectionMethods as $reflectionMethod) {
            $methodName = $reflectionMethod->getName();
            // Exclude some properties
            $methodAllowed = true;
            foreach(self::EXCLUDED_METHOD_REGEXES as $excludePattern) {
                $pattern = '`'.$excludePattern.'`i';
                if (preg_match($pattern, $methodName) === 1) {
                    $methodAllowed = false;
                }
            }
            // Process the method
            if ($methodAllowed && $reflectionMethod->isPublic()) {
                $type = "Method";
                $detail = $type;
                $docblock = null;
                $docs = $reflectionMethod->getDocComment();
                if ($docs) {
                    $docblock = $factory->create($docs);
                    if ($docblock) {
                        $summary = $docblock->getSummary();
                        if (!empty($summary)) {
                            $docs = $summary;
                        }
                        $description = $docblock->getDescription()->render();
                        if (!empty($description)) {
                            $docs = $description;
                        }
                    }
                }
                $thisPath = trim(implode('.', [$path, $methodName]), '.');
                $label = $methodName . '()';
                ArrayHelper::setValue($completionList, $thisPath, [
                    self::COMPLETION_KEY => [
                        'detail' => $detail,
                        'documentation' => $docs,
                        'kind' => self::CompletionItemKind['Method'],
                        'label' => $label,
                        'insertText' => $label,
                        'sortText' => '~~' . $label,
                    ]
                ]);
            }
        }
    }
}
