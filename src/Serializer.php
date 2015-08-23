<?php

namespace NilPortugues\Serializer;

use Closure;
use NilPortugues\Serializer\Strategy\StrategyInterface;
use ReflectionClass;
use ReflectionException;
use SplObjectStorage;

class Serializer
{
    const CLASS_IDENTIFIER_KEY = '@type';
    const SCALAR_TYPE = '@scalar';
    const SCALAR_VALUE = '@value';
    const NULL_VAR = null;
    const MAP_TYPE = '@map';

    /**
     * Storage for object.
     *
     * Used for recursion
     *
     * @var SplObjectStorage
     */
    private $objectStorage;

    /**
     * Object mapping for recursion.
     *
     * @var array
     */
    private $objectMapping = [];

    /**
     * Object mapping index.
     *
     * @var int
     */
    private $objectMappingIndex = 0;

    /**
     * @var \NilPortugues\Serializer\Strategy\JsonStrategy
     */
    private $serializationStrategy;

    /**
     * @var array
     */
    private $dateTimeClassType = ['DateTime', 'DateTimeImmutable', 'DateTimeZone', 'DateInterval', 'DatePeriod'];

    /**
     * @var array
     */
    private $serializationMap = [
        'object' => 'serializeObject',
        'array' => 'serializeArray',
        'integer' => 'serializeScalar',
        'double' => 'serializeScalar',
        'boolean' => 'serializeScalar',
        'string' => 'serializeScalar',
        'Traversable' => 'serializeArrayLikeObject',
    ];

    /**
     * @var bool
     */
    private $isHHVM;

    /**
     * Hack specific serialization classes.
     *
     * @var array
     */
    private $unserializationMapHHVM = [];

    /**
     * @param StrategyInterface $strategy
     */
    public function __construct(StrategyInterface $strategy)
    {
        $this->isHHVM = defined('HHVM_VERSION');
        if ($this->isHHVM) {
            $this->serializationMap = array_merge(
                $this->serializationMap,
                include realpath(dirname(__FILE__).'/Mapping/serialization_hhvm.php')
            );
            $this->unserializationMapHHVM = include realpath(dirname(__FILE__).'/Mapping/unserialization_hhvm.php');
        }
        $this->serializationStrategy = $strategy;
    }

    /**
     * This is handly specially in order to add additional data before the
     * serialization process takes place using the transformer public methods, if any.
     *
     * @return StrategyInterface
     */
    public function getTransformer()
    {
        return $this->serializationStrategy;
    }

    /**
     * Serialize the value in JSON.
     *
     * @param mixed $value
     *
     * @return string JSON encoded
     *
     * @throws SerializerException
     */
    public function serialize($value)
    {
        $this->reset();

        return $this->serializationStrategy->serialize($this->serializeData($value));
    }

    /**
     * Reset variables.
     */
    protected function reset()
    {
        $this->objectStorage = new SplObjectStorage();
        $this->objectMapping = [];
        $this->objectMappingIndex = 0;
    }

    /**
     * Parse the data to be json encoded.
     *
     * @param mixed $value
     *
     * @return mixed
     *
     * @throws SerializerException
     */
    protected function serializeData($value)
    {
        $this->guardForUnsupportedValues($value);

        if ($this->isHHVM && ($value instanceof \DateTimeZone || $value instanceof \DateInterval)) {
            return call_user_func_array($this->serializationMap[get_class($value)], [$this, $value]);
        }

        if (is_object($value) && in_array('Traversable', class_implements(get_class($value)))) {
            $toArray = [];
            foreach ($value as $k => $v) {
                $toArray[$k] = $v;
            }

            return array_merge(
                [self::CLASS_IDENTIFIER_KEY => get_class($value)],
                $this->serializeData($toArray)
            );
        }

        $type = (gettype($value) && $value !== null) ? gettype($value) : 'string';
        $func = $this->serializationMap[$type];

        return $this->$func($value);
    }

    /**
     * @param mixed $value
     *
     * @throws SerializerException
     */
    protected function guardForUnsupportedValues($value)
    {
        if ($value instanceof Closure) {
            throw new SerializerException('Closures are not supported in Serializer');
        }

        if ($value instanceof \DatePeriod) {
            throw new SerializerException(
                'DatePeriod is not supported in Serializer. Loop through it and serialize the output.'
            );
        }

        if (is_resource($value)) {
            throw new SerializerException('Resource is not supported in Serializer');
        }
    }

