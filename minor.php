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

// todo: klasa bazowa plus 2 klasy pochodne - iterator i klasa do mapowania 1-dnego wiersza
class DataToObjectsMapper implements \Iterator {
    protected $segments = [];
    protected $currentPos;
    protected $currentSegment;
    protected $currentSegmentPos;

    /**
     * @var IEntityActivator
     */
    protected $entityActivator;

    /**
     * @var IDataStructure
     */
    protected $dataStructure;

    /**
     * @var \Iterator
     */
    protected $sourceData;
    protected $sourceDataCount;

    /**
     * @var array
     * [['key' => source data key, 'relation' => relation name (.) for main entity, 'fieldName' => name object field source data key is to be mapped to]]
     */
    protected $mapFields = [];

    protected $mapInstructions = [];

    protected $startEntityName;
    protected $extraRelations = null;

    protected $relPaths = null;

    protected $segmentSize = 100;

    const ConversionNone = 0;
    const ConversionToInt = 1;
    const ConversionToFloat = 2;
    const ConversionToBool = 3;

    /**
     * DataToObjectsMapper constructor.
     * @param IDataStructure $dataStructure
     * @param \Iterator      $sourceData
     * @param                $sourceDataCount
     * @param array          $mapFields
     * @param                $startEntityName
     * @param null           $extraRelations
     */
    public function __construct(IDataStructure $dataStructure, \Iterator $sourceData, $sourceDataCount, array $mapFields, $startEntityName, $extraRelations = null) {
        $this->dataStructure = $dataStructure;
        $this->sourceData = $sourceData;
        $this->sourceDataCount = $sourceDataCount;
        $this->mapFields = $mapFields;
        $this->startEntityName = $startEntityName;
        $this->extraRelations = $extraRelations;
    }

    public function current() {
        if (!$this->valid()) {
            return null;
        }
        return $this->segments[$this->currentSegment][$this->currentSegmentPos];
    }

    public function next() {
        $this->currentPos++;
        $this->currentSegmentPos++;
        if ($this->currentSegmentPos >= count($this->segments[$this->currentSegment])) {
            $this->currentSegmentPos = 0;
            $this->currentSegment++;
            $this->ensureCurrentSegmentFetched();
        }
    }

    public function key() {
        return $this->currentPos;
    }

    public function valid() {
        return $this->currentPos < $this->sourceDataCount;
    }

    public function rewind() {
        $this->currentPos = 0;
        $this->currentSegment = 0;
        $this->currentSegmentPos = 0;
        $this->ensureCurrentSegmentFetched();
    }

    protected function ensureCurrentSegmentFetched() {
        if (count($this->segments) <= $this->currentSegment) {
            $this->fetchNextSegment();
        }
    }

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

    protected function fetchNextSegment() {
        $toFetch = $this->segmentSize;
        $remaining = $this->sourceDataCount - $this->currentPos;
        if (0 == $remaining) return;
        if ($toFetch > $remaining) $toFetch = $remaining;

        $objects = $this->createObjects($toFetch);
        for ($toFetch = $this->segmentSize; $toFetch > 0 && $this->sourceData->valid(); $toFetch--, $this->sourceData->next()) {
            $row = $this->sourceData->current();
            $objectNum = 0; // TODO: uzupełnić

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

    protected function initRelPaths() {
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

class Hydrator {

    /**
     * @var array
     * Keys:
     * 0: fields without conversion
     * 1: fields with toInt conversion
     * 2: fields with toFloat conversion
     * 3: fields with toBool conversion
     *
     * Every field has keys:
     * 0: source field key/index
     * 1: destination object number
     * 2: destination field name
     */
    protected $hydrateFields = [];

    const ConversionNone = 0;
    const ConversionToInt = 1;
    const ConversionToFloat = 2;
    const ConversionToBool = 3;

    public function addHydrateField($sourceKey, $dstObjectNumber, $dstField, $conversion = self::ConversionNone) {
        $this->hydrateFields[$conversion][] = [$sourceKey, $dstObjectNumber, $dstField];
    }

    public function hydrate(array $data, array $targets) {
        $tKey = -1;
        foreach ($data as $row) {
            $tKey++;
            foreach ($this->hydrateFields[0] as $hf) {
                $fld = $hf[2];
                $targets[$tKey][$hf[1]]->$fld = $row[$hf[0]];
            }
            foreach ($this->hydrateFields[1] as $hf) {
                $fld = $hf[2];
                $targets[$tKey][$hf[1]]->$fld = (int)$row[$hf[0]];
            }
            foreach ($this->hydrateFields[2] as $hf) {
                $fld = $hf[2];
                $targets[$tKey][$hf[1]]->$fld = (float)$row[$hf[0]];
            }
            foreach ($this->hydrateFields[3] as $hf) {
                $fld = $hf[2];
                $targets[$tKey][$hf[1]]->$fld = $row[$hf[0]] ? true : false;
            }
        }
    }
}

