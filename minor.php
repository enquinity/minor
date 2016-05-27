<?php

namespace minor;

class Type {
    const Integer = 'integer';
    const String = 'string';
    const Float = 'float';
    const Bool = 'bool';

    public static function isInt($type) {
        return 'integer' === $type || 'int' === $type;
    }

    public static function isFloat($type) {
        return 'float' === $type;
    }

    public static function isBool($type) {
        return 'bool' === $type || 'boolean' === $type;
    }
}

class ValueConversion {
    const ConversionNone = 0;
    const ConversionToInt = 1;
    const ConversionToFloat = 2;
    const ConversionToBool = 3;
}


interface IEntityActivator {
    public function createInstances($entityId, $count = 1);
}

interface IEntityRelation {
    const OneToOneType = 'oo';
    const OneToManyType = 'om';
    const ManyToOneType = 'mo';

    public function getTargetEntityId();
    public function getRelationFieldName();
    public function getTargetFieldName();
    public function getType();
}

interface IEntityStructure {
    public function getFieldNames();
    public function getFieldType($fieldName);

    public function getKeyFieldName();
    public function hasComplexKey();

    /**
     * Returns name of source for the entity - for instance name of table in database
     * @return string
     */
    //public function getSourceName();

    /**
     * @param $relationName
     * @return IEntityRelation
     */
    public function getRelation($relationName);
}

interface IDataStructure {
    /**
     * @param $entityId
     * @return IEntityStructure
     */
    public function getEntityStructure($entityId);
}

interface IFieldMapper {
    public function map($key, &$relationName, &$fieldName);
}

interface IDataSetHydrator {
    public function getTotalCount();
    public function fetch($count);
    //public function rewind();
}


class StdFieldMapper implements IFieldMapper {
    public function map($key, &$relationName, &$fieldName) {
        $p = strrpos($key, '.');
        if (false === $p) {
            $relationName = '.';
            $fieldName = $key;
        } else {
            $relationName = substr($key, 0, $p);
            $fieldName = substr($key, $p + 1);
        }
    }

    private static $instance = null;
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}

class StdEntityActivator implements IEntityActivator {
    public function createInstances($entityId, $count = 1) {
        $o = [];
        while ($count-- > 0) {
            $o[] = new $entityId();
        }
        return $o;
    }

    private static $instance = null;
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}

trait TBaseDataSetHydrator {
    /**
     * @var IEntityActivator
     */
    protected $entityActivator;

    /**
     * @var IDataStructure
     */
    protected $dataStructure;

    /**
     * @var array
     * [['key' => source data key, 'relation' => relation name (.) for main entity, 'fieldName' => name object field source data key is to be mapped to]]
     */
    //protected $mapFields = [];

    /**
     * @var IFieldMapper
     */
    protected $fieldMapper;

    //protected $mapInstructions = [];

    protected $startEntityId;
    //protected $extraRelations = null;

    protected $relPaths = null;

    /*protected function calcMapInstructions(array $sourceKeys) {
        $relation = null;
        $fieldName = null;
        foreach ($sourceKeys as $sourceKey) {
            $this->fieldMapper->map($sourceKey, $relation, $fieldName);
            $this->mapInstructions[ValueConversion::ConversionNone][] = [
                $sourceKey,
                $this->relPaths[$relation]['objectIdx'],
                $fieldName,
            ];
            // TODO: zbadać typ pola i do odpowiedniego indeksu wpisać
        }
    }*/

    protected function createObjects($count) {
        $objects = [];
        foreach ($this->relPaths as $relPath => $info) {
            $objects[$info['objectIdx']] = $this->entityActivator->createInstances($info['entityId'], $count);
        }
        foreach ($this->relPaths as $relPath => $info) {
            if (empty($info['parentPath'])) continue;
            $idxParent = $this->relPaths[$info['parentPath']]['objectIdx'];
            $idxChild = $info['objectIdx'];
            $parentFieldName = $info['localPath'];
            for ($i = 0; $i < $count; $i++) {
                $objects[$idxParent][$i]->$parentFieldName = $objects[$idxChild][$i];
            }
        }
        return $objects;
    }

