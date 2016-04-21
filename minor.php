<?php

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