    /**
     * Unserialize the value from string.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public function unserialize($value)
    {
        if (is_array($value) && isset($value[self::SCALAR_TYPE])) {
            return $this->unserializeData($value);
        }

        $this->reset();

        return $this->unserializeData($this->serializationStrategy->unserialize($value));
    }

    /**
     * Parse the json decode to convert to objects again.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    protected function unserializeData($value)
    {
        if ($value === null || !is_array($value)) {
            return $value;
        }

        if (isset($value[self::MAP_TYPE]) && !isset($value[self::CLASS_IDENTIFIER_KEY])) {
            $value = $value[self::SCALAR_VALUE];

            return $this->unserializeData($value);
        }

        if (isset($value[self::SCALAR_TYPE])) {
            return $this->getScalarValue($value);
        }

        if (isset($value[self::CLASS_IDENTIFIER_KEY])) {
            return $this->unserializeObject($value);
        }

        return array_map([$this, __FUNCTION__], $value);
    }

    /**
     * @param $value
     *
     * @return float|int|null|bool
     */
    protected function getScalarValue($value)
    {
        switch ($value[self::SCALAR_TYPE]) {
            case 'integer':
                return intval($value[self::SCALAR_VALUE]);
                break;
            case 'float':
                return floatval($value[self::SCALAR_VALUE]);
                break;
            case 'boolean':
                return $value[self::SCALAR_VALUE];
                break;
            case 'NULL':
                return self::NULL_VAR;
                break;
        }

        return $value[self::SCALAR_VALUE];
    }

    /**
     * Convert the serialized array into an object.
     *
     * @param array $value
     *
     * @return object
     *
     * @throws SerializerException
     */
    protected function unserializeObject(array $value)
    {
        $className = $value[self::CLASS_IDENTIFIER_KEY];
        unset($value[self::CLASS_IDENTIFIER_KEY]);

        if (isset($value[self::MAP_TYPE])) {
            unset($value[self::MAP_TYPE]);
            unset($value[self::SCALAR_VALUE]);
        }

        if ($className[0] === '@') {
            return $this->objectMapping[substr($className, 1)];
        }

        if (!class_exists($className)) {
            throw new SerializerException('Unable to find class '.$className);
        }

        return (null === ($obj = $this->unserializeDateTimeFamilyObject($value, $className)))
            ? $this->unserializeUserDefinedObject($value, $className) : $obj;
    }

    /**
     * @param array  $value
     * @param string $className
     *
     * @return mixed
     */
    protected function unserializeDateTimeFamilyObject(array $value, $className)
    {
        $obj = null;

        if ($this->isDateTimeFamilyObject($className)) {
            if ($this->isHHVM) {
                return call_user_func_array(
                    $this->unserializationMapHHVM[$className],
                    [$this, $className, $value]
                );
            }

            $obj = $this->restoreUsingUnserialize($className, $value);
            $this->objectMapping[$this->objectMappingIndex++] = $obj;
        }

        return $obj;
    }

    /**
     * @param string $className
     *
     * @return bool
     */
    protected function isDateTimeFamilyObject($className)
    {
        $isDateTime = false;

        foreach ($this->dateTimeClassType as $class) {
            $isDateTime = $isDateTime || is_subclass_of($className, $class, true) || $class === $className;
        }

        return $isDateTime;
    }

    /**
     * @param string $className
     * @param array  $attributes
     *
     * @return mixed
     */
    protected function restoreUsingUnserialize($className, array $attributes)
    {
        foreach ($attributes as &$attribute) {
            $attribute = $this->unserializeData($attribute);
        }

        $obj = (object) $attributes;
        $serialized = preg_replace(
            '|^O:\d+:"\w+":|',
            'O:'.strlen($className).':"'.$className.'":',
            serialize($obj)
        );

        return unserialize($serialized);
    }

    /**
     * @param array  $value
     * @param string $className
     *
     * @return object
     */
    protected function unserializeUserDefinedObject(array $value, $className)
    {
        $ref = new ReflectionClass($className);
        $obj = $ref->newInstanceWithoutConstructor();

        $this->objectMapping[$this->objectMappingIndex++] = $obj;
        $this->setUnserializedObjectProperties($value, $ref, $obj);

        if (method_exists($obj, '__wakeup')) {
            $obj->__wakeup();
        }

        return $obj;
    }

