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

class DataToObjectMapper {
    protected $cachedResults = [];

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

    protected $startEntityName;
    protected $extraRelations = [];

    protected $relPaths = null;

    protected $segmentSize = 100;

    protected function fetchNextSegment() {
        $toFetch = $this->segmentSize;
        // TODO: trzeba sprawdzić, ile rekordów pozostało w źródle danych
        $objects = $this->createObjects($toFetch);
        for ($toFetch = $this->segmentSize; $toFetch > 0 && $this->sourceData->valid(); $toFetch--, $this->sourceData->next()) {
            $row = $this->sourceData->current();

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

    protected function initRelPaths() {
        $this->relPaths['.'] = [
            'entityName' => $this->startEntityName,
            'objectIdx' => 0,
            'parentPath' => null,
            'localPath' => $this->startEntityName,
        ];
        foreach ($this->extraRelations as $relName) {
            if (array_key_exists($relName, $this->relPaths)) continue;
            $this->calcRelInfo($relName);
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