    private function calcRelInfo($relPath) {
        $p = strrpos($relPath, '.');
        $parentPath = $p !== false ? substr($relPath, 0, $p) : '.';
        $localPath = $p !== false ? substr($relPath, $p + 1) : $relPath;

        if (!array_key_exists($parentPath, $this->relPaths)) {
            $this->calcRelInfo($parentPath);
        }
        $parentInfo = $this->relPaths[$parentPath];
        $relation = $this->dataStructure->getEntityStructure($parentInfo['entityId'])->getRelation($localPath);

        $this->relPaths[$relPath] = [
            'entityId' => $relation->getTargetEntityId(),
            'objectIdx' => count($this->relPaths),
            'parentPath' => $parentPath,
            'localPath' => $localPath,
        ];
    }

    protected function initRelPaths() {
        $this->relPaths['.'] = [
            'entityId' => $this->startEntityId,
            'objectIdx' => 0,
            'parentPath' => null,
            'localPath' => $this->startEntityId,
        ];
    }

    protected function addRelPath($relPath) {
        if (!isset($this->relPaths[$relPath])) {
            $this->calcRelInfo($relPath);
        }
    }

    /*protected function calcRelPaths() {
        $this->relPaths['.'] = [
            'entityId' => $this->startEntityId,
            'objectIdx' => 0,
            'parentPath' => null,
            'localPath' => $this->startEntityId,
        ];
        $extraRelations = $this->extraRelations;
        if (null !== $extraRelations) {
            foreach ($extraRelations as $relName) {
                if (array_key_exists($relName, $this->relPaths)) continue;
                $this->calcRelInfo($relName);
            }
        }
    }*/
}

class PdoResultHydrator implements IDataSetHydrator {
    use TBaseDataSetHydrator;

    /**
     * @var \PDOStatement
     */
    protected $pdoResult;

    protected $fetchedCnt;
    protected $totalCount;

    //protected $sourceKeyNames;
    protected $mapInstructions;

    public function __construct(IDataStructure $dataStructure, IEntityActivator $entityActivator, IFieldMapper $fieldMapper, \PDOStatement $pdoResult, $startEntityId, $extraRelations = null) {
        $this->dataStructure = $dataStructure;
        $this->entityActivator = $entityActivator;
        $this->startEntityId = $startEntityId;
        $this->fieldMapper = $fieldMapper;

        $this->pdoResult = $pdoResult;
        $this->fetchedCnt = 0;
        $this->totalCount = $pdoResult->rowCount();

        //$this->calcRelPaths();
        $this->initRelPaths();
        //$this->sourceKeyNames = [];
        $mapToRel = null;
        $mapToField = null;
        $this->mapInstructions = [[], [], [], []];
        if (null !== $extraRelations) {
            foreach ($extraRelations as $er) {
                $this->addRelPath($er);
            }
        }
        for ($c = 0; $c < $pdoResult->columnCount(); $c++) {
            $cm = $pdoResult->getColumnMeta($c);
            $this->fieldMapper->map($cm['name'], $mapToRel, $mapToField);
            if (!isset($this->relPaths[$mapToRel])) {
                $this->addRelPath($mapToRel);
            }

            $fieldType = $this->dataStructure->getEntityStructure($this->relPaths[$mapToRel]['entityId'])->getFieldType($mapToField);
            $conversion = ValueConversion::ConversionNone;
            if (Type::isBool($fieldType)) $conversion = ValueConversion::ConversionToBool;
            elseif (Type::isInt($fieldType)) $conversion = ValueConversion::ConversionToInt;
            elseif (Type::isFloat($fieldType)) $conversion = ValueConversion::ConversionToFloat;
            $this->mapInstructions[$conversion][] = [
                $c,
                $this->relPaths[$mapToRel]['objectIdx'],
                $mapToField,
            ];
            /*if (null === $extraRelations) {
                $er[$mapToRel] = true;
            }*/
            //$this->sourceKeyNames[] = $cm['name'];
        }
        /*if (null === $extraRelations) {
            $extraRelations = array_keys($er);
        }
        $this->extraRelations = $extraRelations;*/
    }

    public function getTotalCount() {
        return $this->totalCount;
    }

