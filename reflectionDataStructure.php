<?php
namespace minor;

class ReflectionBasedDataStructure implements IDataStructure {

    /**
     * @var \minnotations\IMinnotations
     */
    protected $annotations;

    protected $es = [];

    public function __construct(\minnotations\IMinnotations $annotations) {
        $this->annotations = $annotations;
    }

    /**
     * @return IEntityStructure
     */
    public function getEntityStructure($entityClass) {
        if (!isset($this->es[$entityClass])) {
            $this->es[$entityClass] = new ReflectionBasedEntityStructure($this->annotations, $entityClass);
        }
        return $this->es[$entityClass];
    }
}

class ReflectionBasedEntityStructure implements IEntityStructure {

   protected $className;

    /**
     * @var \minnotations\IMinnotations
     */
    protected $annotations;

    protected $relationsByName;
    protected $key;
    protected $fieldNames;

    /**
     * ReflectionBasedEntityStructure constructor.
     * @param \minnotations\IMinnotations $annotations
     * @param                             $className
     */
    public function __construct(\minnotations\IMinnotations $annotations, $className) {
        $this->annotations = $annotations;
        $this->className = $className;
    }

    private function scanProperties() {
        $this->fieldNames = [];
        $this->relationsByName = [];
        $rc = new \ReflectionClass($this->className);
        foreach ($rc->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $fieldName = $property->getName();
            $fa = $this->annotations->forProperty($this->className, $fieldName);
            if ($fa->hasAnnotation('minor', 'relation')) {
                $target = $fa->getValue('minor', 'var');
                if (null === $target) $target = $fa->getValue(':global', 'var');
                if (empty($target)) {
                    throw new \Exception("Invalid relation delaration for class $this->className property $fieldName - missing @var tag");
                }
                if ('\\' !== $target[0]) {
                    $ns = $property->getDeclaringClass()->getNamespaceName();
                    if (!empty($ns)) {
                        $target = $ns . '\\' . $target;
                    }
                } else {
                    // pomijamy pierwszy \\
                    $target = substr($target, 1);
                }
                $value = $fa->getValue('minor', 'relation');
                static $symbols = ['<->', '->', '<-'];
                static $types = [IEntityRelation::ManyToOneType, IEntityRelation::OneToManyType, IEntityRelation::OneToOneType];

                $relation = null;
                for ($i = 0; $i < 3; $i++) {
                    $p = strpos($value, $symbols[$i]);
                    $rfs = [];
                    if (false !== $p) {
                        $rfs[] = trim(substr($value, 0, $p));
                        $rfs[] = trim(substr($value, $i < 2 ? $p + 2 : $p + 3));

                        $sourceField = null;
                        $targetField = null;
                        $targetIdx = null;
                        for ($ri = 0; $ri < 2; $ri++) {
                            $pd = strpos($rfs[$ri], '.');
                            if (false !== $pd) {
                                $rfSpec = substr($rfs[$ri], 0, $pd);
                                $rf = substr($rfs[$ri], $pd + 1);
                            } else {
                                $rf = $rfs[$ri];
                                $rfSpec = 0 == $ri || 0 == $targetIdx ? 'this' : 'target';
                            }
                            if ('this' == $rfSpec) {
                                $sourceField = $rf;
                            } else {
                                $targetField = $rf;
                                $targetIdx = $ri;
                            }
                        }
                        if (null === $sourceField || null === $targetField) {
                            throw new \Exception("Invalid relation field definition ($value) for class $this->className, property $fieldName");
                        }
                        $ti = $i;
                        if ($i > 0 && 1 == $targetIdx) {
                            // odwrocony kierunek relacji
                            $ti = 3 - $i;
                        }
                        
                        $relation = new StdEntityRelation($types[$ti], $target, $sourceField, $targetField);
                        break;
                    }
                }
                if (null === $relation) {
                    throw new \Exception("Invalid relation definition ($value) for class $this->className, property $fieldName");
                }
                $this->relationsByName[$fieldName] = $relation;
                continue;
            }
            $this->fieldNames[] = $fieldName;
            if (true == $fa->getValue('minor', 'key')) {
                if (null === $this->key) $this->key = $fieldName;
                elseif (!is_array($this->key)) $this->key = [$this->key, $fieldName];
                else $this->key[] = $fieldName;
            }
        }
    }

    public function getFieldNames() {
        if (null === $this->fieldNames) $this->scanProperties();
        return $this->fieldNames;
    }

    public function getFieldType($fieldName) {
        $fieldAnnotations = $this->annotations->forProperty($this->className, $fieldName);
        $type = $fieldAnnotations->getValue('minor', 'var');
        if (null === $type) $type = $fieldAnnotations->getValue(':global', 'var');
        if (null === $type) $type = 'string';
        return $type;
    }

    public function getKeyFieldName() {
        return $this->key;
    }

    public function hasComplexKey() {
        return is_array($this->key);
    }

    public function getRelation($relationName) {
        if (null === $this->relationsByName) $this->scanProperties();
        if (!isset($this->relationsByName[$relationName])) {
            throw new \Exception("Undefined relation $relationName for entity class $this->className");
        }
        return $this->relationsByName[$relationName];
    }
}

class StdEntityRelation implements IEntityRelation {
    protected $targetEntityId;
    protected $type;
    protected $relationFieldName;
    protected $targetFieldName;

    public function __construct($type, $targetEntityId, $relationFieldName, $targetFieldName) {
        $this->targetEntityId = $targetEntityId;
        $this->type = $type;
        $this->relationFieldName = $relationFieldName;
        $this->targetFieldName = $targetFieldName;
    }

    /**
     * @return mixed
     */
    public function getTargetEntityId() {
        return $this->targetEntityId;
    }

    /**
     * @return mixed
     */
    public function getType() {
        return $this->type;
    }

    /**
     * @return mixed
     */
    public function getRelationFieldName() {
        return $this->relationFieldName;
    }

    /**
     * @return mixed
     */
    public function getTargetFieldName() {
        return $this->targetFieldName;
    }
}