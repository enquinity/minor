<?php

namespace minor;

interface IEntityActivator {
    public function createInstances($entityName, $count = 1);
}

interface IEntityRelation {
    public function getTargetEntityName();
    public function getRelationFieldName();
    public function getTargetFieldName();
    public function getType();
}

interface IEntityStructure {
    public function getFieldNames();
    public function getFieldType($fieldName);

    //public function getKeyFieldName();
    //public function hasComplexKey();

    /**
     * @param $relationName
     * @return IEntityRelation
     */
    public function getRelation($relationName);
}

interface IDataStructure {
    /**
     * @param $entityName
     * @return IEntityStructure
     */
    public function getEntityStructure($entityName);
}

class MapFieldsCalc {
    public static function getMapFieldsFromColumnList(array $columnNames) {
        $mapFields = [];
        foreach ($columnNames as $columnName) {
            $p = strrpos($columnName, '.');
            if (false === $p) {
                $mapFields[] = ['key' => $columnName, 'relation' => '.', 'fieldName' => $columnName];
            } else {
                $relation = substr($columnName, 0, $p);
                $field = substr($columnName, $p + 1);
                $mapFields[] = ['key' => $columnName, 'relation' => $relation, 'fieldName' => $field];
            }
        }
        return $mapFields;
    }
}


class BaseHydrator {

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
    protected $mapFields = [];

    protected $mapInstructions = [];

    protected $startEntityName;
    protected $extraRelations = null;

    protected $relPaths = null;

    const ConversionNone = 0;
    const ConversionToInt = 1;
    const ConversionToFloat = 2;
    const ConversionToBool = 3;

    protected function calcMapInstructions() {
        foreach ($this->mapFields as $mapField) {
            $this->mapInstructions[self::ConversionNone][] = [
                $mapField['key'],
                $this->relPaths[$mapField['relation']]['objectIdx'],
                $mapField['fieldName'],
            ];
            // TODO: zbadać typ pola i do odpowiedniego indeksu wpisać
        }
    }

    protected function createObjects($count) {
        $objects = [];
        foreach ($this->relPaths as $relPath => $info) {
            $objects[$info['objectIdx']] = $this->entityActivator->createInstances($info['entityName'], $count);
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
        $relation = $this->dataStructure->getEntityStructure($parentInfo['entityName'])->getRelation($localPath);

        $this->relPaths[$relPath] = [
            'entityName' => $relation->getTargetEntityName(),
            'objectIdx' => count($this->relPaths),
            'parentPath' => $parentPath,
            'localPath' => $localPath,
        ];
    }

    protected function calcRelPaths() {
        $this->relPaths['.'] = [
            'entityName' => $this->startEntityName,
            'objectIdx' => 0,
            'parentPath' => null,
            'localPath' => $this->startEntityName,
        ];
        $extraRelations = $this->extraRelations;
        if (null === $extraRelations) {
            $er = [];
            foreach ($this->mapFields as $mapField) {
                if ($mapField['relation'] != '.') {
                    $er[$mapField['relation']] = true;
                }
            }
            if (!empty($er)) {
                $extraRelations = array_keys($er);
            }
        }
        if (null !== $extraRelations) {
            foreach ($extraRelations as $relName) {
                if (array_key_exists($relName, $this->relPaths)) continue;
                $this->calcRelInfo($relName);
            }
        }
    }
}

class HydrateIterator extends BaseHydrator implements \Iterator {
    protected $segments = [];
    protected $currentPos;
    protected $currentSegment;
    protected $currentSegmentPos;
    protected $currentSegmentLength;

    protected $segmentSize = 100;

    /**
     * @var \Iterator
     */
    protected $sourceData;
    protected $sourceDataCount;

    public function __construct(IDataStructure $dataStructure, IEntityActivator $entityActivator, \Iterator $sourceData, $sourceDataCount, array $mapFields, $startEntityName, $extraRelations = null) {
        $this->dataStructure = $dataStructure;
        $this->entityActivator = $entityActivator;
        $this->sourceData = $sourceData;
        $this->sourceDataCount = $sourceDataCount;
        $this->mapFields = $mapFields;
        $this->startEntityName = $startEntityName;
        $this->extraRelations = $extraRelations;

        $this->calcRelPaths();
        $this->calcMapInstructions();
    }

    /**
     * @param int $segmentSize
     * @return HydrateIterator
     */
    public function setSegmentSize($segmentSize) {
        $this->segmentSize = $segmentSize;
        return $this;
    }

    public function current() {
        if ($this->currentPos >= $this->sourceDataCount) {
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
            if (count($this->segments) <= $this->currentSegment) {
                $this->fetchNextSegment();
            }
            $this->currentSegmentLength = $this->currentSegment < count($this->segments) ? count($this->segments[$this->currentSegment]) : 0;
        }
    }

    public function key() {
        return $this->currentPos;
    }

    public function valid() {
        return $this->currentPos < $this->sourceDataCount;
    }

    public function rewind() {
        $this->sourceData->rewind();
        $this->currentPos = 0;
        $this->currentSegment = 0;
        $this->currentSegmentPos = 0;
        if (count($this->segments) == 0) {
            $this->fetchNextSegment();
        }
        //$this->ensureCurrentSegmentFetched();
        $this->currentSegmentLength = count($this->segments[$this->currentSegment]);
    }

    /*protected function ensureCurrentSegmentFetched() {
        if (count($this->segments) <= $this->currentSegment) {
            $this->fetchNextSegment();
        }
    }*/

    protected function fetchNextSegment() {
        $toFetch = $this->segmentSize;
        $remaining = $this->sourceDataCount - $this->currentPos;

        if (0 == $remaining) return;
        if ($toFetch > $remaining) $toFetch = $remaining;

        $objects = $this->createObjects($toFetch);
        for ($objectNum = 0; $toFetch > 0 && $this->sourceData->valid(); $toFetch--, $objectNum++, $this->sourceData->next()) {
            $row = $this->sourceData->current();

            foreach ($this->mapInstructions[self::ConversionNone] as $mapField) {
                $field = $mapField[2];
                $objects[$mapField[1]][$objectNum]->$field = $row[$mapField[0]];
            }
            // TODO: pozostałe konwersje
        }
        $this->segments[] = $objects[$this->relPaths['.']['objectIdx']];
        // TODO: zrobić to w bardziej wydajny sposób
        /*foreach ($objects[$this->relPaths['.']['objectIdx']] as $o) {
            $this->results[] = $o;
        }*/
        //$this->results = array_merge($this->results, $objects[$this->relPaths['.']['objectIdx']]);
    }
}

class ObjectHydrator extends BaseHydrator {

    public function __construct(IDataStructure $dataStructure, IEntityActivator $entityActivator, array $mapFields, $startEntityName, $extraRelations = null) {
        $this->dataStructure = $dataStructure;
        $this->entityActivator = $entityActivator;
        $this->mapFields = $mapFields;
        $this->startEntityName = $startEntityName;
        $this->extraRelations = $extraRelations;

        $this->calcRelPaths();
        $this->calcMapInstructions();
    }

    public function hydrate(array $row) {
        $objects = $this->createObjects(1);
        foreach ($this->mapInstructions[self::ConversionNone] as $mapField) {
            $field = $mapField[2];
            $objects[$mapField[1]][0]->$field = $row[$mapField[0]];
        }
        // TODO: pozostałe konwersje
        return $objects[$this->relPaths['.']['objectIdx']][0];
    }
}