    public function fetch($count) {
        if ($this->fetchedCnt + $count > $this->totalCount) {
            $count = $this->totalCount - $this->fetchedCnt;
        }

        $toFetch = $count;
        $objects = $this->createObjects($toFetch);
        for ($objectNum = 0; $toFetch > 0; $toFetch--, $objectNum++) {
            $row = $this->pdoResult->fetch(\PDO::FETCH_NUM);

            foreach ($this->mapInstructions[ValueConversion::ConversionNone] as $mapField) {
                $field = $mapField[2];
                $objects[$mapField[1]][$objectNum]->$field = $row[$mapField[0]];
            }
            foreach ($this->mapInstructions[ValueConversion::ConversionToInt] as $mapField) {
                $field = $mapField[2];
                $objects[$mapField[1]][$objectNum]->$field = (int)$row[$mapField[0]];
            }
            foreach ($this->mapInstructions[ValueConversion::ConversionToFloat] as $mapField) {
                $field = $mapField[2];
                $objects[$mapField[1]][$objectNum]->$field = (float)$row[$mapField[0]];
            }
            foreach ($this->mapInstructions[ValueConversion::ConversionToBool] as $mapField) {
                $field = $mapField[2];
                $objects[$mapField[1]][$objectNum]->$field = (bool)$row[$mapField[0]];
            }
        }

        $this->fetchedCnt += $count;
        if ($this->fetchedCnt >= $this->totalCount) {
            $this->pdoResult->closeCursor();
        }

        return $objects[$this->relPaths['.']['objectIdx']];
    }
}


class DirectHydrateIterator implements \Iterator {
    /**
     * @var IDataSetHydrator
     */
    protected $hydrator;

    protected $current;
    protected $total;
    protected $pos;

    public function __construct(IDataSetHydrator $hydrator) {
        $this->hydrator = $hydrator;
    }

    public function current() {
        return $this->current;
    }

    public function next() {
        $this->current = $this->hydrator->fetch(1);
        $this->current = $this->current[0];
        $this->pos++;
    }

    public function key() {
        return $this->pos;
    }

    public function valid() {
        return $this->pos < $this->total;
    }

    public function rewind() {
        $this->total = $this->hydrator->getTotalCount();
        $this->pos = 0;
        if ($this->total > 0) {
            $this->current = $this->hydrator->fetch(1);
            $this->current = $this->current[0];
        } else {
            $this->current = null;
        }
    }
}

class SequentialHydrateIterator implements \Iterator {
    /**
     * @var IDataSetHydrator
     */
    protected $hydrator;

    protected $totalCount;
    protected $segments = [];
    protected $currentPos;
    protected $currentSegment;
    protected $currentSegmentPos;
    protected $currentSegmentLength;

    protected $segmentSize;

    public function __construct(IDataSetHydrator $hydrator, $segmentSize = 100) {
        $this->hydrator = $hydrator;
        $this->totalCount = $hydrator->getTotalCount();
        $this->segmentSize = $segmentSize;
    }

    /**
     * @param int $segmentSize
     * @return $this
     */
    public function setSegmentSize($segmentSize) {
        $this->segmentSize = $segmentSize;
        return $this;
    }

    public function current() {
        if ($this->currentPos >= $this->totalCount) {
            return null;
        }
        return $this->segments[$this->currentSegment][$this->currentSegmentPos];
    }

    public function next() {
        $this->currentPos++;
        $this->currentSegmentPos++;
        if ($this->currentSegmentPos >= $this->currentSegmentLength) {
            $this->currentSegmentPos = 0;
            $this->currentSegment++;
            //$this->ensureCurrentSegmentFetched();
            if (count($this->segments) <= $this->currentSegment && $this->currentPos < $this->totalCount) {
                $this->segments[] = $this->hydrator->fetch($this->segmentSize);
            }
            $this->currentSegmentLength = $this->currentSegment < count($this->segments) ? count($this->segments[$this->currentSegment]) : 0;
        }
    }

    public function key() {
        return $this->currentPos;
    }

    public function valid() {
        return $this->currentPos < $this->totalCount;
    }

    public function rewind() {
        $this->currentPos = 0;
        $this->currentSegment = 0;
        $this->currentSegmentPos = 0;
        if (count($this->segments) == 0 && $this->totalCount > 0) {
            $this->segments[] = $this->hydrator->fetch($this->segmentSize);
        }
        $this->currentSegmentLength = count($this->segments[$this->currentSegment]);
    }
}