    /**
     * @param array           $value
     * @param ReflectionClass $ref
     * @param mixed           $obj
     *
     * @return mixed
     */
    protected function setUnserializedObjectProperties(array $value, ReflectionClass $ref, $obj)
    {
        foreach ($value as $property => $propertyValue) {
            try {
                $propRef = $ref->getProperty($property);
                $propRef->setAccessible(true);
                $propRef->setValue($obj, $this->unserializeData($propertyValue));
            } catch (ReflectionException $e) {
                $obj->$property = $this->unserializeData($propertyValue);
            }
        }

        return $obj;
    }

    /**
     * @param $value
     *
     * @return string
     */
    protected function serializeScalar($value)
    {
        $type = gettype($value);
        if ($type === 'double') {
            $type = 'float';
        }

        return [
            self::SCALAR_TYPE => $type,
            self::SCALAR_VALUE => $value,
        ];
    }

    /**
     * @param \Traversable|\ArrayAccess $value
     *
     * @return mixed
     */
    protected function serializeArrayLikeObject($value)
    {
        $toArray = [self::CLASS_IDENTIFIER_KEY => get_class($value)];
        foreach ($value as $field) {
            $toArray[] = $field;
        }

        return $this->serializeData($toArray);
    }

    /**
     * @param array $value
     *
     * @return array
     */
    protected function serializeArray(array $value)
    {
        if (array_key_exists(self::MAP_TYPE, $value)) {
            return $value;
        }

        $toArray = [self::MAP_TYPE => 'array', self::SCALAR_VALUE => []];
        foreach ($value as $key => $field) {
            $toArray[self::SCALAR_VALUE][$key] = $this->serializeData($field);
        }

        return $this->serializeData($toArray);
    }

    /**
     * Extract the data from an object.
     *
     * @param mixed $value
     *
     * @return array
     */
    protected function serializeObject($value)
    {
        if ($this->objectStorage->contains($value)) {
            return [self::CLASS_IDENTIFIER_KEY => '@'.$this->objectStorage[$value]];
        }

        $this->objectStorage->attach($value, $this->objectMappingIndex++);

        $reflection = new ReflectionClass($value);
        $className = $reflection->getName();
        if ($reflection->isUserDefined()) {
            return $this->serializeUserClassWithClosureBinding($value, $className);
        }

        return $this->serializeInternalClass($value, $className, $reflection);
    }

    /**
     * @param mixed  $value
     * @param string $className
     *
     * @return array
     */
    protected function serializeUserClassWithClosureBinding($value, $className)
    {
        $reader = Closure::bind(
            function () {
                if (method_exists($this, '__sleep')) {
                    $properties = get_object_vars($this);

                    $serializable = [];
                    foreach ($this->__sleep() as $propertyName) {
                        $serializable[$propertyName] = $properties[$propertyName];
                    }

                    return $serializable;
                }

                return get_object_vars($this);
            },
            $value,
            $value
        );

        $paramsToSerialize = $reader($value);
        $data = [self::CLASS_IDENTIFIER_KEY => $className];
        $data += array_map([$this, 'serializeData'], $paramsToSerialize);

        return $data;
    }

    /**
     * @param mixed           $value
     * @param string          $className
     * @param ReflectionClass $ref
     *
     * @return array
     */
    protected function serializeInternalClass($value, $className, ReflectionClass $ref)
    {
        $paramsToSerialize = $this->getObjectProperties($ref, $value);
        $data = [self::CLASS_IDENTIFIER_KEY => $className];
        $data += array_map([$this, 'serializeData'], $this->extractObjectData($value, $ref, $paramsToSerialize));

        return $data;
    }

    /**
     * Return the list of properties to be serialized.
     *
     * @param ReflectionClass $ref
     * @param object          $value
     *
     * @return array
     */
    protected function getObjectProperties(ReflectionClass $ref, $value)
    {
        if (method_exists($value, '__sleep')) {
            return $value->__sleep();
        }
        $props = [];
        foreach ($ref->getProperties() as $prop) {
            $props[] = $prop->getName();
        }

        return array_unique(array_merge($props, array_keys(get_object_vars($value))));
    }

    /**
     * Extract the object data.
     *
     * @param object          $value
     * @param ReflectionClass $ref
     * @param array           $properties
     *
     * @return array
     */
    protected function extractObjectData($value, $ref, $properties)
    {
        $data = [];
        foreach ($properties as $property) {
            try {
                $propRef = $ref->getProperty($property);
                $propRef->setAccessible(true);
                $data[$property] = $propRef->getValue($value);
            } catch (ReflectionException $e) {
                $data[$property] = $value->$property;
            }
        }

        return $data;
    }
}
