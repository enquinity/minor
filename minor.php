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
    public function createInstances($entityName, $count = 1);
}

interface IEntityRelation {
    const OneToOneType = 'oo';
    const OneToManyType = 'om';
    const ManyToOneType = 'mo';

    public function getTargetEntityName();
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
     * @param $entityName
     * @return IEntityStructure
     */
    public function getEntityStructure($entityName);
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

    protected $startEntityName;
    protected $extraRelations = null;

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
        /*if (null === $extraRelations) {
            $er = [];
            foreach ($this->mapFields as $mapField) {
                if ($mapField['relation'] != '.') {
                    $er[$mapField['relation']] = true;
                }
            }
            if (!empty($er)) {
                $extraRelations = array_keys($er);
            }
        }*/
        if (null !== $extraRelations) {
            foreach ($extraRelations as $relName) {
                if (array_key_exists($relName, $this->relPaths)) continue;
                $this->calcRelInfo($relName);
            }
        }
    }
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

    public function __construct(IDataStructure $dataStructure, IEntityActivator $entityActivator, IFieldMapper $fieldMapper, \PDOStatement $pdoResult, $startEntityName, $extraRelations = null) {
        $this->dataStructure = $dataStructure;
        $this->entityActivator = $entityActivator;
        $this->startEntityName = $startEntityName;
        $this->fieldMapper = $fieldMapper;

        $this->pdoResult = $pdoResult;
        $this->fetchedCnt = 0;
        $this->totalCount = $pdoResult->rowCount();

        $this->calcRelPaths();
        //$this->sourceKeyNames = [];
        $mapToRel = null;
        $mapToField = null;
        $this->mapInstructions = [];
        if (null === $extraRelations) {
            $er = [];
        }
        for ($c = 0; $c < $pdoResult->columnCount(); $c++) {
            $cm = $pdoResult->getColumnMeta($c);
            $this->fieldMapper->map($cm['name'], $mapToRel, $mapToField);
            $conv = ValueConversion::ConversionNone; // TODO: w zależności od typu pola
            $this->mapInstructions[$conv][] = [
                $c,
                $this->relPaths[$mapToRel]['objectIdx'],
                $mapToField,
            ];
            if (null === $extraRelations) {
                $er[$mapToRel] = true;
            }
            //$this->sourceKeyNames[] = $cm['name'];
        }
        if (null === $extraRelations) {
            $extraRelations = array_keys($er);
        }
        $this->extraRelations = $extraRelations;
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
            // TODO: pozostałe konwersje
        }

        $this->fetchedCnt += $count;
        if ($this->fetchedCnt >= $this->totalCount) {
            $this->pdoResult->closeCursor();
        }

        return $objects[$this->relPaths['.']['objectIdx']];
    }
}
