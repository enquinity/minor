<?php
namespace minnotations;

interface IObjectAnnotations {
    public function hasAnnotation($annotationName);
    public function getValue($annotationName);
    public function getValues($annotationName);
    public function getAllAsArray();
}

interface IMinnotations {
    /**
     * @param $className
     * @return IObjectAnnotations
     */
    public function forClass($className);

    /**
     * @param $className
     * @return IObjectAnnotations
     */
    public function forMethod($className, $methodName);

    /**
     * @param $className
     * @return IObjectAnnotations
     */
    public function forProperty($className, $propertyName);
}


class StdObjectAnnotations implements IObjectAnnotations {
    protected $docComment;
    protected $annotations;

    public function __construct($docComment) {
        $this->docComment = $docComment;
    }

    public function getValue($annotationName) {
        if (null === $this->annotations) $this->annotations = self::fetchAnnotations($this->docComment);
        if (!isset($this->annotations[$annotationName])) return null;
        if (is_array($this->annotations[$annotationName])) return reset($this->annotations[$annotationName]);
        return $this->annotations[$annotationName];
    }

    public function getValues($annotationName) {
        if (null === $this->annotations) $this->annotations = self::fetchAnnotations($this->docComment);
        if (!isset($this->annotations[$annotationName])) return [];
        return $this->annotations[$annotationName];
    }

    public function getAllAsArray() {
        if (null === $this->annotations) $this->annotations = self::fetchAnnotations($this->docComment);
        return $this->annotations;
    }

    public static function fetchAnnotations($docComment) {
        if (empty($docComment)) return [];
        $annotations = [];
        for ($pos = 0; true;) {
            $p = strpos($docComment, '@', $pos);
            if (false === $p) break;
            $p2 = strpos($docComment, "\n", $p);
            $annotation = false === $p2 ? substr($docComment, $p + 1) : substr($docComment, $p + 1, $p2 - $p - 1);

            $aName = trim($annotation);
            $aValue = true;

            $ps = strpos($annotation, ' ');
            if (false !== $ps) {
                $aName = substr($annotation, 0, $ps);
                $aValue = trim(substr($annotation, $ps + 1));
                if (empty($aValue)) $aValue = true;
            }
            if (!isset($annotations[$aName])) {
                $annotations[$aName] = $aValue;
            } else {
                if (!is_array($annotations[$aName])) $annotations[$aName] = [$annotations[$aName]];
                $annotations[$aName][] = $aValue;
            }
            if (false === $p2) break;
            $pos = $p2 + 1;
        }
        return $annotations;
    }

    public function hasAnnotation($annotationName) {
        if (null === $this->annotations) $this->annotations = self::fetchAnnotations($this->docComment);
        return isset($this->annotations[$annotationName]);
    }
}

class SimpleMinnotations implements IMinnotations {

    protected $reflectionClassesByName = [];
    protected $cache = [];

    /**
     * @return \ReflectionClass
     */
    protected function getReflectionClass($className) {
        if (!isset($this->reflectionClassesByName[$className])) {
            $this->reflectionClassesByName[$className] = new \ReflectionClass($className);
        }
        return $this->reflectionClassesByName[$className];
    }

    public function forClass($className) {
        $cacheKey = 'cls:' . $className;
        if (!isset($this->cache[$cacheKey])) {
            $rc = $this->getReflectionClass($className);
            $this->cache[$cacheKey] = new StdObjectAnnotations($rc->getDocComment());
        }
        return $this->cache[$cacheKey];
    }

    public function forMethod($className, $methodName) {
        $cacheKey = 'mth:' . $className . ';' . $methodName;
        if (!isset($this->cache[$cacheKey])) {
            $rc = $this->getReflectionClass($className)->getMethod($methodName);
            $this->cache[$cacheKey] = new StdObjectAnnotations($rc->getDocComment());
        }
        return $this->cache[$cacheKey];
    }

    public function forProperty($className, $propertyName) {
        $cacheKey = 'prop:' . $className . ';' . $propertyName;
        if (!isset($this->cache[$cacheKey])) {
            $rc = $this->getReflectionClass($className)->getProperty($propertyName);
            $this->cache[$cacheKey] = new StdObjectAnnotations($rc->getDocComment());
        }
        return $this->cache[$cacheKey];
    }
}